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
- **AI agents.** `P2Flux\Paywall` charges AI agents per request over x402. Live on Base mainnet.

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

**Webhooks are optional and never decide.** The browser message (or a webhook) says what happened; your
server's verdict is what decides.

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

### Optional webhooks

Add `notify_url` (a public https URL) to `createPayment` or `createPaymentLink` and P2Flux POSTs a
`payment.paid` event there once the payment is verified on chain (subscription links:
`subscription.charged` and `subscription.updated`), retrying for about 18 hours. The notification
carries no secret - it is a hint - so confirm it before fulfilling:

```php
// your notify endpoint: 2xx when this returns, 5xx when it throws (not final yet - it is sent again)
$result = $p2flux->handleNotification(file_get_contents('php://input'), ['reference' => $order->p2flux_reference]);
if ($result['confirmed']) { markOrderPaid($order, $result['dedupe_key']); } // idempotent on dedupe_key, never on the event id
```

`handleNotification` asks the API again (`verifyPayment`, or the link's manage view for
subscriptions with `link_id` and `manage`) and checks the answer is for YOUR order. If the create
response has no `notify_url`, the payment is not watched (the service is at capacity): keep polling,
exactly as without webhooks.

**Signed webhooks.** Every delivery carries `p2flux-signature: t=<unix>,kid=<id>,v1=<Ed25519>` over
`{$t}.{$rawBody}`, signed with P2Flux's key (public, in `capabilities()['webhook_signing_keys']`; no
shared secret). Check it with the raw body, before decoding (ext-sodium):

```php
$event = $p2flux->verifyWebhook($rawBody, $_SERVER['HTTP_P2FLUX_SIGNATURE'] ?? ''); // throws WEBHOOK_SIGNATURE_INVALID / WEBHOOK_TOO_OLD
// or in one step: handleNotification($rawBody, ['reference' => $ref, 'signature' => $_SERVER['HTTP_P2FLUX_SIGNATURE'] ?? null])
```

A signature proves P2Flux sent it, not that money moved: `handleNotification` still asks the API.

Answer 4xx/5xx when the reason starts with `WEBHOOK_` (`WEBHOOK_SIGNATURE_INVALID`, `WEBHOOK_TOO_OLD`),
never 2xx: the event then stays pending, is sent again and shows in the delivery history. A request
without the header (an API before the release that signs, 2026-11) passes `signature` null (or leaves it
out) and is checked unsigned - accepted only because the API's verdict (verifyPayment()) decides, never
the body.

**Delivery history.** `webhookDeliveries(['intent' => ...])` (or `['manage' => ...]` for a link) lists
each event with every attempt's time, HTTP status (0 = no answer) and duration, kept 7 days;
`redeliverWebhook($token, $eventId)` sends a pending one now (at most 3 times per event per hour).

### Your own order number: `merchant_ref`

Pass `merchant_ref` (ASCII letters, digits and `. _ : / # -`, 1-64 characters) to `createPayment`,
`createPaymentLink`, `createSubscription` or `charge($ref, $merchantRef)`; it comes back on verify,
recover, link and subscription status, refunds and confirmed notifications. Send the same `merchant_ref` when retrying a
charge: a charge's ref is echoed from the request (stateless), so a retry with another ref echoes that one. It is signed, not
encrypted - the buyer can read it - so never personal data. A payment's on-chain `reference` then
commits to it, provable without P2Flux - proof the payment was created with that `merchant_ref`, not who
created it (anyone can create a payment to any wallet with any `merchant_ref`). Whose order it is comes
from your own records (the intent or reference you stored) or `merchant_ref_verified` true (the creator
proved control of the receiving wallet with a merchant token or partner key):

```php
$payment = $p2flux->createPayment(['recipient' => $wallet, 'amount' => '25.00', 'merchant_ref' => 'INV-1042']);
P2FluxClient::checkMerchantRef($payment['reference'], $payment['reference_salt'], 'INV-1042'); // true
```

