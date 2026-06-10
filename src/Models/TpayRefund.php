<?php

namespace Bpotmalnik\LunarTpay\Models;

use Bpotmalnik\LunarTpay\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lunar\Models\Transaction;

/**
 * @property RefundStatus $status
 * @property int $amount
 * @property string $refund_id
 * @property int $tpay_payment_id
 * @property int|null $lunar_transaction_id
 */
class TpayRefund extends Model
{
    protected $table = 'tpay_refunds';

    protected $fillable = [
        'tpay_payment_id',
        'lunar_transaction_id',
        'refund_id',
        'status',
        'amount',
    ];

    protected $casts = [
        'status' => RefundStatus::class,
        'amount' => 'integer',
    ];

    public function isCancellable(): bool
    {
        return false;
    }

    /** @return BelongsTo<TpayPayment, $this> */
    public function tpayPayment(): BelongsTo
    {
        return $this->belongsTo(TpayPayment::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function lunarTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'lunar_transaction_id');
    }
}
