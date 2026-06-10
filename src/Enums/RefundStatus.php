<?php

namespace Bpotmalnik\LunarTpay\Enums;

enum RefundStatus: string
{
    case New = 'new';
    case Pending = 'pending';
    case ToComplete = 'to_complete';
    case Cancel = 'cancel';
    case Hold = 'hold';
    case Processed = 'processed';
    case Done = 'done';
    case Declined = 'declined';
    case Authorized = 'authorized';

    public function isFailed(): bool
    {
        return in_array($this, [self::Cancel, self::Declined], true);
    }
}
