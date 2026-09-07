# The payment lifecycle

Who does what, in order, and which step is allowed to decide that an order is paid.

```
1. your server        createPayment()                    -> intent
2. your page          open <checkout>/#/pay/<intent>     -> the buyer's wallet pays
3. the checkout       postMessage to your page           -> a CLAIM: tx_hash, settlement_receipt
4. your page          POST the claim to your server      -> the browser's job ends here
5. your server        verifyPayment(intent, tx_hash)     -> the verdict
6. your server        mark the order paid                -> only on a valid verdict
```

Steps 1, 5 and 6 are yours and happen on your server. Steps 2 to 4 happen in a browser you do not
control. **P2Flux sends no webhooks**, so there is no server-to-server callback to wait for: the
browser tells you where to look, and your own verification decides what it means.

## 1. Create the payment on the server

```php
$payment = $p2flux->createPayment(['recipient' => $merchantWallet, 'amount' => '12.50']);

$order->p2flux_intent = $payment['intent'];   // store it before the buyer leaves the page
$order->save();
```

The recipient and the amount are bound into the intent and into the buyer's signature. A browser
must never choose either, which is why this call belongs on your server.

**Keep your own reference.** Your order id is what everything hangs off; the intent is what P2Flux
recognizes. Store them together. Recovery needs the intent long after it expires, and expiry only
stops a payment being *started*.

## 2. Hand the buyer to the hosted checkout

The intent rides in the URL **fragment**, after the `#`. Browsers never send a fragment in the HTTP
request or the `Referer` header, so it stays out of server logs. It is still visible to anything
running in the page, so keep it out of client-side analytics.

```js
const url = `${CHECKOUT}/#/pay/${encodeURIComponent(intent)}`;
const win = window.open(url, 'p2flux', 'width=460,height=680');
```

## 3. The checkout reports back

| Message | Direction | Carries |
|---|---|---|
| `p2flux.ready` | checkout → your page | nothing; answer it with `p2flux.hello` |
| `p2flux.hello` | your page → checkout | the handshake that names your origin |
| `p2flux.payment.completed` | checkout → your page | `tx_hash`, `reference`, `settlement_receipt` |

```js
addEventListener('message', (event) => {
    if (event.origin !== new URL(CHECKOUT).origin) return;   // always check the origin

    if (event.data?.type === 'p2flux.ready') {
        win.postMessage({ type: 'p2flux.hello' }, new URL(CHECKOUT).origin);
    }

    if (event.data?.type === 'p2flux.payment.completed') {
        fetch('/orders/verify', {                            // your server decides, not this page
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order: ORDER_ID,
                tx_hash: event.data.tx_hash,
                settlement_receipt: event.data.settlement_receipt,
            }),
        });
    }
});
```

## 4. Browser success is not authoritative

`p2flux.payment.completed` says what a wallet did. It is a claim, and it is worth exactly as much as
anything else that arrives from a browser:

- Anyone can send your page a message. Checking `event.origin` is necessary and not sufficient.
- The window can die between the wallet returning a hash and your page hearing about it.
- A transaction hash is not a settlement. It may be another payment, another amount, or nothing yet.

So the claim is only ever an instruction to go and look. Nothing in the browser may fulfil an order,
grant access, or send goods.

## 5. Verify on the server

```php
$verdict = $p2flux->verifyPayment($order->p2flux_intent, $txHash, $settlementReceipt);
```

`verifyPayment()` re-reads the receipt on chain and checks it against the signed intent: the
recipient, the amount, the reference and the confirmation depth. It answers, rather than throwing:

| Verdict | Meaning |
|---|---|
| `valid: true` | Settled. This, and only this, may mark the order paid. |
| `code: PAYMENT_CONFIRMING` | On chain, not deep enough yet. Poll the same hash. Never ask the buyer to pay again. |
| any other `code` | This transaction does not settle this intent. The order stays unpaid. |
| `P2FluxException` | The request never reached a verdict. Unknown, not rejected: retry. |

The third argument is the settlement receipt the checkout couriered. It lets the API answer without
re-reading the chain; a missing or broken one silently falls back to the full check, so it is always
safe to pass whatever the browser handed you.

## 6. Mark the order paid, exactly once

Verification is safe to repeat. Fulfilment is not — and repeats are normal, not exceptional: a
double-submitted page, a retried fetch, a cron sweep and a manual "check again" button all arrive at
the same endpoint.

```php
if (($verdict['valid'] ?? false) !== true) {
    return;                                  // nothing changes
}

DB::transaction(function () use ($order, $verdict) {
    $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
    if ($fresh->status === 'paid') {
        return;                              // somebody already did this
    }
    $fresh->update([
        'status' => 'paid',
        'tx_hash' => $verdict['tx_hash'],
        'settlement_receipt' => $verdict['settlement_receipt'] ?? null,
    ]);
});
```

Re-check the status **inside** the lock. A unique constraint on the intent, or on the transaction
hash, gives the same guarantee if you would rather let the database refuse the second write.

## When the claim never arrives

The window died, the callback dropped, the buyer closed the tab. The money may still have moved.
`recoverPayment($intent)` finds the settlement from the intent alone — see
[Recovery](recovery.md). Run it from a cron over every order that has been pending for a while;
never mint a second intent for the same order.

## Working code

[`examples/complete-payment-flow/`](../examples/complete-payment-flow/) is this page as a runnable
demo, including the popup handshake and a repeat-safe verify endpoint. It runs against a canned API,
so no wallet or USDC is needed.

## Next

- [Payments](payments.md) · [Recovery](recovery.md) · [Production checklist](production-checklist.md)
- [Errors and retries](errors.md)
