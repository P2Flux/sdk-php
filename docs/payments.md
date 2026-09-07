# One-time payments

```
your server           createPayment()            -> intent
buyer's browser       <checkout>/#/pay/<intent>  -> wallet sends the transaction
buyer's browser       postMessage to your page   -> a CLAIM: tx_hash, settlement_receipt
your server           verifyPayment()            -> the verdict that marks the order paid
```

Runnable: [`examples/create-payment.php`](../examples/create-payment.php),
[`examples/verify-payment.php`](../examples/verify-payment.php).

## Create the intent

```php
// 1. Mint the intent. Store it on the order: you will need it to verify, and to recover.
$payment = $p2flux->createPayment(['recipient' => $merchantWallet, 'amount' => '12.50']);
$order->p2flux_intent = $payment['intent'];

// 2. Send the buyer to the hosted checkout. The intent rides in the URL FRAGMENT, which never
//    reaches a server log.
$url = 'https://pay.p2flux.com/#/pay/' . rawurlencode($payment['intent']);
```

`amount` is a decimal string in USDC. The API enforces a 0.01 USDC minimum. `recipient` is your
payout wallet, and it is what the buyer's signature is bound to — the settlement can pay nobody
else. Add `'gas_payment_mode' => 'payment_token'` to let a buyer pay
[without holding ETH](network-fee-in-usdc.md).

`resolvePayment($intent)` reads the authoritative display terms back from the intent, for an
integration that renders its own checkout.

## Verify server-side

There are **no webhooks**. Completion reaches you as a browser message from the hosted checkout,
`p2flux.payment.completed { tx_hash, settlement_receipt }`, and a browser message is a claim. Your
server's verdict is what marks the order paid.

```php
$verdict = $p2flux->verifyPayment($order->p2flux_intent, $txHash, $settlementReceipt);

if ($verdict['valid'] === true) {
    $order->markPaid($verdict['tx_hash']);              // block_number, reference, amount also present
} elseif (($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING') {
    // On chain, not deep enough yet. Poll the SAME hash. Never ask the buyer to pay again.
} else {
    // Not a settlement of this intent. $verdict['code'] says why.
}
```

`verifyPayment()` returns `['valid' => false, 'code' => ...]` with HTTP 200 for every rejection —
"this payment is not proven" is an answer, not an exception. Only transport failures throw.

The optional third argument, the settlement receipt the checkout couriered, lets the API answer a
repeat verification without re-reading the chain. A bad receipt silently falls back to the full
check, so it is always safe to pass whatever the browser handed you.

### What to trust

- **The browser message is a claim.** Never grant access, ship goods or mark an order paid on it.
- **Verification is idempotent; crediting is not.** Guard your own side: check whether the order is
  already paid before acting on a verdict, because a double-submitted success page verifies twice.
- **Keep the settlement receipt** with the order for a few minutes. A repeat verify answers instantly.
- **Keep every intent you ever minted** for an order. A transaction prepared while the intent was
  live can be broadcast much later, and the intent is the only thing that connects it to the order.

## When the claim never arrives

The popup closes, the tab crashes, the connection drops — the buyer paid and your page never heard.
`recoverPayment($intent)` finds the settling transaction from the intent alone, and it still works
long after the intent expired. See [Recovery](recovery.md).

## Next

- [The payment lifecycle](payment-flow.md) — the same flow with the browser half included
- [Paying the network fee in USDC](network-fee-in-usdc.md)
- [Recovery](recovery.md) — when the claim never arrives
- [Refunds](refunds.md) — a refund starts from the settlement this page produced
- [Errors and retries](errors.md)
