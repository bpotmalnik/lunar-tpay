<?php

return [

    'admin' => [
        'access_denied' => 'Tpay API denied access. Check account permissions.',
        'unauthorized' => 'Tpay API authentication failed. Check TPAY_CLIENT_ID and TPAY_CLIENT_SECRET.',

        'invalid_amount' => 'Tpay rejected the payment amount.',
        'invalid_currency' => 'Tpay rejected the payment currency.',
        'invalid_payer' => 'Tpay rejected the payer details.',
        'invalid_pos_id' => 'Tpay rejected the POS ID.',
        'invalid_transaction' => 'Tpay rejected the transaction.',

        'refund_record_not_found' => 'Tpay transaction record not found for this Lunar transaction.',
        'refund_not_confirmed' => 'Refunds can only be issued against correct Tpay payments (current status: :status).',
        'refund_exceeds_balance' => 'Refund amount (:amount) exceeds refundable balance (:available).',
        'refund_not_cancellable' => 'Tpay refunds cannot be cancelled through this adapter (refund: :refund_id, status: :status).',
        'missing_buyer_email' => 'Cannot create Tpay transaction: no buyer email on billing address or customer account.',

        'not_found' => 'Tpay resource not found.',
        'validation_error' => 'Tpay request validation failed: :message',
        'generic' => 'Tpay API error: :message',
    ],

    'customer' => [
        'default_payer_name' => 'Customer',
        'invalid_amount' => 'The payment amount is invalid.',
        'invalid_currency' => 'The selected currency is not supported.',
        'invalid_payer' => 'Check your billing details and try again.',
        'validation_error' => 'Check your payment details and try again.',
        'generic' => 'Payment failed. Please try again or choose a different payment method.',
    ],

];
