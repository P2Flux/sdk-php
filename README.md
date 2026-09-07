# P2Flux PHP SDK

[![Packagist](https://img.shields.io/packagist/v/p2flux/sdk-php)](https://packagist.org/packages/p2flux/sdk-php)
[![PHP](https://img.shields.io/packagist/dependency-v/p2flux/sdk-php/php)](https://packagist.org/packages/p2flux/sdk-php)

```bash
composer require p2flux/sdk-php
```

PHP 8.1+, no runtime dependencies, no framework.

## What P2Flux does

P2Flux takes USDC payments on Base that settle **straight to your own wallet**. There is no custody,
no payout step and no account balance: the buyer's transaction pays you directly.

It executes payments; your application keeps everything else. No scheduler, no stored orders, no
retry loops — you already have those.

- **One-time payments.** Create an intent, send the buyer to the hosted checkout, verify server-side.
- **Subscriptions.** The customer signs one authorization; your renewal job calls `charge()` when a
  period is due. The contract allows one charge per period, so retries are safe.
- **Buyers with no ETH.** Optionally, the buyer pays the network fee in USDC instead of holding the
  chain's native currency.
- **Refunds.** A plain transfer from your wallet, verified by P2Flux, which never holds the money.

## Install

```bash
composer require p2flux/sdk-php
```

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `ext-json`; `ext-curl` only for the default transport |
| Dependencies | none |

## Five-minute payment

```php
require 'vendor/autoload.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api-test.p2flux.com', 'timeout' => 30]);

// 1. Mint an intent on your server and store it on the order.
$payment = $p2flux->createPayment(['recipient' => $merchantWallet, 'amount' => '12.50']);
$order->p2flux_intent = $payment['intent'];

// 2. Send the buyer to the hosted checkout.
$url = 'https://pay-test.p2flux.com/#/pay/' . rawurlencode($payment['intent']);

// 3. The checkout hands your page a transaction hash. Verify it server-side.
$verdict = $p2flux->verifyPayment($order->p2flux_intent, $txHash);
if ($verdict['valid'] === true) {
    $order->markPaid($verdict['tx_hash']);
}
```

`https://api-test.p2flux.com` is Base Sepolia with faucet USDC. Production is
`https://api.p2flux.com` with real money. **There is no API key** — a payment is bound to its
recipient and amount by the buyer's own signature.

## Hosted checkout flow

The buyer pays in P2Flux's hosted checkout, which reports back to the page that opened it:

```js
const url = `${CHECKOUT}/#/pay/${encodeURIComponent(intent)}`;
const win = window.open(url, 'p2flux', 'width=460,height=680');

addEventListener('message', (event) => {
    if (event.origin !== new URL(CHECKOUT).origin) return;

    if (event.data?.type === 'p2flux.ready') {
        win.postMessage({ type: 'p2flux.hello' }, new URL(CHECKOUT).origin);
    }

    if (event.data?.type === 'p2flux.payment.completed') {
        fetch('/orders/verify', {                   // hand it to your server; decide nothing here
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: ORDER_ID, tx_hash: event.data.tx_hash }),
        });
    }
});
```

The intent rides in the URL fragment, which browsers never send to a server or put in `Referer`.

## Verify before fulfilling

**P2Flux sends no webhooks.** The browser message says what a wallet did; your server's verdict is
what decides.

```php
$verdict = $p2flux->verifyPayment($intent, $txHash, $settlementReceipt);
```

| Verdict | Meaning |
|---|---|
| `valid: true` | Settled. Mark the order paid — once, under a lock. |
| `code: PAYMENT_CONFIRMING` | On chain, not deep enough. Poll the same hash; never ask the buyer to pay again. |
| any other `code` | This transaction does not settle this intent. |
| `P2FluxException` | Never reached a verdict. Unknown, not rejected: retry. |

Lost the hash entirely? `recoverPayment($intent)` finds the settlement from the intent alone.

Full walk-through: [The payment lifecycle](docs/payment-flow.md).

## Pay the network fee in USDC

A buyer holding USDC and **no ETH** can still pay. They sign a token authorization; P2Flux submits
the transaction and pays the Base network fee in ETH, and the buyer reimburses that exact cost in
USDC in the same transaction. The fee is real and quoted before they sign — they pay it in USDC
rather than in ETH, and USDC is never converted.

```php
$caps = $p2flux->capabilities();                       // ask before offering it
$usdc = array_values(array_filter($caps['tokens'], fn ($t) => $t['symbol'] === 'USDC'))[0] ?? null;
$canSponsor = $usdc && in_array('payment_token', $usdc['gas_payment_modes'], true);

$payment = $p2flux->createPayment([
    'recipient' => $merchantWallet,
    'amount' => '12.50',
    'gas_payment_mode' => $canSponsor ? 'payment_token' : 'native',
]);
```

`verifyPayment()` then returns `gas_payment_mode` and an `accounting` block naming every figure in
USDC base units. Subscription signup and allowance repair take the same path from the hosted
checkout. Details, limits and contract addresses:
[Paying the network fee in USDC](docs/network-fee-in-usdc.md).

## Subscriptions

P2Flux schedules nothing. Your renewal job decides a period is due:

```php
$result = $p2flux->charge($capability);

if ($result->ok) {
    return;                                   // CHARGED or ALREADY_CHARGED - the period is paid
}
match ($result->action) {
    'WAIT'                     => null,                   // confirming; the money moved
    'RETRY_LATER'              => $this->retryLater(),
    'CUSTOMER_ACTION_REQUIRED' => $this->emailCustomer(), // top up or re-approve
    'STOP_SUBSCRIPTION'        => $this->stopBilling(),   // revoked or expired; final
};
```

**`charge()` never throws on a payment outcome.** "The customer has no funds" is an answer, not an
error. Only transport-level surprises are exceptional, and an unreachable API says nothing about
whether the charge landed. See [Subscriptions](docs/subscriptions.md).

## Framework examples

The SDK is a plain class with no framework ties — bind it once and inject it.

- [Laravel](docs/frameworks/laravel.md) — container binding, injected controller, scheduler command
- [Symfony](docs/frameworks/symfony.md) — service definition, autowired controller, console command

There is no Laravel package and no Symfony bundle, and neither is needed.

## Documentation

| | |
|---|---|
| [Getting started](docs/getting-started.md) | Install, configuration, vocabulary, environments |
| [The payment lifecycle](docs/payment-flow.md) | The whole flow, and what may mark an order paid |
| [Payments](docs/payments.md) | Intents, checkout, verification |
| [Paying the network fee in USDC](docs/network-fee-in-usdc.md) | Buyers with no ETH |
| [Subscriptions](docs/subscriptions.md) | Setup, charging, allowance repair, cancellation |
| [Refunds](docs/refunds.md) | Merchant-sent, P2Flux-verified |
| [Recovery](docs/recovery.md) | Lost payments, lost charges, ambiguous requests |
| [Errors and retries](docs/errors.md) | Every public code, with a recipe per situation |
| [Laravel](docs/frameworks/laravel.md) · [Symfony](docs/frameworks/symfony.md) | Framework integration |
| [Testing](docs/testing.md) | Fake transport, canned responses, no crypto spent |
| [Production checklist](docs/production-checklist.md) | Before real money |
| [Call and result contract](docs/protocol-contract.md) | All 18 operations in one table |
| [Examples](examples/) | Runnable, one operation per file |

Full protocol docs: [p2flux.com/docs](https://p2flux.com/docs/) ·
[OpenAPI](https://p2flux.com/openapi.json)

## Examples

```bash
composer install
P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/create-payment.php
```

Every example reads its configuration from the environment and fails with the missing variable's
name. [`examples/complete-payment-flow/`](examples/complete-payment-flow/) is a runnable merchant
integration — order, checkout handshake, repeat-safe verification — against a canned API, so no
wallet or USDC is needed.

## Testing

Your own application never needs to spend crypto to be tested. The client takes a `transport`
callable, so a fake replaces HTTP entirely:

```php
$p2flux = new P2FluxClient([
    'apiUrl' => 'https://api.example',
    'transport' => fn (string $url, array $payload, int $timeout): array
        => [200, ['valid' => true, 'tx_hash' => '0x…']],
]);
```

Recipes for Laravel, Symfony and plain PHP: [Testing](docs/testing.md).

This repository's own suite is offline and runs in a second:

```bash
composer test        # transport, examples, complete flow, documentation
```

## The other official SDK

JavaScript and TypeScript: `npm install @p2flux/sdk` —
[npm](https://www.npmjs.com/package/@p2flux/sdk) ·
[GitHub](https://github.com/P2Flux/sdk-js). Same public operations, same semantics, same security
model. The two are released independently, so their version numbers differ.

## Requirements

PHP 8.1+ with `ext-json`. `ext-curl` is used by the default transport only; pass your own
`transport` (WordPress's `wp_remote_post`, Guzzle, Symfony HttpClient) and it is never loaded.

## License

MIT.
