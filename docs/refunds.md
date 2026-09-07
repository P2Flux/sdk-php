# Refunds

Runnable: [`examples/refund.php`](../examples/refund.php).


A refund is a plain USDC transfer from the merchant's own wallet to the wallet that paid. P2Flux
derives who and how much from the original settlement, and verifies the transfer afterwards; it
never holds the money, charges no fee and returns none of its original commission.

```php
// 0. Enforce one-refund-per-payment BEFORE preparing: P2Flux keeps no refund history, so preparing
//    twice happily prepares two valid refunds. Reserve the order row atomically first.

// 1. Prepare. Amounts here are micro-USDC integer strings: 2.50 USDC is "2500000".
$prep = $p2flux->prepareRefund(['intent' => $intent, 'tx_hash' => $settlementHash], '2500000');
// For a renewal: ['subscription' => $capability, 'tx_hash' => $hash, 'period_index' => 3]

// 2. The merchant's wallet sends the transfer, from the hosted page:
$url = 'https://pay-test.p2flux.com/#/refund/' . rawurlencode($prep['refund_token']);
// The checkout posts `p2flux.refund.sent { tx_hash }` and `p2flux.refund.confirmed { tx_hash }`.

// 3. Verify against the ORIGINAL settlement, not the prepare token - so this works days later.
$verdict = $p2flux->verifyRefund(['intent' => $intent, 'tx_hash' => $settlementHash], '2500000', $refundHash);
if (($verdict['status'] ?? '') === 'REFUNDED') {
    $order->markRefunded($verdict['refund_tx_hash']);
} elseif (($verdict['error'] ?? $verdict['code'] ?? '') === 'REFUND_CONFIRMING') {
    // On chain, not settled. Poll the SAME hash. Never send another transfer.
}
```

Record the refund in your own system only after `REFUNDED`. A store that books the refund first and
verifies later has a refunded order and, sometimes, no refund.

`resolveRefund($refundToken)` is the browser-side read the hosted refund page uses; a merchant
server reconciles with `verifyRefund()`, which needs no token.

## Next

- [Errors and retries](errors.md)
