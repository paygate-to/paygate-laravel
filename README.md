# PayGate.to for Laravel

Accept credit cards, Apple Pay, Google Pay and crypto (Bitcoin, Ethereum, USDT, USDC, Solana, Monero, Zcash and more) in your Laravel app. Every payment is paid out instantly to your own wallet. No sign up, no API keys, no KYC.

This package wires the [PayGate.to PHP SDK](https://github.com/paygate-to/paygate-php) into Laravel:

- a `PayGate` facade and an injectable `PayGate\Client`, configured from `.env`;
- a `paygate.callback` middleware that rejects any callback not signed by PayGate.to;
- a `paygate_callback_url()` helper that adds a tamper-proof order token to your callback URL.

📘 Docs: [https://paygate.to/docs/sdk/php/#laravel](https://paygate.to/docs/sdk/php/#laravel) · API reference: [card](https://paygate.to/docs/payment-gateway-api/), [crypto](https://paygate.to/docs/crypto-payment-api/)

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Card payments](#card-payments)
- [Crypto on the hosted page](#crypto-on-the-hosted-page)
- [Handling the callback](#handling-the-callback)
- [Configuration](#configuration)
- [Key rotation](#key-rotation)
- [Testing](#testing)
- [Security notes](#security-notes)

## Requirements

- PHP 8.1 or newer with the OpenSSL extension.
- Laravel 10, 11 or 12. Tested on Laravel 12.
- Your app reachable at its public https address, set as `APP_URL`. Callbacks are checked against it.

## Install

```bash
composer require paygate-to/paygate-laravel
php artisan vendor:publish --tag=paygate-config   # optional: config/paygate.php
```

The service provider and the `PayGate` facade are registered automatically. Add your payout wallets to `.env`:

```dotenv
APP_URL=https://shop.example

PAYGATE_USDC_WALLET=0xYourPolygonUsdcWallet      # card payouts (USDC on Polygon)
PAYGATE_EVM_WALLET=0xYourEvmWallet               # crypto: ETH, USDT, USDC on EVM networks
PAYGATE_BTC_WALLET=bc1qYourBitcoinWallet
PAYGATE_SOLANA_WALLET=YourSolanaWallet
# PAYGATE_LTC_WALLET, PAYGATE_TRC20_WALLET, PAYGATE_BCH_WALLET, PAYGATE_DOGE_WALLET,
# PAYGATE_XMR_WALLET, PAYGATE_ZEC_WALLET, PAYGATE_TON_WALLET
```

Use self-custodial wallets you control. Never use exchange deposit addresses.

## Card payments

Name your callback route, then create a wallet for the order and send the customer to the payment page:

```php
use PayGate\Laravel\Facades\PayGate;

// routes/web.php
Route::get('/paygate/callback', PayGateCallbackController::class)
    ->name('paygate.callback')
    ->middleware('paygate.callback:order');

// In your checkout controller
$callback = paygate_callback_url('paygate.callback', ['order' => $order->id]);

$wallet = PayGate::card()->createWallet(config('paygate.wallets.usdc_polygon'), $callback);

$order->update([
    'expected_usd' => PayGate::card()->convertToUsd($order->total, $order->currency)['value'],
]);

return redirect()->away(
    PayGate::card()->paymentUrl($wallet, $order->total, $order->email, $order->currency)
);
```

`paymentUrl()` opens PayGate.to's hosted page, which shows every provider that works for the customer's country, amount and currency. Pass a provider id as the fifth argument (for example `'wert'`) to send the customer straight to one provider; `PayGate::card()->providers()` lists them.

## Crypto on the hosted page

The customer picks any coin of the networks you accept:

```php
$wallets = array_filter(config('paygate.wallets'), fn ($wallet, $network) => $network !== 'usdc_polygon' && $wallet, ARRAY_FILTER_USE_BOTH);

$hosted = PayGate::crypto()->createHostedWallet(
    $wallets,
    $order->total,
    $order->currency,
    paygate_callback_url('paygate.callback', ['order' => $order->id])
);

return redirect()->away(PayGate::crypto()->hostedPaymentUrl($hosted, true /* customer pays the network fee */));
```

To show an address and QR code on your own page instead, use `PayGate::crypto()->createWallet()`, `convert()` and `qrCode()`; see the [SDK guide](https://github.com/paygate-to/paygate-php#crypto-payments-on-your-page).

## Handling the callback

The `paygate.callback` middleware runs before your controller and answers **403** unless:

1. the `X-PayGate-Signature` header is PayGate.to's RSA signature over `APP_URL` + the request path + the query string exactly as received; and
2. with `paygate.callback:order`, the `order` parameter carries the token added by `paygate_callback_url()`, so nobody can point a payment at another order.

The parsed payment data is then in `$request->attributes->get('paygate')`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PayGate\Crypto\Coin;

final class PayGateCallbackController
{
    public function __invoke(Request $request)
    {
        /** @var \PayGate\Callback\CallbackData $data */
        $data = $request->attributes->get('paygate');

        if ($data->isPending()) {
            return response('ok');                       // seen, not yet confirmed
        }

        DB::transaction(function () use ($data) {
            $order = Order::lockForUpdate()->findOrFail($data->get('order'));

            // Callbacks can repeat: credit each blockchain transaction once
            // (a unique index on payments.txid_in makes this safe under concurrency).
            if ($order->payments()->where('txid_in', $data->getTxidIn())->exists()) {
                return;
            }
            $order->payments()->create([
                'txid_in' => $data->getTxidIn(),
                'coin' => $data->getCoin(),
                'amount' => $data->getValueCoin(),
            ]);

            // Release the goods only when the right coin and enough of it arrived.
            $paid = $order->method === 'card'
                ? preg_match('/_(usdc|usdt)$/', (string) $data->getCoin()) && (float) $data->getValueCoin() >= 0.60 * (float) $order->expected_usd
                : (float) $data->getValueCoin() >= 0.98 * (float) PayGate::crypto()->convert(Coin::id($data->getCoin()), $order->total, $order->currency)['value'];

            $order->update(['status' => $paid ? 'paid' : 'needs_review']);
        });

        return response('ok');
    }
}
```

Each check matters:

- **Signature**: proves PayGate.to sent the request and that nothing in it was changed.
- **Order token**: anyone can create a wallet whose callback names one of your orders. The token proves your app issued that callback URL.
- **Transaction id**: PayGate.to repeats a callback until you answer `ok`, and two deliveries can arrive at the same time.
- **Coin and amount**: the customer can see and edit the payment link, and any token can be sent to a crypto address. Card payments arrive in USDC after the provider's fee; the official plugins accept 60% of the USD total or more.
- **Payout address**: callback URLs end up in web server logs. With a leaked URL, someone could create a wallet that pays *them*. `$data->getPayoutFor(config('paygate.wallets.usdc_polygon'))` returns what reached your wallet (null if nothing did). Expect it to be at least 90% of the amount forwarded.

`$data` also has `getTxidOut()`, `getAddressIn()`, `getValueForwardedCoin()`, `getAddressOut()` and `getPayouts()`: see [Payment data](https://github.com/paygate-to/paygate-php#payment-data).

Callback routes are GET requests, so Laravel's CSRF protection does not apply to them. Do not put PayGate.to's own parameter names (`value_coin`, `coin`, `txid_in`, `txid_out`, `address_in`, `value_forwarded_coin`, `address_out`, `pending`) in your callback parameters; the helper refuses them.

## Configuration

`config/paygate.php` (all values can come from `.env`):

| Key | `.env` | Default | Meaning |
|---|---|---|---|
| `api_domain` | `PAYGATE_API_DOMAIN` | `api.paygate.to` | Your free dedicated API endpoint ([guide](https://paygate.to/white-label-api-custom-domain-guide/)). |
| `checkout_domain` | `PAYGATE_CHECKOUT_DOMAIN` | `checkout.paygate.to` | Your white-label checkout domain. |
| `wallets.*` | `PAYGATE_*_WALLET` | empty | Payout wallets: `usdc_polygon` for cards, one per network for crypto. |
| `callback_secret` | `PAYGATE_CALLBACK_SECRET` | derived from `APP_KEY` | Secret for order tokens. Changing it (or `APP_KEY`) invalidates callback URLs of unpaid orders. |
| `public_keys` | – | `[]` | Extra PayGate.to public keys, see below. |
| `timeout` | `PAYGATE_TIMEOUT` | `30` | API timeout in seconds. |
| `retries` | `PAYGATE_RETRIES` | `1` | Retries for safe API reads. |

The client is a singleton: inject `PayGate\Client` or use the `PayGate` facade (`PayGate::card()`, `PayGate::crypto()`, `PayGate::callbacks()`).

## Key rotation

The current PayGate.to signing key (`v1`) is bundled with the SDK. `X-PayGate-Key-Id` names the key that signed each callback. When PayGate.to publishes a new key, add it before updating the package, as PEM text or a file path:

```php
'public_keys' => [
    'v2' => storage_path('paygate/paygate_callback_public_v2.pem'),
],
```

Callbacks with an unknown key id are refused.

## Testing

```bash
composer install
composer test
```

The tests boot a small Laravel app, sign callbacks with a key pair generated for the run, and check the middleware: genuine callbacks pass; unsigned, changed, re-addressed and other-host callbacks are refused; and a value planted in your URL never overrides PayGate.to's.

To test your own callback route, sign the full URL with your own test key pair and add the public key under `public_keys` in your testing config only.

## Security notes

- Keep `APP_URL` set to your public https address. The signature is checked against it, never against the `Host` header.
- Answer `ok` only after you have stored the payment.
- Do not log full callback URLs: they contain order tokens.

## License

MIT. See [LICENSE](LICENSE).
