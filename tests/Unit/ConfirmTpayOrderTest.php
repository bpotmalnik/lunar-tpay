<?php

use Bpotmalnik\LunarTpay\Actions\HandleTpayPayment;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Events\PaymentConfirmed;
use Bpotmalnik\LunarTpay\Events\PaymentFailed;
use Illuminate\Support\Facades\Event;
use Lunar\Models\Transaction;

it('marks an order as placed when tpay reports correct', function () {
    Event::fake([PaymentConfirmed::class]);
    ['order' => $order, 'transaction' => $transaction, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder();

    app(HandleTpayPayment::class)($payment, $order, PaymentStatus::Correct);

    $order->refresh();
    $transaction->refresh();

    expect($order->placed_at)->not->toBeNull()
        ->and($order->status)->toBe('payment-received')
        ->and((bool) $transaction->success)->toBeTrue()
        ->and(Transaction::where('order_id', $order->id)->where('type', 'capture')->exists())->toBeTrue();

    Event::assertDispatched(PaymentConfirmed::class, fn ($event) => $event->order->is($order));
});

it('marks the payment as failed when tpay reports canceled', function () {
    Event::fake([PaymentFailed::class]);
    ['order' => $order, 'transaction' => $transaction, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder();

    app(HandleTpayPayment::class)($payment, $order, PaymentStatus::Canceled);

    $order->refresh();
    $transaction->refresh();

    expect($order->placed_at)->toBeNull()
        ->and($order->status)->toBe('payment-failed')
        ->and((bool) $transaction->success)->toBeFalse()
        ->and($transaction->status)->toBe('canceled');

    Event::assertDispatched(PaymentFailed::class, fn ($event) => $event->order->is($order));
});
