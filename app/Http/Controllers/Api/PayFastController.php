<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Services\PayFastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PayFast (South Africa) Payment Gateway Controller.
 *
 * Endpoints:
 *   GET  /api/client/bookings/{id}/payment-data       Build PayFast form fields + signature
 *   GET  /api/client/bookings/{id}/payment-status     Read-only status (success page polls this)
 *   POST /api/client/bookings/{id}/payment-cancelled  Client returned via cancel_url
 *   POST /api/admin/bookings/{id}/mark-paid           Admin-only manual "mark paid" (demo)
 *   POST /api/payfast/itn                             PayFast ITN (server-to-server)
 *
 * A payment is ONLY marked completed by a fully validated ITN (or an admin).
 */
class PayFastController extends Controller
{
    public function __construct(
        protected PayFastService $payFastService
    ) {}

    /**
     * Generate PayFast checkout form data for a confirmed, unpaid booking.
     * Reuses the booking's single pending payment record; every call issues
     * a fresh, unique m_payment_id for the new attempt.
     */
    public function paymentData(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $booking = Booking::where('id', $id)->where('client_id', $user->id)->first();
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if ($booking->status !== 'confirmed') {
            return response()->json([
                'message' => 'Only confirmed bookings can be paid. Current status: ' . $booking->status,
            ], 422);
        }

        if ($booking->payment_status !== 'unpaid'
            || Payment::where('booking_id', $booking->id)->where('status', 'completed')->exists()) {
            return response()->json(['message' => 'This booking has already been paid.'], 422);
        }

        if (Payment::where('booking_id', $booking->id)->where('status', 'processing')->exists()) {
            return response()->json([
                'message' => 'A payment for this booking is already being processed by PayFast. Please wait for confirmation.',
            ], 422);
        }

        $pkrAmount = round((float) $booking->total_amount, 2);
        $zarAmount = $this->payFastService->toGatewayAmount($pkrAmount);
        $minAmount = (float) config('payfast.min_amount', 5.00);

        if ($zarAmount < $minAmount) {
            return response()->json([
                'message' => 'Amount too small: PayFast requires at least R' . number_format($minAmount, 2)
                    . ' (this booking converts to R' . number_format($zarAmount, 2) . ').',
            ], 422);
        }

        $payment = DB::transaction(function () use ($booking, $user, $pkrAmount, $zarAmount) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            $payment = Payment::where('booking_id', $booking->id)
                ->where('status', 'pending')
                ->latest('id')
                ->first() ?? new Payment(['booking_id' => $booking->id, 'status' => 'pending']);

            $payment->fill([
                'client_id'        => $user->id,
                'amount'           => $pkrAmount,
                'currency'         => config('payfast.app_currency', 'PKR'),
                'gateway_amount'   => $zarAmount,
                'gateway_currency' => config('payfast.gateway_currency', 'ZAR'),
                'exchange_rate'    => $this->payFastService->rate(),
                'payment_method'   => 'payd_fast',
            ]);
            $payment->save();

            // Unique per attempt: EG-{booking}-{payment}-{random}
            $payment->payment_reference = 'EG-' . $booking->id . '-' . $payment->id . '-' . Str::upper(Str::random(8));
            $payment->save();

            return $payment;
        });

        $paymentData = $this->payFastService->buildPaymentData($booking, $user, $payment);

        Log::info('PayFast: payment data generated', [
            'booking_id'   => $booking->id,
            'payment_id'   => $payment->id,
            'm_payment_id' => $payment->payment_reference,
            'amount_pkr'   => $pkrAmount,
            'amount_zar'   => $zarAmount,
        ]);

