<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset link should point to the SPA (e.g. http://localhost:5173/reset-password?token=...&email=...)
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $email = $notifiable->getEmailForPasswordReset();
            return config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($email);
        });

        $this->configureAuthRateLimits();
    }

    /**
     * Brute-force protection for the public auth endpoints (used via `throttle:<name>`).
     */
    private function configureAuthRateLimits(): void
    {
        $tooMany = fn (string $what) => function (Request $request, array $headers) use ($what) {
            $retry = (int) ($headers['Retry-After'] ?? 60);
            return response()->json([
                'success'     => false,
                'message'     => "Too many {$what} attempts. Please try again in {$retry} seconds.",
                'retry_after' => $retry,
            ], 429, $headers);
        };

        $email = fn (Request $request) => mb_strtolower(trim((string) $request->input('email')));

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:' . $email($request) . '|' . $request->ip())->response($tooMany('login')),
            Limit::perMinute(30)->by('login-ip:' . $request->ip())->response($tooMany('login')),
        ]);

        RateLimiter::for('auth-register', fn (Request $request) =>
            Limit::perMinute(10)->by('register:' . $request->ip())->response($tooMany('registration'))
        );

        RateLimiter::for('auth-password', fn (Request $request) => [
            Limit::perMinute(3)->by('pwd:' . $email($request) . '|' . $request->ip())->response($tooMany('password reset')),
            Limit::perMinute(10)->by('pwd-ip:' . $request->ip())->response($tooMany('password reset')),
        ]);
    }
}
