# Errors and retries

Two shapes, and the difference matters:

- **`charge()` never throws on a payment outcome.** It returns a `ChargeResult`; "the customer has
  no funds" is an answer, not an error.
- **Everything else throws `P2Flux\P2FluxException`** on HTTP 400 and above, carrying
  `->status` (the protocol code), `->action` (what to do about it) and `->raw` (the API body).
  Verification verdicts, `recoverPayment()`, `recoverCharge()` not-found and confirming results are
  deliberate exceptions to that: they are answers, returned with HTTP 200 semantics.

**Classify on `action`, never on `status`.** A code this client has never seen maps to
`RETRY_LATER`, which is the safe default; a `match` on status alone breaks the day the API grows a
code.

## What to do


| You got | Do |
|---|---|
| `action: SUCCESS` with `txHash` | Mark paid. |
| `action: SUCCESS` without `txHash` (`ALREADY_CHARGED`) | Mark the period collected; `recoverCharge()` for the settlement before you attribute or refund. |
| `action: WAIT` (`CONFIRMING`, `PAYMENT_CONFIRMING`, `REFUND_CONFIRMING`) | Poll the same hash. Never a failure, never a second transaction. |
| `action: RETRY_LATER` | Nothing was spent. Retry the identical call later, on a bounded schedule. Honour `retry_after` on 429s. |
| `RATE_LIMITED` on a payment whose network fee is paid in USDC | The buyer wallet reached its sponsored-transaction limit (10 per rolling hour, 20 per rolling day, across all merchants). Nothing was spent. Retry after `retry_after`, or let the buyer pay the network fee with ETH. |
| `NOT_DUE` | Retry at `nextPeriodAt`, not before. |
| `action: CUSTOMER_ACTION_REQUIRED` | `INSUFFICIENT_BALANCE`: bounded dunning. `INSUFFICIENT_ALLOWANCE`: the approve flow; retrying alone cannot fix it. |
| `action: STOP_SUBSCRIPTION` | Stop billing. The customer must authorize again to resume. |
| `action: INVALID_REQUEST` | Do not retry. Fix the stored reference or the request. |
| `P2FluxException` `NETWORK_ERROR` | The request never reached the API. Retry; treat as unknown, not as declined. |

## Codes by action

Straight from the `ACTIONS` map in `P2FluxClient` — the complete list this client knows.

| `action` | Codes |
|---|---|
| `SUCCESS` | `CHARGED`, `ALREADY_CHARGED` |
| `WAIT` | `CONFIRMING`, `PAYMENT_CONFIRMING`, `REFUND_CONFIRMING`, `SPONSORSHIP_CONFIRMING` |
| `RETRY_LATER` | `PAYMENT_NOT_FOUND`, `PAYMENT_RECOVERY_INCONSISTENT`, `RECOVERY_UNAVAILABLE`, `NOT_DUE`, `RPC_ERROR`, `RELAYER_ERROR`, `TRANSACTION_REVERTED`, `INTERNAL_ERROR`, `NETWORK_ERROR`, `RATE_LIMITED`, `CONCURRENCY_LIMIT`, `GAS_TOO_HIGH`, `GAS_QUOTE_UNAVAILABLE`, `GAS_FEE_TOO_HIGH`, `RELAYER_TX_COST_TOO_HIGH`, `RELAYER_BUDGET_EXCEEDED`, `RELAYER_NOT_READY`, `RPC_BUSY`, `TRANSACTION_NOT_FOUND`, `PAYMENT_TOKEN_GAS_UNAVAILABLE`, `PAYMENT_TOKEN_GAS_LIMIT_EXCEEDED`, `SPONSORED_TRANSACTION_FAILED`, `SPONSORED_PERMIT_FAILED` |
| `CUSTOMER_ACTION_REQUIRED` | `INSUFFICIENT_BALANCE`, `INSUFFICIENT_ALLOWANCE`, `SIGNATURE_VALIDATION_TOO_EXPENSIVE`, `PAYMENT_TOKEN_GAS_QUOTE_EXPIRED`, `INSUFFICIENT_PAYMENT_TOKEN_FOR_GAS` |
| `STOP_SUBSCRIPTION` | `PERMISSION_REVOKED`, `SUBSCRIPTION_EXPIRED` |
| `INVALID_REQUEST` | `INVALID_REFUND_TOKEN`, `REFUND_TOKEN_EXPIRED`, `REFUND_AMOUNT_INVALID`, `REFUND_WRONG_MERCHANT`, `REFUND_TRANSACTION_MISMATCH`, `REFUND_ORIGINAL_PAYMENT_INVALID`, `INVALID_SUBSCRIPTION`, `INVALID_REQUEST`, `AMOUNT_OUT_OF_BOUNDS`, `PERIOD_OUT_OF_BOUNDS`, `INVALID_INTENT`, `INTENT_EXPIRED`, `INVALID_REFERENCE`, `INVALID_SETUP_TOKEN`, `SETUP_TOKEN_EXPIRED`, `INVALID_CANCEL_TOKEN`, `CANCEL_TOKEN_EXPIRED`, `TERMS_MISMATCH`, `PERMISSION_NOT_FOUND`, `INVALID_SIGNATURE`, `UNSUPPORTED_SIGNATURE_FORMAT`, `WRONG_SPENDER`, `WRONG_TOKEN`, `INVALID_EXTRA_DATA`, `PAYMENT_ALREADY_PROCESSED`, `PAYMENT_TOKEN_GAS_UNSUPPORTED`, `INVALID_GAS_QUOTE` |

