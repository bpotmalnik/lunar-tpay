<?php

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Events\PaymentConfirmed;
use Bpotmalnik\LunarTpay\Events\PaymentFailed;
use Bpotmalnik\LunarTpay\Testing\FakeTpayClient;
use Illuminate\Support\Facades\Event;

it('rejects notifications with invalid jws signatures', function () {
    $body = notificationBody('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    $this->post(route('tpay.notification'), json_decode($body, true), [
        'X-JWS-Signature' => 'invalid',
    ])->assertStatus(401);
});

it('acknowledges malformed payloads without dispatching events', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    app()->instance(TpayClientContract::class, new FakeTpayClient);

    $this->postJson(route('tpay.notification'), ['data' => []], [
        'X-JWS-Signature' => 'valid',
    ])->assertOk()->assertSee('TRUE');

    Event::assertNothingDispatched();
});

it('confirms a payment from a correct notification', function () {
    Event::fake([PaymentConfirmed::class]);
    ['order' => $order] = makeTpayPaymentWithOrder('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    app()->instance(TpayClientContract::class, new FakeTpayClient);

    $body = notificationBody('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    $this->call('POST', route('tpay.notification'), [], [], [], [
        'HTTP_X_JWS_SIGNATURE' => 'valid',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk()->assertSee('TRUE');

    expect($order->fresh()->placed_at)->not->toBeNull();

    Event::assertDispatched(PaymentConfirmed::class);
});
