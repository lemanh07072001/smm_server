<?php

namespace App\Providers;

use App\Guards\SanctumGuard;
use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Auth\RequestGuard;
use Illuminate\Support\Facades\Auth;
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
        Order::observe(OrderObserver::class);

        Auth::extend('sanctum', function ($app, $name, array $config) {
            return new RequestGuard(
                new SanctumGuard(Auth::getFacadeRoot(), config('sanctum.expiration'), $config['provider'] ?? null),
                $app['request'],
                $app['auth']->createUserProvider($config['provider'] ?? null)
            );
        });
    }
}
