<?php

declare(strict_types=1);

namespace Bpotmalnik\LunarTpay\Contracts;

use Bpotmalnik\LunarTpay\Enums\RefundReason;

interface TpayClientContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payTransaction(string $transactionId, array $payload): array;

    /** @return array<string, mixed> */
    public function cancelTransaction(string $transactionId): array;

    /** @return array<string, mixed> */
    public function getTransaction(string $transactionId): array;

    /**
     * @return array<string, mixed>
     */
    public function createRefund(string $transactionId, int $amount, ?RefundReason $reason = null): array;

    /** @return array<string, mixed> */
    public function getRefundStatus(string $refundId): array;

    public function verifyNotificationSignature(string $payload, string $jws): bool;
}
