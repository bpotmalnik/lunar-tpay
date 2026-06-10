<?php

namespace Bpotmalnik\LunarTpay\Enums;

enum ApiErrorType: string
{
    case AccessDenied = 'access_denied';
    case InvalidAmount = 'invalid_amount';
    case InvalidCurrency = 'invalid_currency';
    case InvalidPayer = 'invalid_payer';
    case InvalidPosId = 'invalid_pos_id';
    case InvalidTransaction = 'invalid_transaction';
    case NotFound = 'not_found';
    case Unauthorized = 'unauthorized';
    case ValidationError = 'validation_error';

    public function isCustomerSafe(): bool
    {
        return match ($this) {
            self::InvalidAmount,
            self::InvalidCurrency,
            self::InvalidPayer,
            self::ValidationError => true,
            default => false,
        };
    }

    public function translationKey(): string
    {
        return $this->value;
    }

    public function customerMessage(): string
    {
        if (! $this->isCustomerSafe()) {
            return trans('lunar-tpay::errors.customer.generic');
        }

        return trans("lunar-tpay::errors.customer.{$this->translationKey()}");
    }

    public function adminMessage(string $rawMessage = ''): string
    {
        $key = "lunar-tpay::errors.admin.{$this->translationKey()}";

        return trans()->has($key)
            ? trans($key, ['message' => $rawMessage])
            : trans('lunar-tpay::errors.admin.generic', ['message' => $rawMessage ?: $this->value]);
    }
}
