# P2Flux PHP SDK — call and result contract

The client is a thin wrapper over the P2Flux HTTP API: it normalizes result codes and nothing
else. Production API: `https://api.p2flux.com` (Base Mainnet, real money). Test API:
`https://api-test.p2flux.com` (Base Sepolia). Full protocol documentation lives at
[p2flux.com/docs](https://p2flux.com/docs/) and the canonical
[OpenAPI specification](https://p2flux.com/openapi.json) — this file only states what the PHP
client itself guarantees.

## Scope

This is the **merchant-server SDK**: it implements the complete server-side surface — creating
payment intents and subscriptions, verifying settlements, requesting recurring charges, recovery,
refunds, and cancellation preparation. The buyer-side wallet experience is the hosted checkout
(`https://pay.p2flux.com`), not an SDK. The JS SDK (`@p2flux/sdk`) covers only the renewal-job
subset; a JS backend uses direct REST calls for intent creation and verification.

## Calls

| Method | Notes |
|---|---|
| `createPayment(array $terms)` | `['recipient', 'amount']` → intent. API enforces a 0.01 USDC minimum. |
| `verifyPayment(string $intent, string $txHash, ?string $settlementReceipt = null)` | The trust boundary. A rejected payment is `['valid' => false, 'code' => …]` with HTTP 200 — never an exception. The optional settlement receipt (couriered from the checkout) lets the server answer without re-reading the chain; a bad one silently falls back to full verification. |
| `recoverPayment(string $intent)` | Finds a settlement whose tx hash was lost. |
| `createSubscription(array $terms)` | `['recipient', 'amount', 'period']` → setup token. |
| `charge(string $subscription)` | Returns a `ChargeResult`; `REFUND_CONFIRMING`/`PAYMENT_CONFIRMING`-class outcomes are results, not exceptions. |
| `status(string $subscription)` | Period, due-ness, allowance, revocation — read from chain. |
| `createCancellationSession(string $subscription)` | Cancel token for the hosted cancel page. |
| `prepareSubscriptionCancellation(string $subscription)` | Calldata for the buyer's own `revoke()`. |
| `prepareAllowanceRevocation()` | Calldata for the global allowance stop. |
| `prepareRefund(...)` / `verifyRefund(...)` | Merchant-sent refunds, verified by P2Flux. |

## Transport

Default transport is curl. A custom transport is a callable `fn(string $url, array $options)`
returning `[int $httpStatus, array $body]` — see the README for the WordPress `wp_remote_post`
example. `throwIfError()` throws `P2FluxException` only on HTTP ≥ 400, carrying the API's `error`
code and its recommended `action` (`WAIT`, `RETRY_LATER`, `CUSTOMER_ACTION_REQUIRED`,
`STOP_SUBSCRIPTION`, `INVALID_REQUEST`, `SUCCESS`).

## Result codes

The `ACTIONS` map in `P2FluxClient` is the complete list the client knows; anything unknown maps
to `RETRY_LATER`. The authoritative catalogue with per-code guidance is the
[errors page](https://p2flux.com/docs/errors.html).
