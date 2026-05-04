<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // Login and 2FA verification — strict to block brute-force
        RateLimiter::for('api_auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Financial operations — per authenticated user, fallback to IP
        RateLimiter::for('api_financial', function (Request $request) {
            return Limit::perMinute(30)->by(
                optional($request->user())->id ?: $request->ip()
            );
        });

        // Webhooks — per IP, generous since partner may batch retries
        RateLimiter::for('api_webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
