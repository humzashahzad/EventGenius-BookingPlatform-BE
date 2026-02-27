<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private JwtService $jwt) {}

    // ── Register ──────────────────────────────────────────────────────────────

    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role'     => ['sometimes', 'in:client,store_owner'],
        ]);

        $user = User::create([
            'name'     => $request->input('name'),
            'email'    => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role'     => $request->input('role', 'client'),
        ]);

        $tokenData = $this->jwt->createToken($user, $request->ip(), $request->userAgent());

        return response()
            ->json([
                'success' => true,
                'data'    => ['user' => $user],
                'message' => 'Registration successful.',
            ], 201)
            ->header('X-Auth-Token', $tokenData['token'])
            ->header('X-Token-Expires-In', (string) $tokenData['expires_in']);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact support.',
            ], 403);
        }

        $tokenData = $this->jwt->createToken($user, $request->ip(), $request->userAgent());

        return response()
            ->json([
                'success' => true,
                'data'    => [
                    'user'  => $user,
                    'token' => $tokenData['token'],
                ],
                'message' => 'Login successful.',
            ]);
    }

    // ── Me ────────────────────────────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user(),
        ]);
    }

    // ── Logout (current session only) ─────────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        $token = substr($request->header('Authorization', ''), 7);
        if ($token) {
            $payload = $this->decodeTokenPayload($token);
            if ($payload && isset($payload['jti'])) {
                $this->jwt->revokeByJti($payload['jti']);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    // ── Logout All Devices ────────────────────────────────────────────────────

    public function logoutAll(Request $request): JsonResponse
    {
        $this->jwt->revokeAllForUser($request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all devices.',
        ]);
    }

    // ── Forgot Password ───────────────────────────────────────────────────────

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => $status === Password::RESET_LINK_SENT,
            'message' => __($status),
        ]);
    }

    // ── Reset Password ────────────────────────────────────────────────────────

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $jwtService = $this->jwt;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($jwtService) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $jwtService->revokeAllForUser($user->id);
            }
        );

        return response()->json([
            'success' => $status === Password::PASSWORD_RESET,
            'message' => __($status),
        ], $status === Password::PASSWORD_RESET ? 200 : 422);
    }

    // ── Helper: decode JWT payload without full validation ────────────────────

    private function decodeTokenPayload(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $decoded = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', 3 - (3 + strlen($parts[1])) % 4)),
            true
        );
        return is_array($decoded) ? $decoded : null;
    }
}
