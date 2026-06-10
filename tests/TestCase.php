<?php

namespace Bpotmalnik\LunarTpay\Tests;

use Bpotmalnik\LunarTpay\TpayServiceProvider;
use Cartalyst\Converter\Laravel\ConverterServiceProvider;
use Illuminate\Support\Facades\Http;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Lunar\LunarServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ConverterServiceProvider::class,
            LunarServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            NestedSetServiceProvider::class,
            BlinkServiceProvider::class,
            TpayServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('lunar.tpay.client_id', 'test-client-id');
        $app['config']->set('lunar.tpay.client_secret', 'test-client-secret');
        $app['config']->set('lunar.tpay.sandbox', true);
        $app['config']->set('lunar.tpay.status_mapping', [
            'correct' => 'payment-received',
            'refund' => 'payment-refunded',
            'canceled' => 'payment-failed',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }
}
