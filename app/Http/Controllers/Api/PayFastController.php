<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PayFastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PayFast Payment Gateway Controller
 *
 * ╔══════════════════════════════════════════════════╗
 * ║  SANDBOX MODE — Final Year Project Demo Only    ║
 * ║  Do NOT use in production without proper setup  ║
 * ╚══════════════════════════════════════════════════╝
 *
 * Endpoints:
 *   GET  /api/client/bookings/{id}/payment-data  — Generate PayFast form data + signature
 *   POST /api/payfast/itn                        — Receive ITN (Instant Transaction Notification)
 */
class PayFastController extends Controller
{
    public function __construct(
        protected PayFastService $payFastService
    ) {}

    /**
     * Generate PayFast payment form data for a booking.
     *
     * The frontend uses this data to render a hidden HTML form
     * and POST it directly to PayFast's sandbox URL.
     *
     * Route: GET /api/client/bookings/{id}/payment-data
     * Auth:  JWT + role:client
     */
    public function paymentData(Request $request, int $id): JsonResponse
    {
        // ── 1. Get the authenticated client ──────────────────────────────
        $user = $request->user();

        // ── 2. Find the booking (must belong to this client) ─────────────
        $booking = Booking::where('id', $id)
            ->where('client_id', $user->id)
            ->first();

        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        // ── 3. Only allow payment for confirmed bookings ─────────────────
        if ($booking->status !== 'confirmed') {
            return response()->json([
                'message' => 'Only confirmed bookings can be paid. Current status: ' . $booking->status,
            ], 422);
        }

        // ── 4. Check if already paid ─────────────────────────────────────
        $existingPayment = Payment::where('booking_id', $booking->id)
            ->where('status', 'completed')
            ->first();

        if ($existingPayment) {
            return response()->json([
                'message' => 'This booking has already been paid.',
            ], 422);
        }

        // ── 5. Build the PayFast form data with signature ────────────────
        $paymentData = $this->payFastService->buildPaymentData($booking, $user);

        // ── 6. Create or update a pending payment record ─────────────────
        Payment::updateOrCreate(
            [
                'booking_id' => $booking->id,
                'status'     => 'pending',
            ],
            [
                'client_id'         => $user->id,
                'amount'            => $booking->total_amount,
                'currency'          => 'ZAR',
                'payment_method'    => 'payd_fast',
                'payment_reference' => $paymentData['fields']['m_payment_id'],
            ]
        );

        // ── 7. Log for debugging (sandbox) ───────────────────────────────
        Log::channel('single')->info('PayFast: Payment data generated', [
            'booking_id'   => $booking->id,
            'm_payment_id' => $paymentData['fields']['m_payment_id'],
            'amount'       => $paymentData['fields']['amount'],
        ]);

        return response()->json([
            'message' => 'Payment data generated successfully.',
            'data'    => $paymentData,
        ]);
    }

    /**
     * Handle PayFast ITN (Instant Transaction Notification).
     *
     * PayFast POSTs to this endpoint server-to-server after a payment
     * is completed, failed, or cancelled.
     *
     * Route: POST /api/payfast/itn
     * Auth:  NONE (public — PayFast sends this, not the browser)
     *
     * NOTE: In sandbox mode, PayFast cannot reach localhost.
     *       Use ngrok or a public URL for testing ITN in sandbox.
     *       The success page also handles marking payments as complete for demo.
     */
    public function itn(Request $request): JsonResponse
    {
        // ── 1. Log the raw ITN data ──────────────────────────────────────
        Log::channel('single')->info('━━━ PayFast ITN Received ━━━', [
            'ip'   => $request->ip(),
            'data' => $request->all(),
        ]);

        $itnData = $request->all();

        // ── 2. Validate the signature ────────────────────────────────────
        if (!$this->payFastService->validateItnSignature($itnData)) {
            Log::channel('single')->warning('PayFast ITN: INVALID SIGNATURE', $itnData);
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        Log::channel('single')->info('PayFast ITN: Signature VALID');

        // ── 3. Find the payment record by m_payment_id ───────────────────
        $mPaymentId = $itnData['m_payment_id'] ?? null;
        $payment = Payment::where('payment_reference', $mPaymentId)->first();

        if (!$payment) {
            Log::channel('single')->warning('PayFast ITN: Payment record not found', [
                'm_payment_id' => $mPaymentId,
            ]);
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        // ── 4. Update payment status based on PayFast response ───────────
        $pfPaymentStatus = $itnData['payment_status'] ?? 'UNKNOWN';

        if ($pfPaymentStatus === 'COMPLETE') {
            // ✅ Payment successful
            $payment->update([
                'status'           => 'completed',
                'transaction_id'   => $itnData['pf_payment_id'] ?? null,
                'gateway_response' => $itnData,
                'paid_at'          => now(),
                'notes'            => 'Payment completed via PayFast sandbox.',
            ]);

            Log::channel('single')->info('PayFast ITN: Payment COMPLETED', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
            ]);
        } else {
            // ❌ Payment failed or cancelled
            $payment->update([
                'status'           => 'failed',
                'gateway_response' => $itnData,
                'notes'            => 'PayFast status: ' . $pfPaymentStatus,
            ]);

            Log::channel('single')->info('PayFast ITN: Payment FAILED', [
                'payment_id' => $payment->id,
                'status'     => $pfPaymentStatus,
            ]);
        }

        // PayFast expects a 200 OK response
        return response()->json(['message' => 'ITN processed successfully.']);
    }

    /**
     * Mark a payment as completed (manual confirmation for sandbox demo).
     *
     * Since PayFast sandbox cannot reach localhost for ITN,
     * this endpoint lets the frontend mark the payment as complete
     * after the user returns from PayFast.
     *
     * Route: POST /api/client/bookings/{id}/confirm-payment
     * Auth:  JWT + role:client
     */
    public function confirmPayment(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Find the booking
        $booking = Booking::where('id', $id)
            ->where('client_id', $user->id)
            ->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        // Find the pending payment
        $payment = Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'No pending payment found.'], 404);
        }

        // Mark as completed (sandbox demo)
        $payment->update([
            'status'           => 'completed',
            'transaction_id'   => 'SANDBOX-' . time(),
            'paid_at'          => now(),
            'notes'            => 'Payment confirmed via sandbox demo (manual confirmation).',
            'gateway_response' => ['sandbox_confirmation' => true, 'confirmed_at' => now()->toIso8601String()],
        ]);

        Log::channel('single')->info('PayFast: Sandbox payment manually confirmed', [
            'payment_id' => $payment->id,
            'booking_id' => $booking->id,
        ]);

        return response()->json([
            'message' => 'Payment confirmed successfully.',
            'data'    => [
                'payment_id'     => $payment->id,
                'booking_id'     => $booking->id,
                'amount'         => $payment->amount,
                'status'         => $payment->status,
                'transaction_id' => $payment->transaction_id,
                'paid_at'        => $payment->paid_at,
            ],
        ]);
    }
}
