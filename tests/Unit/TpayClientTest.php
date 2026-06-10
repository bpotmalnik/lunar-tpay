<?php

use Bpotmalnik\LunarTpay\Exceptions\TpayApiException;
use Bpotmalnik\LunarTpay\TpayClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('authenticates and creates a transaction', function () {
    Http::fake([
        'openapi.sandbox.tpay.com/oauth/auth' => Http::response([
            'access_token' => 'token-123',
            'expires_in' => 7200,
        ]),
        'openapi.sandbox.tpay.com/transactions' => Http::response([
            'transactionId' => '01J9XH0PDXH1Q8C9MMEB3VJ0G6',
            'transactionPaymentUrl' => 'https://secure.tpay.com/pay',
            'status' => 'pending',
        ]),
    ]);

    $result = app(TpayClient::class)->createTransaction([
        'amount' => 100,
        'currency' => 'PLN',
        'description' => 'Order payment',
        'hiddenDescription' => 'external-id',
        'payer' => ['email' => 'buyer@example.com', 'name' => 'Buyer Example'],
        'pay' => ['method' => 'pay_by_link'],
        'callbacks' => [
            'payerUrls' => ['success' => 'https://example.com/return'],
            'notification' => ['url' => 'https://example.com/tpay/notification'],
        ],
    ]);

    expect($result['transactionId'])->toBe('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    Http::assertSent(fn ($request) => $request->url() === 'https://openapi.sandbox.tpay.com/oauth/auth');
    Http::assertSent(fn ($request) => $request->url() === 'https://openapi.sandbox.tpay.com/transactions'
        && $request->hasHeader('Authorization', 'Bearer token-123')
        && $request['hiddenDescription'] === 'external-id');
});

it('reuses the cached oauth token', function () {
    Cache::flush();

    Http::fake([
        'openapi.sandbox.tpay.com/oauth/auth' => Http::response([
            'access_token' => 'token-123',
            'expires_in' => 7200,
        ]),
        'openapi.sandbox.tpay.com/transactions/*' => Http::response([
            'transactionId' => '01J9XH0PDXH1Q8C9MMEB3VJ0G6',
            'status' => 'correct',
        ]),
    ]);

    app(TpayClient::class)->getTransaction('01J9XH0PDXH1Q8C9MMEB3VJ0G6');
    app(TpayClient::class)->getTransaction('01J9XH0PDXH1Q8C9MMEB3VJ0G6');

    Http::assertSentCount(3);
});

it('creates a transaction refund using decimal amount', function () {
    Http::fake([
        'openapi.sandbox.tpay.com/oauth/auth' => Http::response(['access_token' => 'token-123']),
        'openapi.sandbox.tpay.com/transactions/01J9/refunds' => Http::response([
            'refundsList' => [
                ['refundId' => 'rf_123', 'status' => 'pending'],
            ],
        ]),
    ]);

    app(TpayClient::class)->createRefund('01J9', 1234);

    Http::assertSent(fn ($request) => $request->url() === 'https://openapi.sandbox.tpay.com/transactions/01J9/refunds'
        && $request['amount'] === 12.34);
});

it('throws a typed api exception for tpay errors', function () {
    Http::fake([
        'openapi.sandbox.tpay.com/oauth/auth' => Http::response([
            'result' => 'failed',
            'errors' => [
                ['errorCode' => 'unauthorized', 'errorMessage' => 'Invalid credentials'],
            ],
        ], 401),
    ]);

    app(TpayClient::class)->getTransaction('missing');
})->throws(TpayApiException::class, 'Invalid credentials');

it('rejects malformed notification signatures', function () {
    expect(app(TpayClient::class)->verifyNotificationSignature('{}', 'not-a-jws'))->toBeFalse();
});
