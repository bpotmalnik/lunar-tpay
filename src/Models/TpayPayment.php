<?php

namespace Bpotmalnik\LunarTpay\Models;

use Bpotmalnik\LunarTpay\Enums\BlikPaymentError;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lunar\Models\Order;
use Lunar\Models\Transaction;

/**
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $tpay_transaction_id
 * @property string|null $redirect_url
 * @property string|null $external_id
 * @property int|null $parent_payment_id
 * @property int $order_id
 * @property int|null $transaction_id
 * @property string $currency
 */
class TpayPayment extends Model
{
    protected $table = 'tpay_payments';

    protected $fillable = [
        'order_id',
        'transaction_id',
        'tpay_transaction_id',
        'external_id',
        'status',
        'amount',
        'currency',
        'redirect_url',
        'parent_payment_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
    ];

    public function isRecoverable(): bool
    {
        return in_array($this->status, [
            PaymentStatus::Pending,
            PaymentStatus::Paid,
            PaymentStatus::Canceled,
        ]);
    }

    public function groupId(): ?int
    {
        $groupId = $this->transaction?->meta['group_id'] ?? null;

        return $groupId === null ? null : (int) $groupId;
    }

    /**
     * @param  array<string, mixed>  $tpayTransaction  Response of GET /transactions/{id}
     */
    public function failedBlikAttempt(array $tpayTransaction): ?BlikPaymentError
    {
        $attemptIndex = $this->transaction?->meta['blik_attempt'] ?? null;

        if ($attemptIndex === null) {
            return null;
        }

        $errorCode = $tpayTransaction['payments']['attempts'][(int) $attemptIndex]['paymentErrorCode'] ?? null;

        return $errorCode === null ? null : BlikPaymentError::fromCode($errorCode);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<TpayPayment, $this> */
    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /** @return HasMany<TpayPayment, $this> */
    public function recoveryAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    /** @return HasMany<TpayRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(TpayRefund::class);
    }

    public function amountRefunded(): int
    {
        return $this->refunds()
            ->whereNotIn('status', [RefundStatus::Cancel->value, RefundStatus::Declined->value])
            ->sum('amount');
    }

    public function refundableAmount(): int
    {
        return $this->amount - $this->amountRefunded();
    }
}
