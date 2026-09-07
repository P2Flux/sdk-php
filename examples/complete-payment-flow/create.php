<?php

declare(strict_types=1);

/**
 * Step 1 - the merchant server creates the order and the payment intent, then hands the buyer to
 * the hosted checkout.
 *
 * The intent is created HERE, on the server. A browser must never be able to choose the recipient
 * or the amount.
 */

require __DIR__ . '/bootstrap.php';

use P2Flux\P2FluxException;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

$amount = config('DEMO_AMOUNT', '12.50');

try {
    $payment = p2flux()->createPayment([
        'recipient' => config('P2FLUX_RECIPIENT'),   // YOUR payout wallet
        'amount' => $amount,
    ]);
} catch (P2FluxException $e) {
    http_response_code(502);
    exit('P2Flux refused the request: ' . htmlspecialchars($e->status, ENT_QUOTES));
}

// Your own reference is what everything else hangs off. Store the intent beside it: verification
// and recovery both need it, and recovery works long after the intent expired.
$order = [
    'id' => bin2hex(random_bytes(8)),
    'amount' => $amount,
    'status' => 'pending',
    'intent' => $payment['intent'],
    'reference' => $payment['reference'] ?? null,
    'tx_hash' => null,
    'created_at' => gmdate('c'),
];
saveOrder($order);

$checkout = checkoutUrl() . '/#/pay/' . rawurlencode($order['intent']);

?><!doctype html>
<meta charset="utf-8">
<title>Pay order <?= htmlspecialchars($order['id'], ENT_QUOTES) ?></title>
<h1>Order <?= htmlspecialchars($order['id'], ENT_QUOTES) ?></h1>
<p>Amount: <?= htmlspecialchars($order['amount'], ENT_QUOTES) ?> USDC</p>
<p id="state">Opening the checkout…</p>
<p><a id="manual" href="<?= htmlspecialchars($checkout, ENT_QUOTES) ?>" target="_blank" rel="noopener">Open the checkout</a></p>
<p><a href="status.php?order=<?= urlencode($order['id']) ?>">Order status (server truth)</a></p>

<script>
// Step 2 - the buyer pays in the hosted checkout, which reports back by postMessage.
//
// EVERYTHING BELOW IS A CLAIM. The page cannot mark anything paid; all it does is hand the claim to
// the server, which verifies it against the chain.
const ORDER    = <?= json_encode($order['id']) ?>;
const CHECKOUT = <?= json_encode(checkoutUrl()) ?>;
const ORIGIN   = new URL(CHECKOUT).origin;
const state    = document.getElementById('state');

const win = window.open(<?= json_encode($checkout) ?>, 'p2flux', 'width=460,height=680');
state.textContent = win ? 'Complete the payment in the checkout window.'
                        : 'Popup blocked - use the link below.';

addEventListener('message', (event) => {
    if (event.origin !== ORIGIN) return;                 // always check the origin

    if (event.data?.type === 'p2flux.ready' && win) {
        win.postMessage({ type: 'p2flux.hello' }, ORIGIN);   // handshake the checkout waits for
    }

    if (event.data?.type === 'p2flux.payment.completed') {
        state.textContent = 'Verifying on the server…';
        verify(event.data.tx_hash, event.data.settlement_receipt);
    }
});

async function verify(txHash, settlementReceipt) {
    const res = await fetch('verify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order: ORDER, tx_hash: txHash, settlement_receipt: settlementReceipt }),
    });
    const body = await res.json();

    // The server's answer, not the browser's, decides what the buyer is told.
    if (body.status === 'paid')            state.textContent = 'Paid. Thank you!';
    else if (body.status === 'confirming') { state.textContent = 'Confirming on chain…'; setTimeout(() => verify(txHash, settlementReceipt), 4000); }
    else                                   state.textContent = 'Not settled: ' + (body.code || body.status);
}
</script>
