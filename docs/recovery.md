# Recovery

Two calls for the same shape of accident: the money moved and your system never heard. Both are pure
reads, both are idempotent, and both are safe to run from a cron.

- [`recoverPayment()`](#a-lost-one-time-payment) — a one-time payment whose transaction hash you lost
- [`recoverCharge()`](#a-lost-recurring-charge) — a recurring period that `ALREADY_CHARGED` proved was collected
- [After an ambiguous request](#after-an-ambiguous-request) — the timeout that could have gone either way

Runnable: [`examples/recover-payment.php`](../examples/recover-payment.php),
[`examples/recover-charge.php`](../examples/recover-charge.php).

## A lost one-time payment

The popup closes, the tab crashes, the connection drops — the buyer paid and your page never heard.
Give `recoverPayment()` the intent alone and it finds the settling transaction from the contract's
own logs:

```php
$found = $p2flux->recoverPayment($order->p2flux_intent);

if ($found['found'] && $found['valid']) {
    $order->markPaid($found['tx_hash']);
} elseif ($found['found']) {
    // Located but still confirming; $found['tx_hash'] names it. Poll that hash.
} else {
    // PAYMENT_NOT_FOUND as of $found['as_of_block']. Not a verdict: a slow wallet can still
    // settle. Ask again on your own schedule; never mint a second intent for the same order.
}
```

Pure reads and idempotent, so it is safe to run from a cron over every order you are unsure about.
It also works long after the intent expired: expiry stops a payment being **started** and never
makes an existing settlement unverifiable.

## A lost recurring charge

```php
$found = $p2flux->recoverCharge($capability, $periodIndex, ['attempted_at' => $attemptedAt]);
```

`ALREADY_CHARGED` proves a period was collected and names no transaction. Without the transaction a
paid period cannot be attributed to an order, audited, or refunded — both refund calls start from the
original settlement. `recoverCharge()` finds it.

What it guarantees:

- **The exact `SubscriptionCharged` event is the proof.** A settlement is returned only when the
  contract's own log names this subscription AND this period, and its payer, recipient and amount
  match the signed authorization.
- **The contract's period marker is not proof.** `lastChargedPeriodPlusOne` is monotonic, so a marker
  of 7 says period 6 was collected and says *nothing* about period 5. Skipped periods are ordinary:
  there is no catch-up billing, so a period that was never collected is a normal history.
- **`$periodIndex` is required and exact.** There is no "current period" form, because you are
  reconciling one specific collection — today, or a year from now — and the answer must not move
  under you. Take it from the charge result or from `status()`.
- **The hint only narrows the search.** `['attempted_at' => unix]` or `['block' => n]` is where your
  own records say you attempted the charge. It can never turn a miss into a hit, and omitting it is
  always safe. Persist your attempt times; they turn a search over a whole billing period into one
  log query.

| Result | Meaning |
|---|---|
| `found: true` + `tx_hash`, `block_number`, `payer`, `recipient`, `net_units`, `fee_units`, `network_fee_units`, `amount_units` | The settlement. Check `subscription_id`, `period_index`, `recipient` and `amount_units` against what you expected before you act on it. |
| `found: false`, `code: PAYMENT_NOT_FOUND`, `as_of_block` | No settlement for this period as of that block. Ordinary for a skipped period; a statement about one block height, never a permanent verdict. Returned, not thrown. |
| `PAYMENT_CONFIRMING` (409) | A settlement exists and is not deep enough to act on. `tx_hash` rides along; ask again about that same one. Returned, not thrown. |
| `RECOVERY_UNAVAILABLE` (503) | The search could not be completed within its bounded budget on this deployment. Retryable; throws. |
| `PAYMENT_RECOVERY_INCONSISTENT` (502) | A log exists and contradicts the signed terms. Rare and abnormal; throws. Never treat as a payment. |

### Long periods

A charge can land anywhere inside its period, and a period can be 366 days — about 15.8 million
blocks on Base, far more than one request may scan. The API bisects the contract's own marker over
historical state and reads one log range at the crossing block, so a yearly period costs about the
same as an hourly one. On an RPC provider that does not serve historical state it falls back to
scanning the period window under a bounded budget, and when even that cannot finish it answers
`RECOVERY_UNAVAILABLE` — never a wrong `found: true`.

## After an ambiguous request

A request that never returned a verdict — a timeout, a dropped connection, a worker killed
mid-call — tells you nothing about whether the operation happened. `P2FluxException` with
`NETWORK_ERROR` is exactly this case, and treating it as a failure is how a paid period gets charged
twice or a paid order gets cancelled.

The rule is the same everywhere: **recover first, then decide.**

| You were doing | After the ambiguous request |
|---|---|
| `verifyPayment()` | Retry it. It is a read; it changes nothing. |
| `createPayment()` | No intent means no payment. Safe to create one — but store the intent before the buyer leaves, so the next attempt does not orphan it. |
| `charge()` | Call it again. The contract allows one charge per period, so the answer is `ALREADY_CHARGED` if the first one landed. Then `recoverCharge()` for the hash. |
| `prepareRefund()` | Do NOT prepare again blindly. P2Flux keeps no refund history, so two prepares are two valid refunds. Check your own reservation first. |
| A charge whose response you lost | `recoverCharge($capability, $periodIndex)`. Found means collected; not found as of that block means not collected yet. |

## Next

- [The payment lifecycle](payment-flow.md) · [Payments](payments.md) · [Subscriptions](subscriptions.md)
- [Errors and retries](errors.md)
