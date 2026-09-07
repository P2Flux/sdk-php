<?php

declare(strict_types=1);

/**
 * A payment a buyer can complete holding USDC and no ETH.
 *
 * With `gas_payment_mode => 'payment_token'` the buyer signs a token authorization instead of
 * sending a transaction. P2Flux submits it and pays the Base network fee in ETH; the buyer
 * reimburses that exact cost in USDC inside the same transaction. The fee is real and is quoted
 * before the buyer signs - they pay it in USDC rather than in ETH.
 *
 * Run it:
 *   P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/create-sponsored-payment.php
 */

require __DIR__ . '/../vendor/autoload.php';

use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/** Reads configuration from the environment. Wallets and capabilities never belong in source. */
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
$p2flux = new P2FluxClient(['apiUrl' => p2fluxEnv('P2FLUX_API_URL', 'https://api.p2flux.com')]);

try {
    // 1. Ask what this deployment supports, and never assume. A token that implements the right
    //    standards on a network P2Flux has not deployed to reports false here, and the request is
    //    refused with PAYMENT_TOKEN_GAS_UNSUPPORTED before a buyer sees anything.
    $caps = $p2flux->capabilities();
    $usdc = null;
    foreach ($caps['tokens'] as $token) {
        if ($token['symbol'] === 'USDC') {
            $usdc = $token;
            break;
        }
    }
    $sponsored = $usdc !== null
        && in_array('payment_token', $usdc['gas_payment_modes'], true)
        && ($usdc['operations']['one_time_payment'] ?? false) === true;

    echo 'chain ' . $caps['chain_id'] . ' | buyer can pay without ETH: ' . ($sponsored ? 'yes' : 'no') . PHP_EOL;

    // 2. Create the payment. Fall back to 'native' when sponsorship is unavailable: that is the
    //    ordinary path, where the buyer sends the transaction and pays the fee in ETH.
    $payment = $p2flux->createPayment([
        'recipient' => p2fluxEnv('P2FLUX_RECIPIENT'),
        'amount' => p2fluxEnv('P2FLUX_AMOUNT', '12.50'),
        'gas_payment_mode' => $sponsored ? 'payment_token' : 'native',
    ]);

    echo 'intent    ' . $payment['intent'] . PHP_EOL;
    echo 'mode      ' . ($sponsored ? 'payment_token' : 'native') . PHP_EOL;

    // 3. Nothing else changes. Same checkout URL, same server-side verification afterwards. The
    //    checkout prices the network fee, shows the buyer the total before anything is signed, and
    //    re-checks it at the moment they click.
    echo 'checkout  ' . $checkoutUrl . '/#/pay/' . rawurlencode($payment['intent']) . PHP_EOL;
    echo 'next      verify server-side, then read the accounting block:' . PHP_EOL;
    echo '          examples/network-fee-in-usdc.php' . PHP_EOL;
} catch (P2FluxException $e) {
    fwrite(STDERR, 'P2Flux refused the request: ' . $e->status . ' (' . $e->action . ')' . PHP_EOL);
    exit(1);
}
