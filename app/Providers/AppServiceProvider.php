<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\LiveData::class);
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
        $this->registerLiveData();
    }

    /**
     * Data change events: observe the shared records and broadcast each distinct change once at the
     * end of the request (after the response is sent) or after each queued job.
     */
    private function registerLiveData(): void
    {
        foreach (array_keys(\App\Services\LiveData::ENTITY_BY_CLASS) as $model) {
            $model::observe(\App\Observers\LiveDataObserver::class);
        }

        $flush = fn () => \App\Services\LiveData::flushPending();
        $this->app->terminating($flush);
        Queue::after($flush);
        Queue::exceptionOccurred($flush);
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

        // Ask EventGenius (chat search): per visitor per minute and per day, plus a site-wide ceiling
        // so a burst of chat traffic can never crowd out normal browsing on this small server.
        $chatLimited = fn (string $text) => function (Request $request, array $headers) use ($text) {
            return response()->json([
                'success'     => false,
                'message'     => $text,
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers);
        };
        RateLimiter::for('assistant', fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('assistant.per_minute', 20)))->by('assistant:' . $request->ip())
                ->response($chatLimited("You're sending messages very quickly. Please wait a moment and try again.")),
            Limit::perDay(max(1, (int) config('assistant.per_day', 400)))->by('assistant-day:' . $request->ip())
                ->response($chatLimited("You've reached today's chat limit. You can still search and browse venues as usual.")),
            Limit::perMinute(max(1, (int) config('assistant.global_per_minute', 600)))->by('assistant-all')
                ->response($chatLimited('Lots of people are chatting right now. Please try again in a minute.')),
        ]);
    }
}
