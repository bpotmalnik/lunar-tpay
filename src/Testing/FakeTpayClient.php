<?php

declare(strict_types=1);

namespace Bpotmalnik\LunarTpay\Testing;

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\RefundReason;
use Bpotmalnik\LunarTpay\Exceptions\TpayApiException;

class FakeTpayClient implements TpayClientContract
{
    public string $transactionId = '01J9XH0PDXH1Q8C9MMEB3VJ0G6';

    public string $transactionPaymentUrl = 'https://secure.tpay.com/fake-redirect';

    /** @var array<string, mixed> */
    public array $lastTransactionPayload = [];

    /** @var array<string, mixed> */
    public array $lastPayPayload = [];

    public ?string $lastPayTransactionId = null;

    public bool $rejectBlikCode = false;

    public bool $failCancelTransaction = false;

    public string $transactionStatus = 'correct';

    /** @var list<array{date: string, paymentErrorCode: string|null}> */
    public array $paymentAttempts = [];

    /** @var list<string> */
    public array $cancelledTransactionIds = [];

    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload): array
    {
        $this->lastTransactionPayload = $payload;

        return [
            'result' => 'success',
            'transactionId' => $this->transactionId,
            'transactionPaymentUrl' => $this->transactionPaymentUrl,
            'status' => 'pending',
            'payments' => $this->payments(rejected: false),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function payTransaction(string $transactionId, array $payload): array
    {
        $this->lastPayTransactionId = $transactionId;
        $this->lastPayPayload = $payload;

        if ($this->rejectBlikCode) {
            $this->paymentAttempts[] = ['date' => now()->format('d.m.Y H:i'), 'paymentErrorCode' => '63'];
        }

        return [
            'result' => 'success',
            'transactionId' => $transactionId,
            'status' => 'pending',
            'payments' => $this->payments($this->rejectBlikCode),
        ];
    }

    /** @return array<string, mixed> */
    public function cancelTransaction(string $transactionId): array
    {
        if ($this->failCancelTransaction) {
            throw TpayApiException::fromResponse(405, [
                'errors' => [['errorCode' => 'method_not_allowed', 'errorMessage' => 'Transaction cannot be canceled']],
            ]);
        }

        $this->cancelledTransactionIds[] = $transactionId;

        return ['result' => 'success', 'transactionId' => $transactionId, 'status' => 'canceled'];
    }

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array
    {
        return [
            'transactionId' => $transactionId,
            'status' => $this->transactionStatus,
            'payments' => ['attempts' => $this->paymentAttempts],
        ];
    }

    /** @return array<string, mixed> */
    private function payments(bool $rejected): array
    {
        return [
            'status' => $rejected ? 'declined' : 'pending',
            'errors' => $rejected
                ? [['errorCode' => 'payment_failed', 'errorMessage' => 'Podany kod jest nieprawidłowy, bądź utracił ważność']]
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public function createRefund(string $transactionId, int $amount, ?RefundReason $reason = null): array
    {
        return [
            'refundsList' => [
                ['refundId' => 'fake-refund-id', 'status' => 'pending'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getRefundStatus(string $refundId): array
    {
        return ['refundId' => $refundId, 'status' => 'pending'];
    }

    public function verifyNotificationSignature(string $payload, string $signature): bool
    {
        return true;
    }
}