### Accounting statement

Every payment, subscription charge and probable refund of a wallet, read from the chain over public
JSON-RPC (no API, no key), as rows or CSV for Xero or QuickBooks - the same rows as the JS SDK:

```php
$rows = P2Flux\Statement::statement(['wallet' => $wallet, 'from' => strtotime('2026-10-01'), 'to' => strtotime('2026-11-01'), 'intents' => $intents]);
file_put_contents('october.csv', P2Flux\Statement::toCsv($rows, 'xero')); // 'generic' | 'xero' | 'quickbooks'
```

`intents` (optional, decoded locally) fill `merchant_ref`. A refund is marked `refund?`: a transfer
back to a payer after their payment and no larger than what is left of it (a payment is refunded at most
up to its gross; an exact match is preferred). The payment may be up to `lookback_days` (default 90)
before `from`: a September payment refunded in October shows on the October statement as the refund
only. The look-back never reads below the contracts' deploy block, and a public RPC that no longer keeps
its oldest blocks shortens it (a refund of an older payment is then not listed) instead of failing. Dates
map to blocks by the chain's own timestamps - no block time is assumed.

`'currencies' => ['EUR', 'GBP']` adds `value_usd` (USDC booked as USD 1:1), `value_eur`, `value_gbp`
(the row's `net`, 2 decimals) and `rate_date` at the ECB euro reference rate of the row's day (a weekend
or holiday takes the last published day). The rates come from the API's `/v1/rates` (`api_url`, default
production; only dates are sent) - also as `$client->rates(['from' => ..., 'to' => ...])`. The generic
CSV gets the extra columns; Xero and QuickBooks keep their layout (amount in USD, rounded to cents half away from zero; the
exact 6-decimal gross, fees and net are in the description) and name EUR/GBP in the
description.

### Redirect checkout: `return_url` and `checkout_url`

Prefer sending the buyer to P2Flux's page and getting them back over opening a window? Every create
answer carries `checkout_url` (the hosted page for that payment or link). Pass `return_url` and, after
paying, the page shows a button back to your site and, for a payment it was not opened as a window
for, goes back by itself (links: the button only):

```php
$payment = $p2flux->createPayment(['recipient' => $wallet, 'amount' => '25.00', 'return_url' => 'https://shop.example/order/42']);
header('Location: ' . $payment['checkout_url']);
// the buyer comes back to https://shop.example/order/42?p2flux_reference=0x…&p2flux_tx=0x…&p2flux_status=paid
```

The return is a convenience, never proof: confirm with `verifyPayment()` (or a webhook) before
fulfilling. `p2flux_status=confirming` means sent, not yet deep enough - keep checking.


### Higher limits: `merchantToken`

Many shops behind one server address share its per-IP limits. Prove your receiving wallet once and
requests paying that wallet are counted per wallet instead (higher, still bounded): sign
`P2FluxClient::merchantTokenMessage($chainId, $wallet, $issuedAt, $apiUrl)` with that wallet (`personal_sign`; a
Safe works too), exchange it with `createMerchantToken(['wallet' => ..., 'signature' => ..., 'issued_at'
=> ...])`, and pass the returned `merchant_token` as the `merchantToken` option. The token lasts 30
days, moves no money and opens no data. Confirmed partners pass `partnerKey` instead.

### Private payments with Hinkal: `private_payment`

On Base, a payment or a `once` / `reusable` link (not a subscription) can also offer **"Pay privately with Hinkal"**: the buyer pays
from their Hinkal private balance and their own wallet does not appear on chain. You receive the same
amount, minus the same fee; the buyer pays Hinkal's fee on top. Native gas only.

```php
$payment = $p2flux->createPayment(['recipient' => $wallet, 'amount' => '25.00', 'private_payment' => 'hinkal']);
```

