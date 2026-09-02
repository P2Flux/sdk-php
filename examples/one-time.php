<?php

declare(strict_types=1);

/**
 * A one-time payment, end to end: create the intent, hand the buyer to the hosted checkout,
 * verify the settlement, keep the receipt.
 *
 * Production is real USDC on Base Mainnet. Set P2FLUX_API_URL=https://api-test.p2flux.com
 * (Base Sepolia, faucet money) while integrating.
 *
 *   composer require p2flux/p2flux-php   # see README for the VCS repository block
 */

// In your project: require 'vendor/autoload.php'. Inside this repository the sources load directly.
require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';
require __DIR__ . '/../src/CurlTransport.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL') ?: 'https://api.p2flux.com']);

// 1. Create the intent when the buyer chooses to pay. The recipient is YOUR payout wallet.
$payment = $p2flux->createPayment([
    'recipient' => '0x1111111111111111111111111111111111111111', // example address - use your own
    'amount'    => '12.50',
]);

// 2. Store $payment['intent'] on your order row, then send the buyer to the hosted checkout.
//    The intent rides in the URL fragment, which never reaches a server log.
echo 'send buyer to https://pay.p2flux.com/#/pay/' . $payment['intent'] . PHP_EOL;

// 3. The checkout hands your success page the transaction hash. Verify it server-side -
//    the verdict, not the redirect, is what marks the order paid.
$txHashFromCheckout = '0x...';
$verdict = $p2flux->verifyPayment($payment['intent'], $txHashFromCheckout);

if ($verdict['valid'] === true) {
    // 4. Paid and settled. Keep the settlement receipt with the order for ~10 minutes: presenting
    //    it on a repeat verify (double-submitted success page, queue retry) answers instantly.
    echo 'paid in block ' . ($verdict['block_number'] ?? '?') . PHP_EOL;
    $receipt = $verdict['settlement_receipt'] ?? null;
} elseif (($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING') {
    // On chain but not settled to the required depth. Ask again in a few seconds - same hash.
    echo 'confirming, poll again shortly' . PHP_EOL;
} else {
    // A verdict about the chain: this transaction does not settle this intent.
    echo 'not a settlement of this payment: ' . ($verdict['code'] ?? '?') . PHP_EOL;
}

// Lost the hash entirely (closed popup, dead callback)? The intent alone can find it:
//   $recovered = $p2flux->recoverPayment($payment['intent']);
//   if ($recovered['found'] && $recovered['valid']) { markPaid($recovered['tx_hash']); }
