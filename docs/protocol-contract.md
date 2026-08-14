# P2Flux SDKs

Thin clients over the HTTP API. They normalize result codes and nothing else — no scheduling, no
storage, no retry loops. **Your application owns the subscription lifecycle** (who the customer is,
when a renewal is due, what happens after a failure); P2Flux executes the payment when you say so.

| | |
|---|---|
| [`js/`](js/) | TypeScript/JavaScript, zero dependencies |
| [`php/`](php/) | PHP 8.1+, curl by default — for WordPress/WooCommerce and Laravel hosts |
| Python | interface sketched below, not implemented yet |

## The calls

| | |
|---|---|
| `createSubscription(terms)` | technical terms → setup token for the checkout URL fragment |
| `charge(ref)` | attempt this period's payment. Safe to retry |
| `status(ref)` | current state read from the chain, plus the signed `terms` — reconcile with this |
| `createPayment(terms)` | technical terms → one-time payment intent |
| `verifyPayment(intent, txHash)` | server-side proof a one-time payment landed. Never skip it |
| `createCancellationSession(ref)` | `p2s2` → short-lived cancel token that can go in a browser |
| `prepareSubscriptionCancellation(ref)` | calldata the **customer's wallet** sends to cancel one subscription |
| `prepareAllowanceRevocation()` | calldata for `approve(contract, 0)` — the customer's global stop |

The two `prepare*` calls return unsigned calldata. P2Flux cannot revoke wallet authority and does
not pretend to: only the payer's wallet can send those transactions.

`status()` echoes the signed `terms` (`payer`, `recipient`, `token`, `amount`, `period`, `start`,
`end`, `salt`). Check them against what you sold before activating anything: a capability can be
cryptographically valid and still be the *wrong* capability — someone else's cheaper plan, finalized
against your pending order. The `salt` is what distinguishes two setups whose price and period are
identical, and `createSubscription()` returns it so you can store it with the pending order.

`createCancellationSession()` exists so the capability never has to reach a browser. The cancel
token carries the authorization fields needed to build `revoke()` and **not** the customer's
signature, so it cannot be charged with; the contract still requires the payer's own wallet to send
the transaction. Open `<checkout>/#/cancel/<cancel_token>`.

The PHP client takes an optional `transport` callable, so a host application can route requests
through its own HTTP stack (`wp_remote_post`, Guzzle) or stub them in tests:

```php
new P2FluxClient(['apiUrl' => $url, 'transport' => fn($url, $payload, $timeout) => [$status, $body]]);
```

## Result handling

Every charge returns one status plus an `action` telling you what to do about it:

| status | action | meaning |
|---|---|---|
| `CHARGED` | `SUCCESS` | the money moved; `txHash` is present |
| `ALREADY_CHARGED` | `SUCCESS` | this period was already collected — **mark the renewal paid** |
| `NOT_DUE` | `RETRY_LATER` | you called before the period opened; `nextPeriodAt` says when |
| `INSUFFICIENT_BALANCE` | `CUSTOMER_ACTION_REQUIRED` | customer wallet is short |
| `INSUFFICIENT_ALLOWANCE` | `CUSTOMER_ACTION_REQUIRED` | customer removed or never gave the token allowance |
| `PERMISSION_REVOKED` | `STOP_SUBSCRIPTION` | customer revoked on-chain. Permanent |
| `SUBSCRIPTION_EXPIRED` | `STOP_SUBSCRIPTION` | past the authorization's end date |
| `INVALID_SUBSCRIPTION` | `INVALID_REQUEST` | the reference is malformed, forged or for another deployment |
| `RPC_ERROR` / `RELAYER_ERROR` / `TRANSACTION_REVERTED` / `INTERNAL_ERROR` | `RETRY_LATER` | operational, not the customer's fault |
| `GAS_TOO_HIGH` / `GAS_QUOTE_UNAVAILABLE` | `RETRY_LATER` | gas was too expensive or unpriceable; nothing was sent, nothing changed |
| `NETWORK_ERROR` | `RETRY_LATER` | the SDK could not reach P2Flux at all |

`ok` is true for `CHARGED` and `ALREADY_CHARGED`. Branch on `ok` first — treating
`ALREADY_CHARGED` as a failure is the classic integration bug, and it is exactly what a retry after
a timeout returns.

A charge waits for the transaction to confirm, so it can take tens of seconds; the SDK timeout
defaults to 60 s. If it times out anyway, the payment may still have landed - call again and read
`ALREADY_CHARGED`.

**Retry schedules are yours.** P2Flux reports the technical result; whether a short wallet is
retried in an hour, tomorrow, or after a dunning email is your business policy.

## JavaScript

```ts
import { createP2Flux } from '@p2flux/sdk'

const p2flux = createP2Flux({ apiUrl: process.env.P2FLUX_API_URL })

// Inside your existing renewal job:
const result = await p2flux.charge(subscription.p2fluxRef)

if (result.ok) {
  await markRenewalPaid(subscription, { txHash: result.txHash, period: result.periodIndex })
} else if (result.action === 'STOP_SUBSCRIPTION') {
  await cancelSubscription(subscription, result.status)
} else if (result.action === 'CUSTOMER_ACTION_REQUIRED') {
  await markPastDue(subscription, result.status) // your dunning flow decides what happens next
} else {
  await scheduleRetry(subscription) // RETRY_LATER
}
```

## PHP

```php
use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL')]);
$result = $p2flux->charge($subscription->p2flux_ref);

switch (true) {
    case $result->ok:                                    // CHARGED or ALREADY_CHARGED
        $subscription->markRenewalPaid($result->txHash);
        break;

    case $result->action === 'STOP_SUBSCRIPTION':        // revoked or expired
        $subscription->cancel($result->status);
        break;

    case $result->action === 'CUSTOMER_ACTION_REQUIRED': // funds or allowance
        $subscription->markPastDue($result->status);
        break;

    default:                                             // RETRY_LATER
        $subscription->scheduleRetry();
}
```

Install by path until it is on Packagist:

```json
{ "repositories": [{ "type": "path", "url": "../p2flux/sdk/php" }],
  "require": { "p2flux/p2flux-php": "*" } }
```

## Python (planned)

Same four methods, same normalization. Nothing here is language-specific, so the port is small:

```python
p2flux = P2Flux(api_url=os.environ["P2FLUX_API_URL"])
result = p2flux.charge(ref)          # -> ChargeResult(status, ok, already_paid, action, retryable, …)
if result.ok:
    mark_renewal_paid(result.tx_hash)
```

Implement it against the same result table; the reference behaviour is `sdk/js/index.ts`.

## Storing the reference

The `p2s2…` string is bearer authorization for charging that one subscription. Keep it in your
existing subscription record, server-side. Never put it in HTML, a URL, analytics or application
logs; encrypt at rest where your stack makes that practical. What a leak does and does not allow is
analysed in [`docs/phase5-findings.md`](../docs/phase5-findings.md).
