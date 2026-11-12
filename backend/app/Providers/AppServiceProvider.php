<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        // This application supports first-party sessions only, not bearer API tokens.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('studio.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token, 'email' => $user->email,
        ]));
        RateLimiter::for('login', fn ($request) => [
            Limit::perMinute(5)->by(hash('sha256', is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '').'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('account-setup', fn ($request) => Limit::perMinute(15)->by($request->ip()));
        RateLimiter::for('password-recovery', fn ($request) => Limit::perMinute(10)->by($request->ip()));
    }
}