        return response()->json([
            'message' => 'Payment data generated successfully.',
            'data'    => $paymentData + [
                'amount' => [
                    'app_amount'       => $pkrAmount,
                    'app_currency'     => $payment->currency,
                    'gateway_amount'   => $zarAmount,
                    'gateway_currency' => $payment->gateway_currency,
                    'exchange_rate'    => $payment->exchange_rate,
                ],
            ],
        ]);
    }

    /**
     * Read-only payment status for the client's booking. Never changes state.
     */
    public function paymentStatus(Request $request, int $id): JsonResponse
    {
        $booking = Booking::where('id', $id)->where('client_id', $request->user()->id)->first();
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $payment = Payment::where('booking_id', $booking->id)->where('status', 'completed')->latest('id')->first();
        if (!$payment && $request->filled('m_payment_id')) {
            $payment = Payment::where('booking_id', $booking->id)
                ->where('payment_reference', (string) $request->query('m_payment_id'))->first();
        }
        $payment ??= Payment::where('booking_id', $booking->id)->latest('id')->first();

        if ($booking->payment_status === 'paid') {
            $state = 'paid';
        } elseif ($booking->payment_status === 'refunded') {
            $state = 'refunded';
        } elseif (!$payment) {
            $state = 'unpaid';
        } else {
            $state = match ($payment->status) {
                'cancelled' => 'cancelled',
                'failed'    => 'failed',
                'refunded'  => 'refunded',
                default     => 'processing', // pending / processing: waiting for ITN
            };
        }

        return response()->json([
            'data' => [
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'booking_status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'paid_at'        => $booking->paid_at,
                'state'          => $state,
                'payment'        => $payment ? [
                    'id'               => $payment->id,
                    'status'           => $payment->status,
                    'reference'        => $payment->payment_reference,
                    'transaction_id'   => $payment->transaction_id,
                    'amount'           => $payment->amount,
                    'currency'         => $payment->currency,
                    'gateway_amount'   => $payment->gateway_amount,
                    'gateway_currency' => $payment->gateway_currency,
                    'exchange_rate'    => $payment->exchange_rate,
                    'paid_at'          => $payment->paid_at,
                ] : null,
            ],
        ]);
    }

    /**
     * Client returned from PayFast via cancel_url: flip THEIR OWN pending
     * payment to cancelled. Never touches completed/processing payments.
     */
    public function paymentCancelled(Request $request, int $id): JsonResponse
    {
        $booking = Booking::where('id', $id)->where('client_id', $request->user()->id)->first();
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $query = Payment::where('booking_id', $booking->id)
            ->where('client_id', $request->user()->id)
            ->where('status', 'pending');

        if ($request->filled('m_payment_id')) {
            $query->where('payment_reference', (string) $request->input('m_payment_id'));
        }

        $payment = $query->latest('id')->first();
        if (!$payment) {
            return response()->json([
                'message' => 'No pending payment to cancel.',
                'data'    => ['booking_id' => $booking->id, 'payment_status' => $booking->payment_status],
            ]);
        }

        $payment->update([
            'status' => 'cancelled',
            'notes'  => 'Cancelled by client on PayFast checkout.',
        ]);

        return response()->json([
            'message' => 'Payment cancelled.',
            'data'    => [
                'booking_id'     => $booking->id,
                'payment_id'     => $payment->id,
                'status'         => $payment->status,
                'payment_status' => $booking->payment_status,
            ],
        ]);
    }

    /**
     * PayFast ITN. Implements PayFast's documented checks:
     *   (a) signature over POSTed fields in received order (+ passphrase if set)
     *   (b) source IP belongs to PayFast                 [PAYFAST_VERIFY_SOURCE]
     *   (c) amount_gross equals expected ZAR amount (±0.01)
     *   (d) server confirmation via /eng/query/validate  [PAYFAST_VERIFY_WITH_SERVER]
     *   (e) merchant_id matches; idempotent for completed payments
     */
    public function itn(Request $request): JsonResponse
    {
        // Form body only, in the order PayFast sent it (not merged with the query string)
        $post = $request->request->all();
        $ip = $request->ip();

        Log::info('PayFast ITN received', [
            'ip'             => $ip,
            'm_payment_id'   => $post['m_payment_id'] ?? null,
            'pf_payment_id'  => $post['pf_payment_id'] ?? null,
            'payment_status' => $post['payment_status'] ?? null,
            'amount_gross'   => $post['amount_gross'] ?? null,
        ]);

        $reject = function (string $reason, int $code = 400) use ($post, $ip) {
            Log::warning('PayFast ITN rejected: ' . $reason, [
                'ip' => $ip, 'm_payment_id' => $post['m_payment_id'] ?? null,
            ]);
            return response()->json(['message' => 'ITN rejected: ' . $reason], $code);
        };

        // (a) signature
        if (!$this->payFastService->validItnSignature($post)) {
            return $reject('invalid signature');
        }

        // (b) source IP
        if (!$this->payFastService->validSourceIp($ip)) {
            return $reject('invalid source IP', 403);
        }

        // (e) merchant
        if ((string) ($post['merchant_id'] ?? '') !== (string) config('payfast.merchant_id')) {
            return $reject('merchant mismatch');
        }

        $payment = $this->findPaymentForItn($post);
        if (!$payment) {
            return $reject('payment not found', 404);
        }

        // (e) idempotent: already completed → acknowledge, change nothing
        if ($payment->status === 'completed') {
            return response()->json(['message' => 'ITN already processed.']);
        }

        // (c) amount
        if (!$this->payFastService->validAmount((float) $payment->gateway_amount, $post['amount_gross'] ?? null)) {
            return $reject('amount mismatch');
        }

        // (d) server-to-server confirmation
        if (!$this->payFastService->validWithServer($post)) {
            return $reject('server validation failed');
        }

        $pfStatus = strtoupper((string) ($post['payment_status'] ?? ''));
        $justCompleted = false;

        DB::transaction(function () use ($payment, $post, $pfStatus, &$justCompleted) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === 'completed') {
                return; // concurrent duplicate
            }

            if ($pfStatus === 'COMPLETE') {
                $locked->update([
                    'status'           => 'completed',
                    'transaction_id'   => $post['pf_payment_id'] ?? null,
                    'gateway_response' => $post,
                    'paid_at'          => now(),
                    'notes'            => 'Payment completed via PayFast ' . (config('payfast.sandbox') ? 'sandbox' : 'live') . ' ITN.',
                ]);

                Booking::whereKey($locked->booking_id)->update([
                    'payment_status' => 'paid',
                    'paid_at'        => now(),
                ]);

                // Any other open attempts for this booking are now moot
                Payment::where('booking_id', $locked->booking_id)
                    ->where('id', '!=', $locked->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->update(['status' => 'cancelled', 'notes' => 'Superseded by completed payment #' . $locked->id]);

                $justCompleted = true;
            } else {
                $newStatus = match ($pfStatus) {
                    'CANCELLED' => 'cancelled',
                    'PENDING'   => 'processing',
                    default     => 'failed',
                };
                $locked->update([
                    'status'           => $newStatus,
                    'gateway_response' => $post,
                    'notes'            => 'PayFast status: ' . ($pfStatus ?: 'UNKNOWN'),
                ]);
            }
        });

        if ($justCompleted) {
            $this->notifyPaid($payment->fresh());
        }

        Log::info('PayFast ITN processed', ['payment_id' => $payment->id, 'pf_status' => $pfStatus]);

        return response()->json(['message' => 'ITN processed.']);
    }

    /**
     * Admin-only manual "mark paid" for demos (e.g. when an ITN cannot reach
     * the server). Clients can never call this.
     */
    public function adminMarkPaid(Request $request, int $id): JsonResponse
    {
        $booking = Booking::find($id);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }
        if ($booking->status !== 'confirmed') {
            return response()->json(['message' => 'Only confirmed bookings can be marked paid.'], 422);
        }
        if ($booking->payment_status === 'paid') {
            return response()->json(['message' => 'This booking is already paid.'], 422);
        }

        $payment = DB::transaction(function () use ($booking, $request) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            $payment = Payment::where('booking_id', $booking->id)
                ->whereIn('status', ['pending', 'processing'])
                ->latest('id')->first()
                ?? new Payment(['booking_id' => $booking->id, 'client_id' => $booking->client_id]);

            $payment->fill([
                'amount'         => round((float) $booking->total_amount, 2),
                'currency'       => config('payfast.app_currency', 'PKR'),
                'payment_method' => 'other',
                'status'         => 'completed',
                'transaction_id' => 'MANUAL-' . $booking->id . '-' . Str::upper(Str::random(8)),
                'paid_at'        => now(),
                'notes'          => 'Manually marked paid by admin #' . $request->user()->id . ' (demo, no gateway charge).',
            ]);
            $payment->payment_reference ??= 'MANUAL-' . $booking->id;
            $payment->save();

            $booking->update(['payment_status' => 'paid', 'paid_at' => now()]);

            return $payment;
        });

        $this->notifyPaid($payment->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Booking marked as paid.',
            'data'    => $booking->fresh(['payment']),
        ]);
    }

    private function findPaymentForItn(array $post): ?Payment
    {
        $mPaymentId = (string) ($post['m_payment_id'] ?? '');
        if ($mPaymentId === '') {
            return null;
        }

        $payment = Payment::where('payment_reference', $mPaymentId)->first();
        if ($payment) {
            return $payment;
        }

        // An earlier attempt's reference for the same (reused) payment record
        $bookingId = (int) ($post['custom_int1'] ?? 0);
        $paymentId = (int) ($post['custom_int2'] ?? 0);
        if ($bookingId && $paymentId && str_starts_with($mPaymentId, "EG-{$bookingId}-{$paymentId}-")) {
            return Payment::where('id', $paymentId)->where('booking_id', $bookingId)->first();
        }

        return null;
    }

    private function notifyPaid(Payment $payment): void
    {
        try {
            $booking = Booking::with(['venue:id,name', 'store:id,name,user_id', 'client:id,name'])->find($payment->booking_id);
            if (!$booking) {
                return;
            }

            $venueName = $booking->venue?->name ?? 'venue';
            $amount = 'PKR ' . number_format((float) $payment->amount, 0);
            if ($payment->gateway_amount) {
                $amount .= ' (R' . number_format((float) $payment->gateway_amount, 2) . ')';
            }
            $data = [
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'payment_id'     => $payment->id,
                'amount'         => $payment->amount,
                'venue_name'     => $venueName,
            ];

            NotificationService::notifyClient(
                $booking->client_id, 'payment_completed', 'Payment Received',
                "Your payment of {$amount} for booking #{$booking->booking_number} (\"{$venueName}\") was received. Your booking is now paid.",
                $data
            );

            if ($booking->store?->user_id) {
                NotificationService::notifyStoreOwner(
                    $booking->store->user_id, 'payment_completed', 'Booking Paid',
                    ($booking->client?->name ?? 'A client') . " paid {$amount} for booking #{$booking->booking_number} (\"{$venueName}\").",
                    $data
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
