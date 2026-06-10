<?php

namespace Bpotmalnik\LunarTpay\Events;

use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lunar\Models\Order;

class PaymentConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TpayPayment $tpayPayment,
        public readonly Order $order,
    ) {}
}
