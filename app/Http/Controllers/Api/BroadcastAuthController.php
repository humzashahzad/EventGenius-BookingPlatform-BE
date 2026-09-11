<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Custom broadcasting auth endpoint that works with JWT authentication.
 *
 * Laravel's built-in /broadcasting/auth uses the default web guard.
 * Since this app uses JWT tokens, we need a custom endpoint that
 * first resolves the user from the JWT token (via jwt middleware),
 * then delegates to Laravel's Broadcast::auth().
 */
class BroadcastAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        // The jwt middleware already resolved auth()->user()
        // so Broadcast::auth() will use it correctly.
        return Broadcast::auth($request);
    }
}
