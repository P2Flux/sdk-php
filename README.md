# P2Flux PHP SDK

PHP client for the [P2Flux](https://p2flux.com) payments API: USDC payments and subscriptions on
Base, settled directly to your own wallet. P2Flux executes payments; your application keeps the
subscription lifecycle, so there is no scheduler and no state here.

```bash
composer require p2flux/sdk-php
```

PHP 8.1+, `ext-json`, **no runtime dependencies**. `ext-curl` is used only by the default transport
— a host that supplies its own HTTP client never loads it.

## Quickstart

```php
require 'vendor/autoload.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'timeout' => 30]);

// 1. Mint an intent and store it on the order.
$payment = $p2flux->createPayment(['recipient' => $merchantWallet, 'amount' => '12.50']);

// 2. Send the buyer to the hosted checkout. The intent rides in the URL fragment.
$url = 'https://pay.p2flux.com/#/pay/' . rawurlencode($payment['intent']);

// 3. The checkout hands your page a transaction hash. That is a claim - verify it server-side.
$verdict = $p2flux->verifyPayment($payment['intent'], $txHash);
if ($verdict['valid'] === true) {
    $order->markPaid($verdict['tx_hash']);
}
```

There are no webhooks. The browser message says what a wallet did; only `verifyPayment()` decides
anything. Lost the hash? `recoverPayment($intent)` finds the settlement from the intent alone.

## Configuration

**There is no API key.** V1 has no API authentication: a payment is bound to an exact recipient,
amount and period by the customer's own signature, and the contract refuses a second charge in a
period. What you configure is which deployment to talk to.

| Option | |
|---|---|
| `apiUrl` | Required. `https://api.p2flux.com` (Base Mainnet) or `https://api-test.p2flux.com` (Base Sepolia) |
| `timeout` | Seconds, default 60. A charge waits for on-chain confirmation. |
| `transport` | Optional callable replacing curl with your own HTTP client |

Tokens are bound to the deployment that issued them, so store the environment with every order and
use the stored one for every later call. See [Getting started](docs/getting-started.md).

## Paying the network fee in USDC

Live on Base Mainnet. With `'gas_payment_mode' => 'payment_token'`, a buyer holding USDC and **no
ETH** can pay by signing: P2Flux sends the transaction and pays the Base network fee in ETH, and the
buyer reimburses that cost in USDC in the same transaction. It is not gas-free — the fee is quoted
before the buyer signs, and they pay it in USDC instead of ETH. Your share still settles straight to
your wallet, and USDC is never converted.

```php
$caps = $p2flux->capabilities();                      // ask before offering it
$usdc = array_values(array_filter($caps['tokens'], fn ($t) => $t['symbol'] === 'USDC'))[0] ?? null;
$canSponsor = $usdc && in_array('payment_token', $usdc['gas_payment_modes'], true);

$payment = $p2flux->createPayment([
    'recipient' => $merchantWallet,
    'amount' => '12.50',
    'gas_payment_mode' => $canSponsor ? 'payment_token' : 'native',
]);
```

`verifyPayment()` then returns `gas_payment_mode` and an `accounting` block naming every figure in
USDC base units. Subscription signup, allowance repair and allowance removal take the same path from
the hosted checkout, with no change on your side. Details, limits and contract addresses:
[Paying the network fee in USDC](docs/network-fee-in-usdc.md).

## Subscriptions

P2Flux schedules nothing. Your renewal job decides a period is due and calls `charge()`; the
contract enforces one charge per period, so a retry after a timeout can never charge twice.

```php
$result = $p2flux->charge($capability);

if ($result->ok) {
    return; // CHARGED or ALREADY_CHARGED - this period is paid
}
match ($result->action) {
    'WAIT'                     => null,                   // confirming; the money moved
    'RETRY_LATER'              => $this->retryLater(),
    'CUSTOMER_ACTION_REQUIRED' => $this->emailCustomer(), // top up or re-approve
    'STOP_SUBSCRIPTION'        => $this->stopBilling(),   // revoked or expired; final
};
```

**`charge()` never throws on a payment outcome.** "The customer has no funds" is an answer, not an
error. Only transport-level surprises are exceptional, and those come back as `NETWORK_ERROR` /
`RETRY_LATER` rather than as a verdict — an unreachable API says nothing about whether the charge
landed. See [Subscriptions](docs/subscriptions.md).

## Error handling

Everything except `charge()` throws `P2Flux\P2FluxException` on HTTP 400 and above, carrying
`->status`, `->action` and the raw body. Verification verdicts and the two recovery calls answer
instead of throwing, because "not proven" and "not found yet" are answers.

```php
use P2Flux\P2FluxException;

try {
    $state = $p2flux->status($capability);
} catch (P2FluxException $e) {
    // Classify on $e->action - RETRY_LATER, CUSTOMER_ACTION_REQUIRED, STOP_SUBSCRIPTION,
    // INVALID_REQUEST - so a code this client has never seen still lands in the right branch.
}
```

Full catalogue, including `RATE_LIMITED`, the gas codes and the sponsorship codes:
[Errors and retries](docs/errors.md).

## Bring your own HTTP client

The `transport` option takes any callable, so a host framework supplies its own stack — WordPress's
`wp_remote_post`, Guzzle, Symfony HttpClient. It receives the absolute URL, the payload as an array
and the timeout in seconds, and must return `[int $httpStatus, array $decodedBody]` — the body
decoded, not the JSON string. Worked example:
[Getting started](docs/getting-started.md#your-own-http-client).

## Documentation

- [Getting started](docs/getting-started.md)
- [One-time payments](docs/payments.md)
- [Paying the network fee in USDC](docs/network-fee-in-usdc.md)
- [Subscriptions](docs/subscriptions.md)
- [Refunds](docs/refunds.md)
- [Errors and retries](docs/errors.md)
- [Call and result contract](docs/protocol-contract.md)
- Full protocol docs: [p2flux.com/docs](https://p2flux.com/docs/) · [OpenAPI](https://p2flux.com/openapi.json)

## Examples

Runnable, configured from the environment: [`examples/`](examples/)

| File | |
|---|---|
| [`create-payment.php`](examples/create-payment.php) | Intent → hosted checkout URL |
| [`verify-payment.php`](examples/verify-payment.php) | Server-side verification and recovery |
| [`network-fee-in-usdc.php`](examples/network-fee-in-usdc.php) | A buyer paying with USDC and no ETH |
| [`subscription.php`](examples/subscription.php) | Setup, charging, cancellation session |
| [`refund.php`](examples/refund.php) | Prepare, send from your wallet, verify |

```bash
composer install
P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/create-payment.php
```

## Scope

This SDK covers the complete public V1 merchant/server API — the same 18 operations as the JS SDK
(`@p2flux/sdk`): one-time payments (create/resolve/verify with settlement receipts), recovery,
subscription setup/resolve/finalize/charge/status, recurring settlement recovery, cancellation
sessions and preparation, allowance revocation and repair, and refunds. No raw REST calls are needed
for a normal integration. The buyer-side wallet experience is the hosted checkout, not an SDK.

**Parity is tested, not promised.** `tests/transport.php` holds the checked-in list of all 18 public
V1 merchant operations and fails if any stops being reachable through the client; the JS SDK and
P2Flux/core carry the same guard.

## Tests

```bash
composer test          # transport, examples and documentation checks - all offline
```

`tests/smoke.php` runs the same client against a live API and is driven by the integration suite in
the private P2Flux/core repository.

## License

MIT.
