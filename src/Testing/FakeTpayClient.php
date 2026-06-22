<?php

declare(strict_types=1);

namespace Bpotmalnik\LunarTpay\Testing;

use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\RefundReason;

class FakeTpayClient implements TpayClientContract
{
    public string $transactionId = '01J9XH0PDXH1Q8C9MMEB3VJ0G6';

    public string $transactionPaymentUrl = 'https://secure.tpay.com/fake-redirect';

    /** @var array<string, mixed> */
    public array $lastTransactionPayload = [];

    /** @param array<string, mixed> $payload */
    public function createTransaction(array $payload): array
    {
        $this->lastTransactionPayload = $payload;

        return [
            'transactionId' => $this->transactionId,
            'transactionPaymentUrl' => $this->transactionPaymentUrl,
            'status' => 'pending',
        ];
    }

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array
    {
        return ['transactionId' => $transactionId, 'status' => 'correct'];
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
