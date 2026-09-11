<?php

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  PayFast Payment Gateway Configuration                             ║
 * ║  SANDBOX MODE — Final Year Project Demo Only                       ║
 * ║  Switch PAYFAST_SANDBOX to false and update creds for production   ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

return [

    // ── Merchant Credentials ─────────────────────────────────────────────
    'merchant_id'  => env('PAYFAST_MERCHANT_ID', '10046059'),
    'merchant_key' => env('PAYFAST_MERCHANT_KEY', 's45b32fcnnjyk'),
    'passphrase'   => env('PAYFAST_PASSPHRASE', 'myfinalyearproject2026'),

    // ── Sandbox Toggle ───────────────────────────────────────────────────
    // true  = sandbox (testing)
    // false = live (production)
    'sandbox' => env('PAYFAST_SANDBOX', true),

    // ── PayFast Processing URLs ──────────────────────────────────────────
    'sandbox_url' => 'https://sandbox.payfast.co.za/eng/process',
    'live_url'    => 'https://www.payfast.co.za/eng/process',

    // ── Callback URLs ────────────────────────────────────────────────────
    // return_url & cancel_url → Vue frontend (browser redirect)
    // notify_url → Laravel API (server-to-server ITN from PayFast)
    'return_url' => env('PAYFAST_RETURN_URL', 'http://localhost:5173/client/payment-success'),
    'cancel_url' => env('PAYFAST_CANCEL_URL', 'http://localhost:5173/client/payment-cancel'),
    'notify_url' => env('PAYFAST_NOTIFY_URL', 'http://localhost:8000/api/payfast/itn'),

];
