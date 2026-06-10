<?php

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\RefundReason;
use Bpotmalnik\LunarTpay\Events\PaymentConfirmed;
use Bpotmalnik\LunarTpay\Events\PaymentFailed;
use Illuminate\Support\Facades\Event;

it('rejects notifications with invalid jws signatures', function () {
    $body = notificationBody('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    $this->post(route('tpay.notification'), json_decode($body, true), [
        'X-JWS-Signature' => 'invalid',
    ])->assertStatus(401);
});

it('acknowledges malformed payloads without dispatching events', function () {
    Event::fake([PaymentConfirmed::class, PaymentFailed::class]);
    app()->instance(TpayClientContract::class, new class implements TpayClientContract
    {
        public function createTransaction(array $payload): array
        {
            return [];
        }

        public function getTransaction(string $transactionId): array
        {
            return [];
        }

        public function createRefund(string $transactionId, int $amount, ?RefundReason $reason = null): array
        {
            return [];
        }

        public function getRefundStatus(string $refundId): array
        {
            return [];
        }

        public function verifyNotificationSignature(string $payload, string $jws): bool
        {
            return true;
        }
    });

    $this->postJson(route('tpay.notification'), ['data' => []], [
        'X-JWS-Signature' => 'valid',
    ])->assertOk()->assertJson(['result' => true]);

    Event::assertNothingDispatched();
});

it('confirms a payment from a correct notification', function () {
    Event::fake([PaymentConfirmed::class]);
    ['order' => $order] = makeTpayPaymentWithOrder('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    app()->instance(TpayClientContract::class, new class implements TpayClientContract
    {
        public function createTransaction(array $payload): array
        {
            return [];
        }

        public function getTransaction(string $transactionId): array
        {
            return [];
        }

        public function createRefund(string $transactionId, int $amount, ?RefundReason $reason = null): array
        {
            return [];
        }

        public function getRefundStatus(string $refundId): array
        {
            return [];
        }

        public function verifyNotificationSignature(string $payload, string $jws): bool
        {
            return true;
        }
    });

    $body = notificationBody('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    $this->call('POST', route('tpay.notification'), [], [], [], [
        'HTTP_X_JWS_SIGNATURE' => 'valid',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk()->assertJson(['result' => true]);

    expect($order->fresh()->placed_at)->not->toBeNull();

    Event::assertDispatched(PaymentConfirmed::class);
});