The authoritative catalogue with per-code guidance is the
[errors page](https://p2flux.com/docs/errors.html).

## Codes worth knowing

| Code | Meaning |
|---|---|
| `RATE_LIMITED` | Infrastructure protection, refused before any money could move: per-IP and per-subscription on ordinary calls, and per buyer wallet (10 per rolling hour, 20 per rolling day) on transactions whose [network fee is paid in USDC](network-fee-in-usdc.md). Honour `retry_after`. |
| `CONCURRENCY_LIMIT` | Too many simultaneous requests for the same subject. Nothing was spent; repeat the identical call. |
| `PAYMENT_TOKEN_GAS_UNSUPPORTED` | This deployment does not sponsor that token or operation. Fall back to `native` — retrying cannot change a fact about a deployment. Check `capabilities()` before offering the option. |
| `PAYMENT_TOKEN_GAS_QUOTE_EXPIRED` | The quoted network fee is stale. The buyer requotes and signs again; only they can. |
| `INSUFFICIENT_PAYMENT_TOKEN_FOR_GAS` | The wallet cannot cover the price plus the quoted network fee. |
| `GAS_TOO_HIGH`, `GAS_FEE_TOO_HIGH`, `GAS_QUOTE_UNAVAILABLE` | Gas could not be priced, or moved above what the subscription authorized. Nothing was spent; the charge waits for better conditions. |
| `INSUFFICIENT_BALANCE` / `INSUFFICIENT_ALLOWANCE` | The customer must top up, or approve once. The signed authorization is intact in both cases. |
| `PERMISSION_REVOKED`, `SUBSCRIPTION_EXPIRED` | Final. Stop billing. |
| `INVALID_REQUEST`, `INVALID_SUBSCRIPTION`, `TERMS_MISMATCH`, `AMOUNT_OUT_OF_BOUNDS`, `PERIOD_OUT_OF_BOUNDS` | Deterministic. Fix the request; retrying returns the same answer forever. |
| `INTENT_EXPIRED`, `SETUP_TOKEN_EXPIRED`, `REFUND_TOKEN_EXPIRED`, `CANCEL_TOKEN_EXPIRED` | A dead token never becomes valid. Mint a new one; a settlement that already happened stays verifiable. |
| `NETWORK_ERROR` | The SDK's own code for a request that never reached the API. Unknown, not declined — retry. |

## Security

- **`p2s2` is a bearer capability.** Whoever holds it can ask P2Flux to collect the customer's next
  period. It can only ever pay the recipient the customer signed for, so it is not a theft
  primitive — but it is the customer's standing permission, and it belongs server-side only.
- Never log it, never put it in HTML, never put it in a URL. The one protocol-defined exception is
  the hosted checkout's own `#/cancel/`, `#/approve/` and `#/refund/` routes, which take the narrow
  session tokens minted for the purpose — never the capability itself.
- Encrypt it at rest. Redact every P2Flux token prefix from anything you log.
- **Browser messages are claims.** `p2flux.payment.completed` and `p2flux.subscription.created` say
  what a wallet did; only your server's `verifyPayment()` / `status()` / `charge()` decides anything.
- Store the environment with every order, and use the stored one for every later call.
- There is no API authentication in v1: a payment is bound to an exact recipient, amount and period
  by the customer's signature, and the contract refuses a second charge in a period. What that does
  not protect is the service itself, so the API rate-limits per IP and per subscription.

## Next

- [One-time payments](payments.md)
- [Subscriptions](subscriptions.md)
