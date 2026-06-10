<?php

use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Bpotmalnik\LunarTpay\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lunar\DataTypes\Price;
use Lunar\DataTypes\ShippingOption;
use Lunar\Facades\ShippingManifest;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\CartLine;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Order;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Lunar\Models\Transaction;

uses(TestCase::class)->in('Feature', 'Unit');
uses(RefreshDatabase::class)->in('Feature', 'Unit');

function buildCart(array $overrides = []): Cart
{
    Language::factory()->create(['default' => true]);

    $currency = Currency::factory()->create(['default' => true]);

    $taxClass = TaxClass::factory()->create();

    $cart = Cart::factory()->create(array_merge(['currency_id' => $currency->id], $overrides));

    ShippingManifest::addOption(
        new ShippingOption(
            name: 'Standard',
            description: 'Standard delivery',
            identifier: 'STANDARD',
            price: new Price(500, $currency, 1),
            taxClass: $taxClass,
        )
    );

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'shipping',
        'shipping_option' => 'STANDARD',
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'billing',
    ]);

    $variant = ProductVariant::factory()->create();

    $variant->prices()->create([
        'price' => 1000,
        'currency_id' => $currency->id,
        'min_quantity' => 1,
    ]);

    CartLine::factory()->create([
        'cart_id' => $cart->id,
        'purchasable_id' => $variant->id,
        'purchasable_type' => ProductVariant::class,
        'quantity' => 1,
    ]);

    return $cart->calculate();
}

function makeTpayPaymentWithOrder(string $transactionId = '01J9XH0PDXH1Q8C9MMEB3VJ0G6', string $statusValue = 'pending'): array
{
    Language::factory()->create(['default' => true]);
    Currency::factory()->create(['default' => true]);

    $order = Order::factory()->create([
        'status' => 'draft',
        'total' => 10000,
        'sub_total' => 9000,
        'tax_total' => 1000,
    ]);

    $transaction = Transaction::factory()->create([
        'order_id' => $order->id,
        'type' => 'intent',
        'driver' => 'tpay',
        'amount' => 10000,
        'success' => false,
        'reference' => $transactionId,
        'status' => $statusValue,
        'card_type' => 'tpay',
        'meta' => ['tpay_transaction_id' => $transactionId],
    ]);

    $tpayPayment = TpayPayment::create([
        'order_id' => $order->id,
        'transaction_id' => $transaction->id,
        'tpay_transaction_id' => $transactionId,
        'external_id' => Str::uuid(),
        'status' => $statusValue,
        'amount' => 10000,
        'currency' => 'PLN',
        'redirect_url' => 'https://secure.tpay.com/'.$transactionId,
    ]);

    return compact('order', 'transaction', 'tpayPayment');
}

function notificationBody(string $transactionId, string $status = 'correct', ?string $externalId = null): string
{
    return json_encode([
        'type' => 'transaction',
        'data' => [
            'transactionId' => $transactionId,
            'transactionTitle' => 'TR-TEST',
            'transactionAmount' => 100,
            'transactionPaidAmount' => 100,
            'transactionStatus' => $status,
            'transactionHiddenDescription' => $externalId ?? 'ext-001',
            'payerEmail' => 'buyer@example.com',
            'transactionDescription' => 'Test transaction',
        ],
    ]);
}
