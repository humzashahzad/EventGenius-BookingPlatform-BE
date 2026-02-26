<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Secret Key
    |--------------------------------------------------------------------------
    | This key is used to sign JWT tokens. Generate with:
    | php artisan jwt:secret
    */
    'secret' => env('JWT_SECRET', 'change-this-to-a-secure-random-string-at-least-32-chars'),

    /*
    |--------------------------------------------------------------------------
    | Token TTL (Time To Live) in minutes
    |--------------------------------------------------------------------------
    */
    'ttl' => env('JWT_TTL', 1440), // 24 hours

    /*
    |--------------------------------------------------------------------------
    | Refresh TTL in minutes
    |--------------------------------------------------------------------------
    */
    'refresh_ttl' => env('JWT_REFRESH_TTL', 20160), // 2 weeks
];
