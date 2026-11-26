<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the invoice for a booking's settled payment (completed or refunded).
 *
 * Every figure comes from the stored booking / payment records, which were
 * produced by BookingPricing when the booking was made, so the invoice always
 * matches what the customer was charged.
 *
 * Invoice number: INV-{year paid}-{payment id, 6 digits}, e.g. INV-2026-000123.
 * It is derived from the payment row, so it is stable and unique per payment.
 */
class InvoiceService
{
    /** Bump when the PDF layout changes so cached files are regenerated. */
    public const TEMPLATE_VERSION = 2;

    public const DISPLAY_TIMEZONE = 'Asia/Karachi';

    /** The payment an invoice is issued for, or null when the booking has none. */
    public static function paymentFor(Booking $booking): ?Payment
    {
        return Payment::where('booking_id', $booking->id)
            ->whereIn('status', ['completed', 'refunded'])
            ->latest('id')
            ->first();
    }

    public static function invoiceNumber(Payment $payment): string
    {
        $issued = $payment->paid_at ?? $payment->created_at ?? now();

        return sprintf('INV-%s-%06d', $issued->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('Y'), $payment->id);
    }

    /** Filesystem-safe download name, e.g. EventGenius-Invoice-INV-2026-000123-BK-ABC123.pdf */
    public static function fileName(string $invoiceNumber, ?string $bookingNumber): string
    {
        $safe = fn (?string $v) => trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $v), '-');
        $parts = array_filter(['EventGenius-Invoice', $safe($invoiceNumber), $safe($bookingNumber)]);

        return implode('-', $parts) . '.pdf';
    }

    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            'payd_fast'     => 'PayFast (card)',
            'bank_transfer' => 'Bank transfer',
            'cash'          => 'Cash',
            default         => 'Recorded by EventGenius',
        };
    }

    /**
     * Invoice data shared by the JSON endpoint and the PDF template.
     *
     * @return array<string, mixed>
     */
    public static function build(Booking $booking, Payment $payment): array
    {
        $booking->loadMissing(['venue', 'store', 'client']);

        $refunded = $payment->status === 'refunded' || $booking->payment_status === 'refunded';
        $number = self::invoiceNumber($payment);
        $issuedAt = $payment->paid_at ?? $booking->paid_at ?? $payment->created_at;

        $guests = (int) $booking->expected_guests;
        $base = round((float) $booking->base_price, 2);
        $amenities = round((float) $booking->amenities_price, 2);
        $discount = round((float) $booking->discount_amount, 2);
        $tax = round((float) $booking->tax_amount, 2);
        $total = round((float) $booking->total_amount, 2);
        $subtotal = round($base + $amenities, 2);
        $paid = round((float) $payment->amount, 2);
        $refundAmount = $refunded ? $paid : 0.0;
        $balance = $refunded ? 0.0 : max(0.0, round($total - $paid, 2));
        $perHead = $guests > 0 ? round($base / $guests, 2) : null;

        $lineItems = [[
            'key'         => 'venue_hire',
            'description' => 'Venue hire' . ($booking->venue?->name ? ' — ' . $booking->venue->name : ''),
            'detail'      => $perHead !== null
                ? 'PKR ' . self::money($perHead) . ' per head × ' . number_format($guests) . ' guests'
                : null,
            'quantity'    => $guests ?: 1,
            'unit_price'  => $perHead ?? $base,
            'amount'      => $base,
        ]];
        if ($amenities > 0) {
            $lineItems[] = [
                'key'         => 'add_ons',
                'description' => 'Paid add-ons',
                'detail'      => 'Amenities selected with the booking',
                'quantity'    => 1,
                'unit_price'  => $amenities,
                'amount'      => $amenities,
            ];
        }

        $isGateway = $payment->payment_method === 'payd_fast' && $payment->gateway_amount;
        $store = $booking->store;
        $venue = $booking->venue;
        $client = $booking->client;

        return [
            'invoice_number' => $number,
            'file_name'      => self::fileName($number, $booking->booking_number),
            'status'         => $refunded ? 'refunded' : 'paid',
            'status_label'   => $refunded ? 'Refunded' : 'Paid',
            'issued_at'      => $issuedAt?->toIso8601String(),
            'issued_on'      => self::localDate($issuedAt),
            'currency'       => $payment->currency ?: 'PKR',
            'issuer'         => [
                'name'          => 'EventGenius',
                'tagline'       => 'Venue booking platform',
                'website'       => preg_replace('#^https?://#', '', rtrim((string) config('app.frontend_url'), '/')),
                'support_email' => config('invoices.support_email'),
            ],
            'customer' => [
                'name'  => $client?->name,
                'email' => $client?->email,
                'phone' => $client?->phone,
            ],
            'store' => [
                'name'    => $store?->name,
                'address' => self::address($store?->address, $store?->city),
                'phone'   => $store?->phone,
                'email'   => $store?->email,
            ],
            'booking' => [
                'id'               => $booking->id,
                'booking_number'   => $booking->booking_number,
                'event_name'       => $booking->event_name,
                'event_type'       => $booking->event_type,
                'event_type_label' => $booking->event_type ? Str::headline($booking->event_type) : null,
                'event_date'       => $booking->event_date?->toDateString(),
                'event_date_label' => $booking->event_date?->format('l, j F Y'),
                'start_time'       => $booking->start_time,
                'end_time'         => $booking->end_time,
                'time_label'       => self::timeRange($booking->start_time, $booking->end_time),
                'duration_hours'   => $booking->duration_hours,
                'guests'           => $guests,
                'venue'            => [
                    'name'    => $venue?->name,
                    'address' => self::address($venue?->address, $venue?->city),
                ],
            ],
            'line_items' => $lineItems,
            'totals'     => [
                'subtotal'     => $subtotal,
                'discount'     => $discount,
                'tax_rate'     => BookingPricing::TAX_RATE,
                'tax_label'    => 'Tax (' . rtrim(rtrim(number_format(BookingPricing::TAX_RATE * 100, 2), '0'), '.') . '%)',
                'tax'          => $tax,
                'total'        => $total,
                'amount_paid'  => $paid,
                'refunded'     => $refundAmount,
                'balance_due'  => $balance,
            ],
            'payment' => [
                'id'               => $payment->id,
                'status'           => $payment->status,
                'method'           => $payment->payment_method,
                'method_label'     => self::methodLabel($payment->payment_method),
                'transaction_id'   => $payment->transaction_id,
                'reference'        => $payment->payment_reference,
                'amount'           => $paid,
                'currency'         => $payment->currency ?: 'PKR',
                'paid_at'          => $payment->paid_at?->toIso8601String(),
                'paid_at_label'    => self::localDateTime($payment->paid_at),
                'refunded_at'      => $payment->refunded_at?->toIso8601String(),
                'refunded_at_label'=> self::localDateTime($payment->refunded_at),
                'gateway_amount'   => $isGateway ? (float) $payment->gateway_amount : null,
                'gateway_currency' => $isGateway ? ($payment->gateway_currency ?: 'ZAR') : null,
                'exchange_rate'    => $isGateway ? (float) $payment->exchange_rate : null,
            ],
        ];
    }

    /**
     * Render (or reuse a cached copy of) the invoice PDF, cached per payment in
     * storage/app/invoices.
     */
    public static function pdf(Booking $booking, Payment $payment, array $invoice): string
    {
        // Keyed on the invoice content, so any change to the booking, payment,
        // customer or venue details produces a fresh file.
        $key = sprintf('%d-%s-v%d', $payment->id, substr(md5((string) json_encode($invoice)), 0, 16), self::TEMPLATE_VERSION);
        $dir = storage_path('app/invoices');
        $path = $dir . '/' . $key . '.pdf';

        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false && str_starts_with($cached, '%PDF')) {
                return $cached;
            }
        }

        $pdf = Pdf::loadView('invoices.booking', [
            'invoice' => $invoice,
            'logo'    => self::logoDataUri(),
        ])
            ->setPaper('a4')
            ->setOption([
                'defaultFont'             => 'DejaVu Sans',
                'isRemoteEnabled'         => false,
                'isPhpEnabled'            => false,
                'isFontSubsettingEnabled' => true,
                'dpi'                     => 96,
            ]);
        $pdf->render();

        // "Page X of Y" in the footer, right-aligned with the page content.
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $size = 6.5;
        $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 9 of 9', $font, $size);
        $canvas->page_text($canvas->get_width() - 30 - $width, $canvas->get_height() - 35.5, $label, $font, $size, [0.435, 0.408, 0.388]);

        $output = $pdf->output();

        try {
            File::ensureDirectoryExists($dir);
            // Drop older copies for this payment, then store the new one.
            foreach (glob($dir . '/' . $payment->id . '-*.pdf') ?: [] as $old) {
                @unlink($old);
            }
            file_put_contents($path, $output, LOCK_EX);
        } catch (Throwable $e) {
            report($e);
        }

        return $output;
    }

    public static function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2);
    }

    private static function logoDataUri(): string
    {
        $svg = @file_get_contents(resource_path('views/invoices/logo.svg')) ?: '';

        return $svg === '' ? '' : 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function address(?string $address, ?string $city): ?string
    {
        $address = trim((string) $address);
        $city = trim((string) $city);
        if ($city !== '' && stripos($address, $city) === false) {
            $address = $address === '' ? $city : $address . ', ' . $city;
        }

        return $address === '' ? null : $address;
    }

    private static function timeRange(?string $start, ?string $end): ?string
    {
        $fmt = function (?string $t): string {
            if (!$t) {
                return '';
            }
            [$h, $m] = array_pad(explode(':', $t), 2, '00');
            $h = (int) $h;

            return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, (int) $m, $h >= 12 ? 'PM' : 'AM');
        };
        if (!$start && !$end) {
            return null;
        }

        return trim($fmt($start) . ' – ' . $fmt($end), ' –');
    }

    private static function localDate(?CarbonInterface $d): ?string
    {
        return $d?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('j M Y');
    }

    private static function localDateTime(?CarbonInterface $d): ?string
    {
        return $d ? $d->copy()->setTimezone(self::DISPLAY_TIMEZONE)->format('j M Y, g:i A') . ' PKT' : null;
    }
}