Such a payment cannot be refunded automatically: on chain the payer is Hinkal's contract. A buyer can
pay any payment through Hinkal's own app, with or without this option, so check every verification:
`$verdict['accounting']['refundable'] === false` (and `screening` `unknown`) means refund it by hand,
to an address the buyer gives you. [Private payments](https://p2flux.com/docs/payments.html#private)

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

## Payment links

No server needed: create a link once and send it by e-mail, chat or QR code.

```php
$link = $p2flux->createPaymentLink([
    'kind' => 'once', // 'reusable' for a fixed price, 'subscription' with 'period' for a plan
    'recipient' => getenv('P2FLUX_RECIPIENT'),
    'amount' => '120.00',
    'label' => 'Invoice 2026-041',
]);
$forBuyer = $p2flux->checkoutLink('link', $link['link']);
$forYou = $p2flux->checkoutLink('links', $link['manage']); // private: who paid, every payment
```

An invoice link can be paid once - the contract itself refuses a second payment. Subscription links
are collected by P2Flux every period. See [Payment links](docs/payment-links.md).

## Charge AI agents (x402 paywall)

AI agents pay for a page or an API route in USDC, per request, with the open x402 standard. You say
who is paid and how much; P2Flux builds what the agent signs and settles what it sends — before you
serve.

```php
use P2Flux\Paywall;

$paywall = new Paywall($p2flux, ['recipient' => '0xYourWallet', 'price' => '0.05']);
$result = $paywall->guard($_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? null, $currentUrl, $_SERVER['HTTP_USER_AGENT'] ?? null);

foreach ($result['headers'] as $name => $value) { header("$name: $value"); }
if (!$result['allow']) {
    http_response_code($result['status']);      // 402 with the price, or 503
    echo json_encode($result['body']);
    exit;
}
// serve the content - once per payment
```

- One payment serves one response; the same payment sent again is refused.
- Agents pay per request (P2Flux keeps 1%, at least 0.003 USDC) or from a prepaid balance with no
  transaction per request (3%). `'prepaid' => false` offers pay-per-request only.
- `'agentsOnly' => true` charges AI agents and programs only; browsers and search engines pass free.
- Pass `cacheGet` / `cacheSet` (APCu, Redis, your framework's cache) to fetch the requirement once
  an hour instead of per request.
- Laravel: `p2flux/laravel` ships this as the `p2flux.paywall` middleware.

**Usage pricing** - when the cost is known only after the work. The agent signs for at most
`$maxPrice`; you charge what it cost (at least 0.01):

```php
$result = $paywall->usage($_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? null, $currentUrl, '1', function () {
    $rows = run_query();
    return ['amount' => number_format(count($rows) * 0.001, 6, '.', ''), 'value' => $rows];
});
if (!$result['allow']) { /* send $result['status'], $result['headers'], json $result['body'] */ }
// send $result['headers'] and $result['value']
```

The work runs only after P2Flux confirmed the payment will settle; if the settlement then fails, the
value is not returned.

See [`examples/paywall.php`](examples/paywall.php). Every option, the cache, the result shape and the
test-to-live checklist: [Charge AI agents](docs/paywall.md).

## Framework examples

The SDK is a plain class with no framework ties — bind it once and inject it.

- [Laravel](docs/frameworks/laravel.md) — container binding, injected controller, scheduler command
- [Symfony](docs/frameworks/symfony.md) — service definition, autowired controller, console command

There is no Symfony bundle, and none is needed. For Laravel, the optional
[`p2flux/laravel`](https://github.com/P2Flux/laravel) package adds the binding, config and the
`p2flux.paywall` middleware.

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
| [Payment links](docs/payment-links.md) | Invoices, fixed prices and subscriptions sent as a URL |
| [Call and result contract](docs/protocol-contract.md) | All 27 operations in one table |
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

PHP 8.1+ (64-bit) with `ext-json` and `ext-sodium` (bundled with PHP since 7.2; signed webhooks). `ext-curl` is used by the default transport only; pass your own
`transport` (WordPress's `wp_remote_post`, Guzzle, Symfony HttpClient) and it is never loaded.

## License

MIT.
