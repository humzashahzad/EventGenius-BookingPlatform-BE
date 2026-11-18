<?php

/**
 * PayFast (South Africa) Payment Gateway Configuration
 *
 * SANDBOX MODE by default — Final Year Project demo.
 * Credentials are read from the environment ONLY. Never commit real
 * merchant keys or passphrases to this file (the repository is public).
 */

return [

    // ── Merchant Credentials (env only, no defaults) ─────────────────────
    'merchant_id'  => env('PAYFAST_MERCHANT_ID'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY'),

    // Optional. Leave empty if no passphrase is configured on the PayFast
    // merchant account (Settings → Developer Settings). When empty it is
    // NOT appended to the signature string.
    'passphrase'   => env('PAYFAST_PASSPHRASE', ''),

    // ── Sandbox Toggle ───────────────────────────────────────────────────
    'sandbox' => (bool) env('PAYFAST_SANDBOX', true),

    // ── PayFast URLs ─────────────────────────────────────────────────────
    'sandbox_url'          => 'https://sandbox.payfast.co.za/eng/process',
    'live_url'             => 'https://www.payfast.co.za/eng/process',
    'sandbox_validate_url' => 'https://sandbox.payfast.co.za/eng/query/validate',
    'live_validate_url'    => 'https://www.payfast.co.za/eng/query/validate',

    // ── Callback URLs ────────────────────────────────────────────────────
    // return_url & cancel_url → Vue frontend (browser redirect)
    // notify_url → Laravel API (server-to-server ITN from PayFast)
    'return_url' => env('PAYFAST_RETURN_URL', 'http://localhost:5173/customer/payment-success'),
    'cancel_url' => env('PAYFAST_CANCEL_URL', 'http://localhost:5173/customer/payment-cancel'),
    'notify_url' => env('PAYFAST_NOTIFY_URL', 'http://localhost:8000/api/payfast/itn'),

    // ── Currency ─────────────────────────────────────────────────────────
    // The app prices everything in PKR. PayFast (ZA) only charges ZAR, so
    // the PKR total is converted with this rate before being sent.
    'app_currency'     => 'PKR',
    'gateway_currency' => 'ZAR',
    'pkr_to_zar_rate'  => (float) env('PAYFAST_PKR_TO_ZAR_RATE', 0.065),
    'min_amount'       => 5.00, // PayFast minimum transaction amount (ZAR)

    // ── ITN security checks ──────────────────────────────────────────────
    // Keep both ON outside local testing.
    'verify_source'      => (bool) env('PAYFAST_VERIFY_SOURCE', true),
    'verify_with_server' => (bool) env('PAYFAST_VERIFY_WITH_SERVER', true),

    // Hosts whose resolved IPs may send ITNs, plus PayFast's published ranges.
    'valid_hosts' => [
        'www.payfast.co.za',
        'sandbox.payfast.co.za',
        'w1w.payfast.co.za',
        'w2w.payfast.co.za',
    ],
    'valid_ip_ranges' => [
        '197.97.145.144/28',
        '41.74.179.192/27',
        '102.216.36.0/28',
        '102.216.36.128/28',
        '144.126.193.139/32',
    ],

];
