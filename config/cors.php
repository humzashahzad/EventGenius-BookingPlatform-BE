<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://10.189.174.153:5173',
        'https://10.189.174.153:5173',
    ],

    // Allow any LAN origin (same machine or other device on WiFi): http(s)://<ip>:5173
    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1|10\.\d+\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+):(5173|5174|3000|8080)$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Auth-Token', 'X-Token-Expires-In'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
