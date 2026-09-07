<?php

declare(strict_types=1);

/**
 * A one-time payment a buyer can complete holding USDC and no ETH.
 *
 * With `gas_payment_mode => 'payment_token'` the buyer signs a token authorization instead of
 * sending a transaction. P2Flux submits it and pays the Base network fee in ETH; the buyer
 * reimburses that cost in USDC inside the same transaction. It is not free - the fee is quoted
 * before anything is signed, and USDC is never converted to ETH.
 *
 * Run it:
 *   P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/network-fee-in-usdc.php
 */

require __DIR__ . '/../vendor/autoload.php';

use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

function p2fluxEnv(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        fwrite(STDERR, "Missing required environment variable {$name}\n");
        exit(1);
    }

    return $value;
}

$checkoutUrl = p2fluxEnv('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com');
$recipient = p2fluxEnv('P2FLUX_RECIPIENT');
$p2flux = new P2FluxClient(['apiUrl' => p2fluxEnv('P2FLUX_API_URL', 'https://api.p2flux.com')]);

try {
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

    echo 'chain ' . $caps['chain_id'] . ' | network fee payable in USDC: ' . ($noEthPath ? 'yes' : 'no') . PHP_EOL;

    // 2. Create the payment. Without the field nothing changes: the buyer sends the transaction and
    //    pays the network fee in ETH, exactly as before. This is opt-in, per payment.
    $payment = $p2flux->createPayment([
        'recipient' => $recipient,
        'amount' => p2fluxEnv('P2FLUX_AMOUNT', '12.50'),
        'gas_payment_mode' => $noEthPath ? 'payment_token' : 'native',
    ]);

    // 3. The hosted checkout does the rest. It prices the network fee, shows the buyer the total
    //    before anything is signed, re-checks the price at the moment they click, and asks them to
    //    confirm if it moved. A wallet that can pay its own gas is offered the ordinary path.
    echo 'checkout  ' . $checkoutUrl . '/#/pay/' . rawurlencode($payment['intent']) . PHP_EOL;

    // 4. Verify on your server, as always. The verdict carries how it was paid and every figure.
    $txHash = getenv('P2FLUX_TX_HASH') ?: null;
    if ($txHash === null) {
        echo 'set P2FLUX_TX_HASH to the hash the checkout returns to verify this payment' . PHP_EOL;
        exit(0);
    }

    $verdict = $p2flux->verifyPayment($payment['intent'], $txHash);

    if (($verdict['valid'] ?? false) === true) {
        echo 'paid via ' . ($verdict['gas_payment_mode'] ?? 'native') . PHP_EOL;
        if (isset($verdict['accounting'])) {
            $a = $verdict['accounting']; // USDC base units: 1 USDC = 1000000
            echo 'buyer paid          ' . $a['buyer_total_units'] . PHP_EOL;   // price + quoted network fee only
            echo 'you receive         ' . $a['merchant_net_units'] . PHP_EOL;  // price - 1% - fixed 0.10 network fee
            echo 'P2Flux fee          ' . $a['payment_fee_units'] . PHP_EOL;
            echo 'fixed network fee   ' . $a['fixed_network_fee_units'] . PHP_EOL; // merchant-funded, as on a renewal
            echo 'buyer network fee   ' . $a['network_fee_units'] . PHP_EOL;   // quoted before signing, charged exactly
        }
    } elseif (($verdict['code'] ?? '') === 'RATE_LIMITED') {
        // Per buyer wallet: 10 sponsored transactions in any rolling hour, 20 in any rolling day,
        // across every merchant and operation. Nothing was spent - the buyer can retry later, or pay
        // the network fee with ETH if their wallet holds any. charge() calls are never counted.
        echo 'buyer hit the per-wallet limit; retry after ' . ($verdict['retry_after'] ?? '?') . 's' . PHP_EOL;
    } else {
        echo 'not settled: ' . ($verdict['code'] ?? '?') . PHP_EOL;
    }
} catch (P2FluxException $e) {
    fwrite(STDERR, 'P2Flux refused the request: ' . $e->status . ' (' . $e->action . ')' . PHP_EOL);
    exit(1);
}
