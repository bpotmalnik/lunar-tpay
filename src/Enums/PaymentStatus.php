<?php

namespace Bpotmalnik\LunarTpay\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Correct = 'correct';
    case Refund = 'refund';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Correct,
            self::Refund,
            self::Canceled => true,
            default => false,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Correct;
    }
}
