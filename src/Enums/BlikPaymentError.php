<?php

namespace Bpotmalnik\LunarTpay\Enums;

enum BlikPaymentError: string
{
    case WrongCode = '63';
    case Unclassified = '100';
    case RejectedByPayer = '101';
    case RejectedByIssuer = '102';
    case InsufficientFunds = '103';
    case Timeout = '104';
    case AliasRequired = '105';
    case LimitExceeded = '106';
    case SecurityRejection = '107';

    public static function fromCode(string|int $code): self
    {
        return self::tryFrom((string) $code) ?? self::Unclassified;
    }

    public function customerMessage(): string
    {
        return trans('lunar-tpay::errors.blik.'.match ($this) {
            self::WrongCode => 'wrong_code',
            self::RejectedByPayer => 'rejected_by_payer',
            self::InsufficientFunds => 'insufficient_funds',
            self::Timeout => 'timeout',
            self::LimitExceeded => 'limit_exceeded',
            default => 'generic',
        });
    }
}
