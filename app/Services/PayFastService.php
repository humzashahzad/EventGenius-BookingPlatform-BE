<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;

/**
 * PayFast Payment Gateway Service
 *
 * ╔══════════════════════════════════════════════════╗
 * ║  SANDBOX MODE — Final Year Project Demo Only    ║
 * ║  Do NOT use in production without proper setup  ║
 * ╚══════════════════════════════════════════════════╝
 *
 * Responsibilities:
 *   - Build the payment form fields for PayFast
 *   - Generate the MD5 signature (alphabetically sorted params + passphrase)
 *   - Validate ITN (Instant Transaction Notification) signatures
 */
class PayFastService
{
    /**
     * Build all the hidden form fields required by PayFast, including the signature.
     *
     * @param  Booking  $booking  The booking to generate payment for
     * @param  User     $user     The authenticated client making the payment
     * @return array    ['fields' => [...], 'action_url' => '...', 'sandbox' => bool]
     */
    public function buildPaymentData(Booking $booking, User $user): array
    {
        // ── 1. Generate a unique payment ID ──────────────────────────────
        $mPaymentId = 'DEMO-' . time() . '-' . $booking->id;

        // ── 2. Extract buyer name parts from user's full name ────────────
        $nameParts = explode(' ', trim($user->name ?? 'Test Student'));
        $firstName = $nameParts[0] ?? 'Test';
        $lastName  = count($nameParts) > 1
            ? implode(' ', array_slice($nameParts, 1))
            : 'Student';

        // ── 3. Collect ALL required PayFast parameters ───────────────────
        $data = [
            // Merchant details
            'merchant_id'    => config('payfast.merchant_id'),
            'merchant_key'   => config('payfast.merchant_key'),

            // Redirect URLs
            'return_url'     => config('payfast.return_url') . '?booking_id=' . $booking->id . '&m_payment_id=' . $mPaymentId,
            'cancel_url'     => config('payfast.cancel_url') . '?booking_id=' . $booking->id,
            'notify_url'     => config('payfast.notify_url'),

            // Buyer details
            'name_first'     => $firstName,
            'name_last'      => $lastName,
            'email_address'  => $user->email ?? 'test@example.com',
            'cell_number'    => $user->phone ?? '03001234567',

            // Transaction details
            'm_payment_id'   => $mPaymentId,
            'amount'         => number_format((float) $booking->total_amount, 2, '.', ''),
            'item_name'      => 'Booking #' . $booking->booking_number . ' - ' . ($booking->event_name ?? 'Event Booking'),
        ];

        // ── 4. Generate the MD5 signature ────────────────────────────────
        $data['signature'] = $this->generateSignature($data);

        // ── 5. Determine the correct PayFast URL (sandbox vs live) ───────
        $actionUrl = config('payfast.sandbox')
            ? config('payfast.sandbox_url')
            : config('payfast.live_url');

        return [
            'fields'     => $data,
            'action_url' => $actionUrl,
            'sandbox'    => (bool) config('payfast.sandbox'),
        ];
    }

    /**
     * Generate the PayFast MD5 signature.
     *
     * Algorithm (per PayFast documentation):
     *   1. Sort parameters ALPHABETICALLY by key
     *   2. Concatenate as key=urlencode(value) pairs joined by &
     *   3. Append &passphrase=urlencode(<passphrase>)
     *   4. MD5 hash the entire string
     *
     * @param  array  $data  Payment parameters (signature key is excluded if present)
     * @return string        The MD5 signature hash
     */
    public function generateSignature(array $data): string
    {
        // Remove signature if present — we're generating a fresh one
        unset($data['signature']);

        // Step 1: Sort alphabetically by key
        ksort($data);

        // Step 2: Build the parameter string — only include non-empty values
        $pfParamString = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfParamString .= $key . '=' . urlencode(trim($val)) . '&';
            }
        }

        // Remove trailing &
        $pfParamString = rtrim($pfParamString, '&');

        // Step 3: Append the passphrase
        $passphrase = config('payfast.passphrase');
        if (!empty($passphrase)) {
            $pfParamString .= '&passphrase=' . urlencode(trim($passphrase));
        }

        // Step 4: Generate and return the MD5 hash
        return md5($pfParamString);
    }

    /**
     * Validate the signature received from a PayFast ITN callback.
     *
     * Recomputes the signature from the received data and compares it
     * with the signature PayFast included in the POST.
     *
     * @param  array  $itnData  The full POST data received from PayFast
     * @return bool             true if signature matches, false otherwise
     */
    public function validateItnSignature(array $itnData): bool
    {
        // Extract the received signature
        $receivedSignature = $itnData['signature'] ?? '';

        // Remove it before regenerating
        unset($itnData['signature']);

        // Regenerate and compare
        $expectedSignature = $this->generateSignature($itnData);

        return hash_equals($expectedSignature, $receivedSignature);
    }
}
