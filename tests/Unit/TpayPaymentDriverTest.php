<?php

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\BlikPaymentError;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Exceptions\TpayApiException;
use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Bpotmalnik\LunarTpay\Models\TpayRefund;
use Bpotmalnik\LunarTpay\Testing\FakeTpayClient;
use Bpotmalnik\LunarTpay\TpayPaymentDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

it('creates a blik level 0 transaction and returns the continue url', function () {
    $order = makeUnpaidOrderWithBillingAddress();
    $fake = new FakeTpayClient;
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData([
            'continue_url' => 'https://example.com/pending',
            'blik_token' => '123456',
            'payer_ip' => '203.0.113.10',
            'payer_user_agent' => 'Mozilla/5.0',
        ])
        ->authorize();

    $payment = TpayPayment::sole();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://example.com/pending')
        ->and($fake->lastTransactionPayload['pay'])->toBe(['groupId' => TpayPaymentDriver::BLIK_GROUP_ID])
        ->and($fake->lastPayTransactionId)->toBe($fake->transactionId)
        ->and($fake->lastPayPayload)->toBe([
            'groupId' => TpayPaymentDriver::BLIK_GROUP_ID,
            'method' => 'transfer',
            'blikPaymentData' => ['blikToken' => '123456', 'type' => 0],
        ])
        ->and($fake->lastTransactionPayload['payer']['ip'])->toBe('203.0.113.10')
        ->and($fake->lastTransactionPayload['payer']['userAgent'])->toBe('Mozilla/5.0')
        ->and($payment->groupId())->toBe(TpayPaymentDriver::BLIK_GROUP_ID)
        ->and($payment->transaction->meta['blik_attempt'])->toBe(0);
});

it('fails when tpay rejects the blik code of a new transaction and keeps it for a retry', function () {
    $order = makeUnpaidOrderWithBillingAddress();
    $fake = new FakeTpayClient;
    $fake->rejectBlikCode = true;
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'blik_token' => '000000'])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(__('lunar-tpay::errors.blik.wrong_code'))
        ->and(TpayPayment::sole()->status)->toBe(PaymentStatus::Pending);
});

it('retries an existing blik transaction with a new code', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );

    $fake = new FakeTpayClient;
    $fake->paymentAttempts = [['date' => '2026-09-26 12:00:00', 'paymentErrorCode' => '63']];
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'blik_token' => '654321'])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://example.com/pending')
        ->and($fake->lastPayTransactionId)->toBe('01J9EXISTING')
        ->and($fake->lastPayPayload['blikPaymentData']['blikToken'])->toBe('654321')
        ->and($fake->lastTransactionPayload)->toBe([])
        ->and($payment->transaction->fresh()->meta['blik_attempt'])->toBe(1);
});

it('fails when tpay rejects the blik code of a retry', function () {
    ['order' => $order] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );

    $fake = new FakeTpayClient;
    $fake->rejectBlikCode = true;
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'blik_token' => '000000'])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(__('lunar-tpay::errors.blik.wrong_code'))
        ->and($fake->lastPayTransactionId)->toBe('01J9EXISTING');
});

it('fails instead of throwing when tpay is unreachable during a blik payment', function (string $call) {
    ['order' => $order] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );

    app()->instance(TpayClientContract::class, new class($call) extends FakeTpayClient
    {
        public function __construct(private readonly string $call) {}

        public function getTransaction(string $transactionId): array
        {
            return $this->call === 'getTransaction'
                ? throw new ConnectionException('Connection lost')
                : parent::getTransaction($transactionId);
        }

        public function payTransaction(string $transactionId, array $payload): array
        {
            return $this->call === 'payTransaction'
                ? throw new ConnectionException('Connection lost')
                : parent::payTransaction($transactionId, $payload);
        }
    });

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'blik_token' => '123456'])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(__('lunar-tpay::errors.customer.generic'));
})->with(['getTransaction', 'payTransaction']);

it('authorizes a blik retry without a continue url or stored redirect url', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );
    $payment->update(['redirect_url' => null]);
    app()->instance(TpayClientContract::class, new FakeTpayClient);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['blik_token' => '123456'])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBeNull();
});

it('replaces a blik transaction once tpay retries are used up', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );
    OrderAddress::factory()->create(['order_id' => $order->id, 'type' => 'billing', 'contact_email' => 'anna@example.com']);

    $fake = new FakeTpayClient;
    $fake->transactionId = '01J9REPLACEMENT';
    $fake->paymentAttempts = array_fill(0, TpayPaymentDriver::BLIK_MAX_ATTEMPTS, ['date' => '2026-09-26 12:00:00', 'paymentErrorCode' => '63']);
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'blik_token' => '123456'])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($fake->cancelledTransactionIds)->toBe(['01J9EXISTING'])
        ->and($fake->lastPayTransactionId)->toBe('01J9REPLACEMENT')
        ->and($fake->lastPayPayload['blikPaymentData']['blikToken'])->toBe('123456')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Canceled);
});

it('replaces the pending transaction when the customer switches payment method', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );
    OrderAddress::factory()->create(['order_id' => $order->id, 'type' => 'billing', 'contact_email' => 'anna@example.com']);

    $fake = new FakeTpayClient;
    $fake->transactionId = '01J9CARD';
    $fake->transactionPaymentUrl = 'https://secure.tpay.com/card';
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['continue_url' => 'https://example.com/pending', 'group_id' => 103])
        ->authorize();

    expect($result->success)->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://secure.tpay.com/card')
        ->and($fake->cancelledTransactionIds)->toBe(['01J9EXISTING'])
        ->and($fake->lastTransactionPayload['pay']['groupId'])->toBe(103)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Canceled)
        ->and($order->fresh()->status)->toBe('draft');
});

