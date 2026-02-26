<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Str;

class JwtService
{
    private string $secret;
    private int $ttl; // minutes

    public function __construct()
    {
        $this->secret = config('jwt.secret') ?? 'fallback-secret-change-in-env';
        $this->ttl    = (int) config('jwt.ttl', 1440); // 24 hours default
    }

    // ── Encode ────────────────────────────────────────────────────────────────

    public function encode(array $payload): string
    {
        $header  = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode(json_encode($payload));
        $sig     = $this->sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$sig}";
    }

    // ── Decode ────────────────────────────────────────────────────────────────

    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $sig] = $parts;

        // Verify signature
        $expectedSig = $this->sign("{$header}.{$payload}");
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);
        if (!$decoded) {
            return null;
        }

        // Check expiry
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            return null;
        }

        return $decoded;
    }

    // ── Create token for a user ───────────────────────────────────────────────

    public function createToken(User $user, string $ipAddress = null, string $userAgent = null): array
    {
        $jti = Str::uuid()->toString(); // unique token ID

        $payload = [
            'iss'     => config('app.url'),
            'sub'     => $user->id,
            'jti'     => $jti,
            'role'    => $user->role,
            'iat'     => time(),
            'exp'     => time() + ($this->ttl * 60),
        ];

        $token = $this->encode($payload);

        // Store session in DB
        UserSession::create([
            'user_id'    => $user->id,
            'jti'        => $jti,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => now()->addMinutes($this->ttl),
            'last_used_at' => now(),
        ]);

        return [
            'token'      => $token,
            'expires_in' => $this->ttl * 60,
            'jti'        => $jti,
        ];
    }

    // ── Validate token and check DB session ──────────────────────────────────

    public function validate(string $token): ?User
    {
        $payload = $this->decode($token);
        if (!$payload) {
            return null;
        }

        // Check session exists and is not revoked
        $session = UserSession::where('jti', $payload['jti'])
            ->where('user_id', $payload['sub'])
            ->where('is_revoked', false)
            ->first();

        if (!$session) {
            return null;
        }

        // Touch last used
        $session->update(['last_used_at' => now()]);

        return User::find($payload['sub']);
    }

    // ── Revoke a specific token ───────────────────────────────────────────────

    public function revokeByJti(string $jti): void
    {
        UserSession::where('jti', $jti)->update(['is_revoked' => true]);
    }

    // ── Revoke all tokens for a user ─────────────────────────────────────────

    public function revokeAllForUser(int $userId): void
    {
        UserSession::where('user_id', $userId)->update(['is_revoked' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $data, $this->secret, true));
    }
}
