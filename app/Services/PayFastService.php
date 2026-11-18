<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * PayFast (South Africa) Payment Gateway Service
 *
 * Responsibilities:
 *   - Convert the app amount (PKR) to the gateway amount (ZAR)
 *   - Build the checkout form fields + MD5 signature (documented field order)
 *   - Validate ITNs: signature, source IP, amount, server-to-server validation
 */
class PayFastService
{
    /**
     * PayFast's documented attribute order for the checkout form. The
     * signature MUST be computed over the fields in this order.
     */
    public const FIELD_ORDER = [
        'merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url',
        'name_first', 'name_last', 'email_address', 'cell_number',
        'm_payment_id', 'amount', 'item_name', 'item_description',
        'custom_int1', 'custom_int2', 'custom_int3', 'custom_int4', 'custom_int5',
        'custom_str1', 'custom_str2', 'custom_str3', 'custom_str4', 'custom_str5',
        'email_confirmation', 'confirmation_address', 'payment_method',
    ];

    /** Convert an app-currency (PKR) amount to the ZAR amount PayFast charges. */
    public function toGatewayAmount(float $pkrAmount): float
    {
        return round($pkrAmount * $this->rate(), 2);
    }

    public function rate(): float
    {
        return (float) config('payfast.pkr_to_zar_rate', 0.065);
    }

    /**
     * Build the hidden form fields (incl. signature) for a pending payment.
     *
     * @return array ['fields' => [...], 'action_url' => '...', 'sandbox' => bool]
     */
    public function buildPaymentData(Booking $booking, User $user, Payment $payment): array
    {
        $nameParts = preg_split('/\s+/', trim($user->name ?: 'EventGenius Client'));
        $firstName = $nameParts[0] ?: 'Client';
        $lastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Customer';

        $mPaymentId = $payment->payment_reference;

        $data = [
            'merchant_id'      => (string) config('payfast.merchant_id'),
            'merchant_key'     => (string) config('payfast.merchant_key'),
            'return_url'       => config('payfast.return_url') . '?booking_id=' . $booking->id . '&m_payment_id=' . $mPaymentId,
            'cancel_url'       => config('payfast.cancel_url') . '?booking_id=' . $booking->id . '&m_payment_id=' . $mPaymentId,
            'notify_url'       => (string) config('payfast.notify_url'),
            'name_first'       => mb_substr($firstName, 0, 100),
            'name_last'        => mb_substr($lastName, 0, 100),
            'email_address'    => (string) $user->email,
            'm_payment_id'     => $mPaymentId,
            'amount'           => number_format((float) $payment->gateway_amount, 2, '.', ''),
            'item_name'        => mb_substr('Booking #' . $booking->booking_number . ' - ' . ($booking->event_name ?: 'Event Booking'), 0, 100),
            'item_description' => mb_substr(
                'PKR ' . number_format((float) $payment->amount, 2, '.', '') . ' at ' . $this->rate() . ' ZAR per PKR', 0, 255
            ),
            'custom_int1'      => (string) $booking->id,
            'custom_int2'      => (string) $payment->id,
        ];

        $data['signature'] = $this->generateSignature($data);

        return [
            'fields'     => $data,
            'action_url' => config('payfast.sandbox') ? config('payfast.sandbox_url') : config('payfast.live_url'),
            'sandbox'    => (bool) config('payfast.sandbox'),
        ];
    }

    /**
     * Checkout signature: non-empty fields in PayFast's documented order,
     * key=urlencode(trim(value)) joined by '&', plus '&passphrase=…' ONLY
     * when a passphrase is configured, then MD5.
     */
    public function generateSignature(array $data): string
    {
        unset($data['signature']);

        $ordered = [];
        foreach (self::FIELD_ORDER as $key) {
            if (array_key_exists($key, $data)) {
                $ordered[$key] = $data[$key];
                unset($data[$key]);
            }
        }
        // Any unknown extra fields go last, in their given order
        $ordered += $data;

        $pairs = [];
        foreach ($ordered as $key => $val) {
            $val = trim((string) $val);
            if ($val !== '') {
                $pairs[] = $key . '=' . urlencode($val);
            }
        }

        return md5($this->appendPassphrase(implode('&', $pairs)));
    }

    /**
     * ITN parameter string: every POSTed field in the order received
     * (including empty ones), excluding 'signature', without passphrase.
     * This is also the body sent to /eng/query/validate.
     */
    public function itnParamString(array $postData): string
    {
        $pairs = [];
        foreach ($postData as $key => $val) {
            if ($key === 'signature') {
                continue;
            }
            $pairs[] = $key . '=' . urlencode(stripslashes((string) $val));
        }

        return implode('&', $pairs);
    }

    /** (a) Signature over the POSTed fields in received order. */
    public function validItnSignature(array $postData): bool
    {
        $received = (string) ($postData['signature'] ?? '');
        if ($received === '') {
            return false;
        }

        $expected = md5($this->appendPassphrase($this->itnParamString($postData)));

        return hash_equals($expected, strtolower($received));
    }

    /** (b) Source IP must belong to PayFast (resolved hosts or published ranges). */
    public function validSourceIp(?string $ip): bool
    {
        if (!config('payfast.verify_source')) {
            return true;
        }
        if (!$ip) {
            return false;
        }

        $validIps = [];
        foreach (config('payfast.valid_hosts', []) as $host) {
            $resolved = @gethostbynamel($host);
            if ($resolved) {
                $validIps = array_merge($validIps, $resolved);
            }
        }

        if (in_array($ip, array_unique($validIps), true)) {
            return true;
        }

        return IpUtils::checkIp($ip, config('payfast.valid_ip_ranges', []));
    }

    /** (c) amount_gross must match the expected ZAR amount within R0.01. */
    public function validAmount(float $expected, mixed $amountGross): bool
    {
        if (!is_numeric($amountGross)) {
            return false;
        }

        return abs($expected - (float) $amountGross) <= 0.01;
    }

    /** (d) Server-to-server confirmation with PayFast: body must be "VALID". */
    public function validWithServer(array $postData): bool
    {
        if (!config('payfast.verify_with_server')) {
            return true;
        }

        $url = config('payfast.sandbox')
            ? config('payfast.sandbox_validate_url')
            : config('payfast.live_validate_url');

        try {
            $response = Http::timeout(10)
                ->withBody($this->itnParamString($postData), 'application/x-www-form-urlencoded')
                ->post($url);

            return trim($response->body()) === 'VALID';
        } catch (Throwable $e) {
            Log::warning('PayFast ITN: server validation request failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function appendPassphrase(string $paramString): string
    {
        $passphrase = trim((string) config('payfast.passphrase', ''));
        if ($passphrase !== '') {
            $paramString .= '&passphrase=' . urlencode($passphrase);
        }

        return $paramString;
    }
}
