<?php

return [

    'client_id' => env('TPAY_CLIENT_ID'),

    'client_secret' => env('TPAY_CLIENT_SECRET'),

    'sandbox' => env('TPAY_SANDBOX', false),

    'cache_store' => env('TPAY_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Notification Path
    |--------------------------------------------------------------------------
    |
    | The URL path where Tpay sends payment status notifications (webhooks).
    | You must configure this full URL in your Tpay merchant dashboard
    | under PoS settings → Notification URL.
    |
    | Example: https://yoursite.com/tpay/notification
    |
    */
    'notification_path' => env('TPAY_NOTIFICATION_PATH', 'tpay/notification'),

    /*
    |--------------------------------------------------------------------------
    | Payment Description
    |--------------------------------------------------------------------------
    |
    | Default description shown to the customer on the Tpay payment page.
    | Individual payments can override this via the `description` data key.
    |
    */
    'description' => env('TPAY_PAYMENT_DESCRIPTION', 'Order payment'),

    'lang' => env('TPAY_LANG', 'pl'),

    'method' => env('TPAY_METHOD'),

    'group_id' => env('TPAY_GROUP_ID') !== null ? (int) env('TPAY_GROUP_ID') : null,

    'channel_id' => env('TPAY_CHANNEL_ID') !== null ? (int) env('TPAY_CHANNEL_ID') : null,

    'notification_email' => env('TPAY_NOTIFICATION_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Order Status Mapping
    |--------------------------------------------------------------------------
    |
    | Maps Tpay payment statuses to your Lunar order status slugs.
    | Adjust these to match the statuses configured in your Lunar installation.
    |
    */
    'status_mapping' => [
        'correct' => env('TPAY_STATUS_CORRECT', 'payment-received'),
        'refund' => env('TPAY_STATUS_REFUND', 'payment-refunded'),
        'canceled' => env('TPAY_STATUS_CANCELED', 'payment-failed'),
    ],

];
