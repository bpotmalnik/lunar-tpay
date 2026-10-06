<?php

namespace Bpotmalnik\LunarTpay;

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\ApiErrorType;
use Bpotmalnik\LunarTpay\Enums\BlikPaymentError;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Enums\RefundReason;
use Bpotmalnik\LunarTpay\Enums\RefundStatus;
use Bpotmalnik\LunarTpay\Exceptions\TpayApiException;
use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Bpotmalnik\LunarTpay\Models\TpayRefund;
use Bpotmalnik\LunarTpay\Responses\PaymentAuthorize;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Cart;
use Lunar\Models\Contracts\Transaction as TransactionContract;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Lunar\PaymentTypes\AbstractPayment;

class TpayPaymentDriver extends AbstractPayment
{
    public const BLIK_GROUP_ID = 150;

    public const BLIK_MAX_ATTEMPTS = 4;

    protected ?TpayPayment $recoveryPayment = null;

    public function __construct(private readonly TpayClientContract $client) {}

    public function recoverFrom(TpayPayment $failedPayment): static
    {
        $this->recoveryPayment = $failedPayment;

        return $this;
    }

    public function authorize(): PaymentAuthorize
    {
        if ($this->recoveryPayment) {
            return $this->authorizeRecovery();
        }

        if (! $this->order && $this->cart) {
            $this->order = $this->cart->draftOrder ?? $this->cart->createOrder();
        }

        if (! $this->order) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-tpay::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        /** @var Order $order */
        $order = $this->order;

        if ($order->placed_at) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-tpay::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        $existing = TpayPayment::where('order_id', $order->id)
            ->whereNotIn('status', [
                PaymentStatus::Correct->value,
                PaymentStatus::Refund->value,
                PaymentStatus::Canceled->value,
            ])
            ->latest()
            ->first();

        if ($existing && $existing->groupId() === $this->requestedGroupId()) {
            if (! $this->blikToken() && $existing->redirect_url) {
                return $this->succeeded($order->id, $existing->redirect_url);
            }

            if ($this->blikToken()) {
                try {
                    $attemptsUsed = $this->blikAttemptsUsed($existing);
                } catch (TpayApiException|ConnectionException $e) {
                    return $this->failedAuthorize($e);
                }

                if ($attemptsUsed < self::BLIK_MAX_ATTEMPTS) {
                    return $this->payExistingBlik($existing, $attemptsUsed);
                }
            }
        }

        if ($existing && ! $this->cancelPayment($existing)) {
            return $this->failed(trans('lunar-tpay::errors.customer.generic'));
        }

        return $this->callTpay((string) Str::uuid(), null);
    }

    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        return new PaymentCapture(success: true);
    }

    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        /** @var Transaction $transaction */
        $tpayPayment = TpayPayment::where('tpay_transaction_id', $transaction->reference)->first();

        if (! $tpayPayment) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-tpay::errors.admin.refund_record_not_found'),
            );
        }

        if (! $tpayPayment->status->isSuccessful()) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-tpay::errors.admin.refund_not_confirmed', [
                    'status' => $tpayPayment->status->value,
                ]),
            );
        }

        // @phpstan-ignore-next-line
        $refundAmount = $amount ?: $transaction->amount->value;
        $available = $tpayPayment->refundableAmount();

        if ($refundAmount > $available) {
            return new PaymentRefund(
                success: false,
                message: trans('lunar-tpay::errors.admin.refund_exceeds_balance', [
                    'amount' => $refundAmount,
                    'available' => $available,
                ]),
            );
        }

        $reason = isset($this->data['refund_reason'])
            ? RefundReason::from($this->data['refund_reason'])
            : null;

        try {
            $apiResponse = $this->client->createRefund(
                transactionId: $tpayPayment->tpay_transaction_id,
                amount: $refundAmount,
                reason: $reason,
            );

            $refund = $this->extractRefund($apiResponse);

            // @phpstan-ignore-next-line
            $lunarTx = $transaction->order->transactions()->create([
                'parent_transaction_id' => $transaction->id,
                'type' => 'refund',
                'success' => true,
                'driver' => 'tpay',
                'amount' => $refundAmount,
                'reference' => $refund['refundId'],
                'status' => $refund['status'],
                'card_type' => 'tpay',
                'notes' => $notes,
                'meta' => ['refund_id' => $refund['refundId']],
            ]);

            TpayRefund::create([
                'tpay_payment_id' => $tpayPayment->id,
                'lunar_transaction_id' => $lunarTx->id,
                'refund_id' => $refund['refundId'],
                'status' => $refund['status'],
                'amount' => $refundAmount,
            ]);
        } catch (TpayApiException $e) {
            return new PaymentRefund(success: false, message: $this->adminMessage($e));
        }

        return new PaymentRefund(success: true);
    }

    public function cancelRefund(TpayRefund $refund): PaymentRefund
    {
        return new PaymentRefund(
            success: false,
            message: trans('lunar-tpay::errors.admin.refund_not_cancellable', [
                'refund_id' => $refund->refund_id,
                'status' => $refund->status->value,
            ]),
        );
    }

    private function authorizeRecovery(): PaymentAuthorize
    {
        $failed = $this->recoveryPayment;

        if (! $failed->isRecoverable()) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-tpay::errors.customer.generic'),
            );
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        $this->order ??= $failed->order;

        return $this->callTpay($failed->external_id, $failed->id);
    }

    private function callTpay(string $externalId, ?int $parentPaymentId): PaymentAuthorize
    {
        /** @var Order $order */
        $order = $this->order;
        /** @var Cart|null $cart */
        $cart = $this->cart;

        // @phpstan-ignore-next-line (billingAddress nullability and CartContract::$user not in interface)
        $email = $order->billingAddress?->contact_email ?? $cart?->user?->email;

        if (! $email) {
            $response = new PaymentAuthorize(
                success: false,
                message: trans('lunar-tpay::errors.customer.generic'),
                errorType: null,
            );
            Log::warning('Tpay: missing buyer email', ['order' => $order->id]);
            PaymentAttemptEvent::dispatch($response);

            return $response;
        }

        try {
            $payload = array_filter([
                // @phpstan-ignore-next-line
                'amount' => $this->minorToDecimal($order->total->value),
                'currency' => $order->currency_code,
                'hiddenDescription' => $externalId,
                'description' => $this->data['description'] ?? config('lunar.tpay.description', 'Order payment'),
                'lang' => $this->data['lang'] ?? config('lunar.tpay.lang', 'pl'),
                'payer' => array_filter([
                    'email' => $email,
                    'name' => $this->payerName(),
                    // @phpstan-ignore-next-line
                    'phone' => $order->billingAddress?->contact_phone,
                    // @phpstan-ignore-next-line
                    'address' => $order->billingAddress?->line_one,
                    // @phpstan-ignore-next-line
                    'code' => $order->billingAddress?->postcode,
                    // @phpstan-ignore-next-line
                    'city' => $order->billingAddress?->city,
                    // @phpstan-ignore-next-line
                    'country' => $order->billingAddress?->country?->iso2,
                    // @phpstan-ignore-next-line
                    'taxId' => $order->billingAddress?->tax_identifier,
                    'ip' => $this->data['payer_ip'] ?? null,
                    'userAgent' => $this->data['payer_user_agent'] ?? null,
                ]),
                'pay' => $this->payPayload(),
                'callbacks' => [
                    'payerUrls' => array_filter([
                        'success' => $this->data['continue_url'] ?? null,
                        'error' => $this->data['error_url'] ?? $this->data['continue_url'] ?? null,
                    ]),
                    'notification' => array_filter([
                        'url' => $this->data['notification_url'] ?? route('tpay.notification'),
                        'email' => config('lunar.tpay.notification_email'),
                    ]),
                ],
            ]);

            $apiResponse = $this->client->createTransaction($payload);
            $transactionId = $apiResponse['transactionId'];

            /** @var Transaction $transaction */
            $transaction = $order->transactions()->create([
                'type' => 'intent',
                'success' => false,
                'driver' => 'tpay',
                // @phpstan-ignore-next-line
                'amount' => $order->total->value,
                'reference' => $transactionId,
                'status' => $apiResponse['status'],
                'card_type' => 'tpay',
                'meta' => array_filter([
                    'tpay_transaction_id' => $transactionId,
                    'group_id' => $this->requestedGroupId(),
                ]),
            ]);

            $tpayPayment = TpayPayment::create([
                'order_id' => $order->id,
                'transaction_id' => $transaction->id,
                'tpay_transaction_id' => $transactionId,
                'external_id' => $externalId,
                'status' => $apiResponse['status'],
                // @phpstan-ignore-next-line
                'amount' => $order->total->value,
                'currency' => $order->currency_code,
                'redirect_url' => $apiResponse['transactionPaymentUrl'],
                'parent_payment_id' => $parentPaymentId,
            ]);
        } catch (TpayApiException $e) {
            return $this->failedAuthorize($e);
        }

        if ($this->blikToken()) {
            return $this->payExistingBlik($tpayPayment, attemptIndex: 0);
        }

        return $this->succeeded($order->id, $apiResponse['transactionPaymentUrl']);
    }

    private function payExistingBlik(TpayPayment $existing, int $attemptIndex): PaymentAuthorize
    {
        try {
            $apiResponse = $this->client->payTransaction($existing->tpay_transaction_id, [
                'groupId' => self::BLIK_GROUP_ID,
                'method' => 'transfer',
                'blikPaymentData' => ['blikToken' => $this->blikToken(), 'type' => 0],
            ]);
        } catch (TpayApiException|ConnectionException $e) {
            return $this->failedAuthorize($e);
        }

        if ($this->blikWasRejected($apiResponse)) {
            return $this->failed($this->blikRejectionMessage($apiResponse));
        }

        if ($transaction = $existing->transaction) {
            $transaction->update([
                'meta' => collect($transaction->meta)->put('blik_attempt', $attemptIndex)->all(),
            ]);
        }

        return $this->succeeded($existing->order_id, $this->data['continue_url'] ?? $existing->redirect_url);
    }

    private function blikAttemptsUsed(TpayPayment $payment): int
    {
        $transaction = $this->client->getTransaction($payment->tpay_transaction_id);

        return count($transaction['payments']['attempts'] ?? []);
    }

    private function cancelPayment(TpayPayment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return false;
        }

        try {
            $this->client->cancelTransaction($payment->tpay_transaction_id);
        } catch (TpayApiException|ConnectionException $e) {
            Log::warning('Tpay: could not cancel transaction before replacing it', [
                'order' => $payment->order_id,
                'transaction' => $payment->tpay_transaction_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return DB::transaction(function () use ($payment): bool {
            $current = TpayPayment::lockForUpdate()->find($payment->getKey());

            if (! $current || $current->status !== PaymentStatus::Pending) {
                return false;
            }

            $current->update(['status' => PaymentStatus::Canceled]);
            $current->transaction?->update(['status' => PaymentStatus::Canceled->value]);

            return true;
        });
    }

    /** @param array<string, mixed> $apiResponse */
    private function blikWasRejected(array $apiResponse): bool
    {
        return ($apiResponse['result'] ?? null) === 'failed'
            || ! empty($apiResponse['payments']['errors']);
    }

    /** @param array<string, mixed> $apiResponse */
    private function blikRejectionMessage(array $apiResponse): string
    {
        $errorCode = $apiResponse['payments']['errors'][0]['errorCode'] ?? null;

        return ($errorCode === 'payment_failed' ? BlikPaymentError::WrongCode : BlikPaymentError::Unclassified)
            ->customerMessage();
    }

    /**
     * @return array<string, mixed>
     */
    private function payPayload(): array
    {
        return array_filter([
            'method' => $this->blikToken() ? null : ($this->data['method'] ?? config('lunar.tpay.method')),
            'groupId' => $this->requestedGroupId(),
            'channelId' => $this->blikToken() ? null : ($this->data['channel_id'] ?? config('lunar.tpay.channel_id')),
        ]);
    }

    private function requestedGroupId(): ?int
    {
        if ($this->blikToken()) {
            return self::BLIK_GROUP_ID;
        }

        $groupId = $this->data['group_id'] ?? config('lunar.tpay.group_id');

        return filled($groupId) ? (int) $groupId : null;
    }

    private function blikToken(): ?string
    {
        $token = $this->data['blik_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }

    private function succeeded(int $orderId, ?string $redirectUrl): PaymentAuthorize
    {
        $response = new PaymentAuthorize(
            success: true,
            orderId: $orderId,
            paymentType: 'tpay',
            redirectUrl: $redirectUrl,
        );
        PaymentAttemptEvent::dispatch($response);

        return $response;
    }

    private function failed(string $message, ?ApiErrorType $errorType = null): PaymentAuthorize
    {
        $response = new PaymentAuthorize(
            success: false,
            message: $message,
            errorType: $errorType,
        );
        PaymentAttemptEvent::dispatch($response);

        return $response;
    }

    private function failedAuthorize(TpayApiException|ConnectionException $e): PaymentAuthorize
    {
        /** @var Order|null $order */
        $order = $this->order;

        if ($e instanceof ConnectionException) {
            Log::warning('Tpay: payment authorization failed', [
                'order' => $order?->id,
                'error' => $e->getMessage(),
                'blik' => $this->blikToken() !== null,
            ]);

            return $this->failed(trans('lunar-tpay::errors.customer.generic'));
        }

        Log::warning('Tpay: payment authorization failed', [
            'order' => $order?->id,
            'status' => $e->statusCode,
            'error' => $e->getMessage(),
            'blik' => $this->blikToken() !== null,
        ]);

        $message = $this->blikToken() && $this->isBlikTokenError($e)
            ? BlikPaymentError::WrongCode->customerMessage()
            : ($e->errorType?->customerMessage() ?? trans('lunar-tpay::errors.customer.generic'));

        return $this->failed($message, $e->errorType);
    }

    private function isBlikTokenError(TpayApiException $e): bool
    {
        foreach ($e->errors['errors'] ?? [] as $error) {
            $details = is_array($error) ? ($error['fieldName'] ?? '').' '.($error['errorMessage'] ?? '') : '';

            if (str_contains(strtolower($details), 'blik')) {
                return true;
            }
        }

        return false;
    }

    private function adminMessage(TpayApiException $e): string
    {
        return $e->errorType?->adminMessage($e->getMessage())
            ?? trans('lunar-tpay::errors.admin.generic', ['message' => $e->getMessage()]);
    }

    private function payerName(): string
    {
        // @phpstan-ignore-next-line
        $firstName = $this->order?->billingAddress?->first_name;
        // @phpstan-ignore-next-line
        $lastName = $this->order?->billingAddress?->last_name;
        $name = trim("{$firstName} {$lastName}");

        return $name !== '' ? $name : trans('lunar-tpay::errors.customer.default_payer_name');
    }

    private function minorToDecimal(int $amount): float
    {
        return round($amount / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $apiResponse
     * @return array{refundId: string, status: string}
     */
    private function extractRefund(array $apiResponse): array
    {
        $refund = $apiResponse['refundsList'][0]
            ?? $apiResponse['refundsCardsList'][0]
            ?? $apiResponse['refundsNoAccountList'][0]
            ?? null;

        if (! is_array($refund)) {
            return [
                'refundId' => $apiResponse['refundId'] ?? (string) Str::uuid(),
                'status' => $apiResponse['status'] ?? RefundStatus::Pending->value,
            ];
        }

        return [
            'refundId' => $refund['refundId'] ?? $refund['id'] ?? (string) Str::uuid(),
            'status' => $refund['status'] ?? RefundStatus::Pending->value,
        ];
    }
}
