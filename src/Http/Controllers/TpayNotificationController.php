<?php

namespace Bpotmalnik\LunarTpay\Http\Controllers;

use Bpotmalnik\LunarTpay\Actions\HandleTpayPayment;
use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Enums\PaymentStatus;
use Bpotmalnik\LunarTpay\Models\TpayPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TpayNotificationController extends Controller
{
    public function __invoke(Request $request, TpayClientContract $client): Response
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-JWS-Signature', '');

        if (! $client->verifyNotificationSignature($rawBody, $signature)) {
            Log::warning('Tpay: invalid notification signature', ['ip' => $request->ip()]);

            return response('Forbidden', 401);
        }

        $data = $request->json()->all();
        $transactionData = $data['data'] ?? [];

        if (! is_array($transactionData)) {
            return $this->accepted();
        }

        $transactionId = $transactionData['transactionId'] ?? null;
        $externalId = $transactionData['transactionHiddenDescription'] ?? null;
        $status = PaymentStatus::tryFrom($transactionData['transactionStatus'] ?? '');

        if ($status === null || ! $status->isTerminal()) {
            return $this->accepted();
        }

        if (! $transactionId && ! $externalId) {
            return $this->accepted();
        }

        DB::transaction(function () use ($transactionId, $externalId, $status) {
            $query = TpayPayment::lockForUpdate();
            $transactionId
                ? $query->where('tpay_transaction_id', $transactionId)
                : $query->where('external_id', $externalId);

            $tpayPayment = $query->first();

            if (! $tpayPayment || $tpayPayment->status->isTerminal()) {
                return;
            }

            $order = $tpayPayment->order;

            if (! $order) {
                return;
            }

            app(HandleTpayPayment::class)($tpayPayment, $order, $status);
        });

        return $this->accepted();
    }

    private function accepted(): Response
    {
        return response('TRUE');
    }
}
