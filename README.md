# lunar-tpay

Tpay Open API payment driver for [LunarPHP](https://lunarphp.io).

The package provides:

- `Payments::driver('tpay')` registration
- Tpay OAuth token handling
- transaction creation and redirect authorization
- JWS-signed notification handling
- Lunar capture transaction creation after successful payment
- full and partial refunds through Tpay
- English and Polish error translations
- a `FakeTpayClient` for application tests

## Requirements

- PHP 8.3+
- ext-openssl
- Laravel 12 or 13
- LunarPHP 1.x

## Installation

```bash
composer require bpotmalnik/lunar-tpay
php artisan vendor:publish --tag=lunar-tpay-config
php artisan vendor:publish --tag=lunar-tpay-migrations
php artisan migrate
```

Optionally publish translations:

```bash
php artisan vendor:publish --tag=lunar-tpay-lang
```

## Configuration

```env
TPAY_CLIENT_ID=
TPAY_CLIENT_SECRET=
TPAY_SANDBOX=true
TPAY_NOTIFICATION_PATH=tpay/notification
TPAY_PAYMENT_DESCRIPTION="Order payment"
TPAY_LANG=pl
TPAY_NOTIFICATION_EMAIL=

TPAY_STATUS_CORRECT=payment-received
TPAY_STATUS_REFUND=payment-refunded
TPAY_STATUS_CANCELED=payment-failed
```

The package registers this notification route automatically:

```text
POST /tpay/notification
```

When creating transactions, the adapter sends this URL in Tpay's `callbacks.notification.url` field. Tpay signs notification bodies using JWS in the `X-JWS-Signature` header.

## Usage

```php
use Lunar\Facades\Payments;

$result = Payments::driver('tpay')
    ->cart($cart)
    ->withData([
        'continue_url' => route('checkout.pending', $order),
        'error_url' => route('checkout.failed', $order),
    ])
    ->authorize();

if (! $result->success) {
    return back()->withErrors(['payment' => $result->message]);
}

return redirect($result->redirectUrl);
```

Supported `withData()` keys:

| Key | Description |
|---|---|
| `continue_url` | Tpay success redirect URL. |
| `error_url` | Tpay error redirect URL. Defaults to `continue_url`. |
| `notification_url` | Override the webhook URL sent to Tpay. |
| `description` | Transaction description. |
| `lang` | Payment page language. Defaults to `TPAY_LANG`. |
| `method` | Optional Tpay payment method. Leave unset for the default Tpay payment page. |
| `group_id` | Optional Tpay payment group ID, e.g. `150` for BLIK or `103` for card. |
| `channel_id` | Optional Tpay channel ID. |

## Notifications

Tpay sends successful payment notifications with `data.transactionStatus` set to `correct`. The package:

- verifies `X-JWS-Signature` using Tpay's signing certificate and root CA,
- looks up the local payment by `data.transactionId` or `data.transactionHiddenDescription`,
- locks the payment row during processing,
- marks the Lunar intent transaction successful,
- creates a Lunar `capture` transaction,
- sets `placed_at` on the order,
- updates the Lunar order status using `lunar.tpay.status_mapping`,
- dispatches `PaymentConfirmed`.

The controller responds to accepted signed notifications with the literal body expected by Tpay:

```text
TRUE
```

## Refunds

Refund amounts are passed in the smallest currency unit:

```php
$result = Payments::driver('tpay')->refund($captureTransaction, 5000);
```

That creates a 50.00 PLN refund request against the original Tpay transaction. Tpay's refund API does not expose refund cancellation in the same transaction endpoint used here, so `cancelRefund()` returns a failed `PaymentRefund` response with an admin-facing message.

## Events

```php
use Bpotmalnik\LunarTpay\Events\PaymentConfirmed;
use Bpotmalnik\LunarTpay\Events\PaymentFailed;

Event::listen(PaymentConfirmed::class, function (PaymentConfirmed $event) {
    $event->order;
    $event->tpayPayment;
});
```

## Testing

```php
use Bpotmalnik\LunarTpay\Contracts\TpayClientContract;
use Bpotmalnik\LunarTpay\Testing\FakeTpayClient;

$fake = new FakeTpayClient;
$this->app->instance(TpayClientContract::class, $fake);
```

The fake returns:

- `transactionId`: `01J9XH0PDXH1Q8C9MMEB3VJ0G6`
- `transactionPaymentUrl`: `https://secure.tpay.com/fake-redirect`
- `status`: `pending`

Its notification signature verifier always returns `true`, which makes webhook feature tests straightforward.

## Development

```bash
composer test
```

In local environments without `ext-intl`, install dependencies with:

```bash
composer update --ignore-platform-req=ext-intl
```

Lunar itself requires `ext-intl`; some cart-calculation paths will still need it at runtime.

## License

MIT. See [LICENSE.md](LICENSE.md).