it('keeps the pending transaction when tpay refuses to cancel it', function () {
    ['order' => $order, 'tpayPayment' => $payment] = makeTpayPaymentWithOrder(
        transactionId: '01J9EXISTING',
        groupId: TpayPaymentDriver::BLIK_GROUP_ID,
    );

    $fake = new FakeTpayClient;
    $fake->failCancelTransaction = true;
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['group_id' => 103])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($fake->lastTransactionPayload)->toBe([])
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('preserves a payment confirmed while cancellation is in flight', function (string $outcome) {
    ['order' => $order, 'tpayPayment' => $payment, 'transaction' => $transaction] = makeTpayPaymentWithOrder(groupId: 150);

    $confirmPayment = function (string $transactionId) {
        $this->call('POST', route('tpay.notification'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_JWS_SIGNATURE' => 'valid',
        ], notificationBody($transactionId))->assertOk()->assertContent('TRUE');
    };

    $fake = new class($confirmPayment, $outcome) extends FakeTpayClient
    {
        public function __construct(private readonly Closure $confirmPayment, private readonly string $outcome) {}

        public function cancelTransaction(string $transactionId): array
        {
            ($this->confirmPayment)($transactionId);

            if ($this->outcome === 'refused') {
                throw TpayApiException::fromResponse(409, ['message' => 'Transaction is not pending']);
            }

            if ($this->outcome === 'connection failure') {
                throw new ConnectionException('Connection lost');
            }

            return parent::cancelTransaction($transactionId);
        }
    };
    app()->instance(TpayClientContract::class, $fake);

    $result = app(TpayPaymentDriver::class)->order($order)->withData(['group_id' => 103])->authorize();

    expect($result->success)->toBeFalse()
        ->and($fake->lastTransactionPayload)->toBe([])
        ->and(TpayPayment::count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Correct)
        ->and($transaction->fresh()->status)->toBe('correct')
        ->and((bool) $transaction->fresh()->success)->toBeTrue()
        ->and($order->fresh()->placed_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe('payment-received')
        ->and($order->transactions()->where('type', 'capture')->count())->toBe(1);
})->with(['refused', 'connection failure', 'success']);

it('keeps a payment pending after a cancellation connection failure and accepts its later confirmation', function () {
    ['order' => $order, 'tpayPayment' => $payment, 'transaction' => $transaction] = makeTpayPaymentWithOrder(groupId: 150);

    Http::fake([
        'openapi.sandbox.tpay.com/oauth/auth' => Http::response(['access_token' => 'token-123']),
        'openapi.sandbox.tpay.com/transactions/*/cancel' => Http::failedConnection(),
    ]);

    $result = app(TpayPaymentDriver::class)->order($order)->withData(['group_id' => 103])->authorize();

    expect($result->success)->toBeFalse()
        ->and(TpayPayment::count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($transaction->fresh()->status)->toBe('pending')
        ->and($order->fresh()->placed_at)->toBeNull();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://openapi.sandbox.tpay.com/transactions');

    app()->instance(TpayClientContract::class, new FakeTpayClient);
    $this->call('POST', route('tpay.notification'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_JWS_SIGNATURE' => 'valid',
    ], notificationBody($payment->tpay_transaction_id))->assertOk()->assertContent('TRUE');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Correct)
        ->and($transaction->fresh()->status)->toBe('correct')
        ->and($order->fresh()->placed_at)->not->toBeNull()
        ->and($order->transactions()->where('type', 'capture')->count())->toBe(1);
});

it('shows the tpay error instead of a blik error when the request fails for another reason', function () {
    $order = makeUnpaidOrderWithBillingAddress();
    app()->instance(TpayClientContract::class, new class extends FakeTpayClient
    {
        public function createTransaction(array $payload): array
        {
            throw TpayApiException::fromResponse(400, [
                'errors' => [['errorCode' => 'invalid_payer', 'errorMessage' => 'Invalid payer email', 'fieldName' => 'payer.email']],
            ]);
        }
    });

    $result = app(TpayPaymentDriver::class)
        ->order($order)
        ->withData(['blik_token' => '123456'])
        ->authorize();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(__('lunar-tpay::errors.customer.invalid_payer'));
});

it('reports a failed blik attempt only for the attempt the shop submitted', function () {
    ['tpayPayment' => $payment, 'transaction' => $transaction] = makeTpayPaymentWithOrder(groupId: TpayPaymentDriver::BLIK_GROUP_ID);
    $transaction->update(['meta' => [...$transaction->meta->getArrayCopy(), 'blik_attempt' => 1]]);

    $firstAttemptFailed = ['payments' => ['attempts' => [['date' => '2026-09-26 12:00:00', 'paymentErrorCode' => '63']]]];
    $secondAttemptRejected = ['payments' => ['attempts' => [
        ['date' => '2026-09-26 12:00:00', 'paymentErrorCode' => '63'],
        ['date' => '2026-09-26 12:01:00', 'paymentErrorCode' => '101'],
    ]]];

    expect($payment->fresh()->failedBlikAttempt($firstAttemptFailed))->toBeNull()
        ->and($payment->fresh()->failedBlikAttempt($secondAttemptRejected))->toBe(BlikPaymentError::RejectedByPayer);
});

it('does not refund a non-correct payment', function () {
    ['transaction' => $transaction] = makeTpayPaymentWithOrder(statusValue: PaymentStatus::Pending->value);

    $result = app(TpayPaymentDriver::class)->refund($transaction, 5000);

    expect($result->success)->toBeFalse();
});
