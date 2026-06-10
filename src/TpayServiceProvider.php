<?php

namespace Bpotmalnik\LunarTpay;

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Http\Controllers\TpayNotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lunar\Facades\Payments;

class TpayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/lunar/tpay.php',
            'lunar.tpay'
        );

        $this->app->singleton(TpayClient::class, function () {
            return new TpayClient(
                clientId: config('lunar.tpay.client_id', ''),
                clientSecret: config('lunar.tpay.client_secret', ''),
                sandbox: (bool) config('lunar.tpay.sandbox', false),
                cacheStore: config('lunar.tpay.cache_store'),
            );
        });

        $this->app->singleton(TpayClientContract::class, function ($app) {
            return $app->make(TpayClient::class);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lunar/tpay.php' => config_path('lunar/tpay.php'),
            ], 'lunar-tpay-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'lunar-tpay-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/lunar-tpay'),
            ], 'lunar-tpay-lang');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'lunar-tpay');

        $this->registerRoutes();

        Payments::extend('tpay', function ($app) {
            return $app->make(TpayPaymentDriver::class);
        });
    }

    private function registerRoutes(): void
    {
        Route::post(
            config('lunar.tpay.notification_path', 'tpay/notification'),
            TpayNotificationController::class
        )->name('tpay.notification');
    }
}
