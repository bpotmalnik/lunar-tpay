<?php

namespace Bpotmalnik\LunarTpay\Actions;

use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Events\PaymentConfirmed;
use Bpotmalnik\LunarTpay\Events\PaymentFailed;
use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Lunar\Models\Order;

class HandleTpayPayment
{
    public function __invoke(TpayPayment $tpayPayment, Order $order, PaymentStatus $status): void
    {
        $tpayPayment->update(['status' => $status]);

        if ($status->isSuccessful()) {
            $this->handleConfirmed($tpayPayment, $order);
        } else {
            $this->handleFailed($tpayPayment);
        }

        $mapping = config('lunar.tpay.status_mapping', []);
        $order->update(['status' => $mapping[$status->value] ?? $mapping['canceled'] ?? 'payment-failed']);

        $status->isSuccessful()
            ? PaymentConfirmed::dispatch($tpayPayment, $order)
            : PaymentFailed::dispatch($tpayPayment, $order);
    }

    private function handleConfirmed(TpayPayment $tpayPayment, Order $order): void
    {
        $intent = $tpayPayment->transaction;

        if ($intent) {
            $intent->update(['success' => true, 'status' => PaymentStatus::Correct->value]);

            $order->transactions()->create([
                'parent_transaction_id' => $intent->id,
                'type' => 'capture',
                'success' => true,
                'driver' => 'tpay',
                // @phpstan-ignore-next-line
                'amount' => $intent->amount->value,
                'reference' => $tpayPayment->tpay_transaction_id,
                'status' => PaymentStatus::Correct->value,
                'card_type' => 'tpay',
                'meta' => ['tpay_transaction_id' => $tpayPayment->tpay_transaction_id],
            ]);
        }

        $order->update(['placed_at' => $order->placed_at ?? now()]);
    }

    private function handleFailed(TpayPayment $tpayPayment): void
    {
        $tpayPayment->transaction?->update([
            'success' => false,
            'status' => $tpayPayment->status->value,
        ]);
    }
}
