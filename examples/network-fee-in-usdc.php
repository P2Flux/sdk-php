<?php

declare(strict_types=1);

/**
 * A one-time payment a buyer can complete holding USDC and no ETH.
 *
 * The buyer signs a token authorization instead of sending a transaction; P2Flux submits it and
 * pays the Base network fee in ETH, and the buyer pays that cost in USDC inside the same
 * transaction. Your share still settles directly to your wallet. USDC is never converted to ETH.
 *
 * Production is real USDC on Base Mainnet. Set P2FLUX_API_URL=https://api-test.p2flux.com
 * (Base Sepolia, faucet money) while integrating.
 */

// In your project: require 'vendor/autoload.php'. Inside this repository the sources load directly.
require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL') ?: 'https://api.p2flux.com']);

// 1. Ask what this deployment supports. Architectural possibility is not support: a token that
//    implements the right standards on a network P2Flux has not deployed to reports false, and
//    every request for it is refused with PAYMENT_TOKEN_GAS_UNSUPPORTED.
$caps = $p2flux->capabilities();
$usdc = null;
foreach ($caps['tokens'] as $token) {
    if ($token['symbol'] === 'USDC') {
        $usdc = $token;
        break;
    }
}
$noEthPath = $usdc !== null && in_array('payment_token', $usdc['gas_payment_modes'], true);

echo 'chain ' . $caps['chain_id'] . ' | network fee payable in USDC: ' . ($noEthPath ? 'yes' : 'no') . "\n";

// 2. Create the payment. Without the field nothing changes: the buyer sends the transaction and
//    pays the network fee in ETH, exactly as before. This is opt-in, per payment.
$payment = $p2flux->createPayment([
    'recipient' => '0x1111111111111111111111111111111111111111', // example - use your own wallet
    'amount' => '12.50',
    'gas_payment_mode' => $noEthPath ? 'payment_token' : 'native',
]);

// 3. The hosted checkout does the rest. It prices the network fee, shows the buyer the total before
//    anything is signed, re-checks the price at the moment they click, and asks them to confirm if
//    it moved. A wallet that can pay its own gas is offered the ordinary path instead.
echo 'send buyer to https://pay.p2flux.com/#/pay/' . $payment['intent'] . "\n";

// 4. Verify on your server, as always. The verdict now carries how it was paid and every figure.
$txHash = getenv('TX_HASH') ?: '0x'; // the checkout posts this to your page
$verdict = $p2flux->verifyPayment($payment['intent'], $txHash);

if (($verdict['valid'] ?? false) === true) {
    echo 'paid via ' . ($verdict['gas_payment_mode'] ?? 'native') . "\n";
    if (isset($verdict['accounting'])) {
        $a = $verdict['accounting']; // USDC base units: 1 USDC = 1000000
        echo 'buyer paid          ' . $a['buyer_total_units'] . "\n";   // price + quoted network fee only
        echo 'you receive         ' . $a['merchant_net_units'] . "\n";  // price - 1% - fixed 0.10 network fee
        echo 'P2Flux fee          ' . $a['payment_fee_units'] . "\n";
        echo 'fixed network fee   ' . $a['fixed_network_fee_units'] . "\n"; // merchant-funded, as on a renewal
        echo 'buyer network fee   ' . $a['network_fee_units'] . "\n";   // quoted before signing, charged exactly
    }
} elseif (($verdict['code'] ?? '') === 'RATE_LIMITED') {
    // Per buyer wallet: 10 sponsored transactions in any rolling hour, 20 in any rolling day, across
    // every merchant and operation. Nothing was spent - the buyer can retry later, or pay the network
    // fee with ETH if their wallet holds any. Your charge() calls are never counted against this.
    echo "buyer hit the per-wallet limit; retry later\n";
}
