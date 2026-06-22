<?php

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Bpotmalnik\LunarTpay\Models\TpayRefund;
use Bpotmalnik\LunarTpay\Testing\FakeTpayClient;
use Bpotmalnik\LunarTpay\TpayPaymentDriver;
use Lunar\Models\OrderAddress;
use Lunar\Models\Transaction;

it('authorizes an order by creating a tpay transaction', function () {
    ['order' => $order] = makeTpayPaymentWithOrder(statusValue: PaymentStatus::Canceled->value);
    $order->update(['placed_at' => null, 'currency_code' => 'PLN']);
    $order->transactions()->delete();
    TpayPayment::query()->delete();

    OrderAddress::factory()->create([
        'order_id' => $order->id,
        'type' => 'billing',
        'first_name' => 'Anna',
        'last_name' => 'Kowalska',
        'contact_email' => 'anna@example.com',
    ]);

    $fake = new FakeTpayClient;
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/return'])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->paymentType)->toBe('tpay')
        ->and($result->redirectUrl)->toBe($fake->transactionPaymentUrl);

    expect(TpayPayment::where('tpay_transaction_id', $fake->transactionId)->exists())->toBeTrue();
    expect($fake->lastTransactionPayload)->not->toHaveKey('pay');
});

it('returns an existing non-terminal redirect url for the order', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        statusValue: PaymentStatus::Pending->value,
    );

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe($payment->redirect_url);
});

it('creates a refund for a correct payment', function () {
    ['order' => $order, 'transaction' => $transaction, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        statusValue: PaymentStatus::Correct->value,
    );
    $transaction->update(['success' => true, 'status' => PaymentStatus::Correct->value]);
    $payment->update(['status' => PaymentStatus::Correct]);

    app()->instance(TpayClientContract::class, new FakeTpayClient);

    $result = app(TpayPaymentDriver::class)->refund($transaction, 5000);

    expect($result->success)->toBeTrue()
        ->and(TpayRefund::where('amount', 5000)->where('refund_id', 'fake-refund-id')->exists())->toBeTrue()
        ->and(Transaction::where('order_id', $order->id)->where('type', 'refund')->exists())->toBeTrue();
});

it('does not refund a non-correct payment', function () {
    ['transaction' => $transaction] = makeTpayPaymentWithOrder(statusValue: PaymentStatus::Pending->value);

    $result = app(TpayPaymentDriver::class)->refund($transaction, 5000);

    expect($result->success)->toBeFalse();
});
