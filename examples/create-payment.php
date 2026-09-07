<?php

declare(strict_types=1);

/**
 * A one-time payment: create the intent, hand the buyer to the hosted checkout, verify server-side.
 *
 * Run it:
 *   P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/create-payment.php
 *
 * Production is real USDC on Base Mainnet. Set P2FLUX_API_URL=https://api-test.p2flux.com and
 * P2FLUX_CHECKOUT_URL=https://pay-test.p2flux.com (Base Sepolia, faucet money) while integrating.
 */

require __DIR__ . '/../vendor/autoload.php';

use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/** Reads configuration from the environment. Credentials and wallets never belong in source. */
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

$apiUrl = p2fluxEnv('P2FLUX_API_URL', 'https://api.p2flux.com');
$checkoutUrl = p2fluxEnv('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com');
$recipient = p2fluxEnv('P2FLUX_RECIPIENT');           // YOUR payout wallet
$amount = p2fluxEnv('P2FLUX_AMOUNT', '12.50');

$p2flux = new P2FluxClient(['apiUrl' => $apiUrl, 'timeout' => 30]);

try {
    // 1. Mint the intent when the buyer chooses to pay, and store it on the order row: you need it
    //    to verify, and to recover a settlement whose hash you never received.
    $payment = $p2flux->createPayment(['recipient' => $recipient, 'amount' => $amount]);
    echo 'intent    ' . $payment['intent'] . PHP_EOL;

    // 2. Send the buyer to the hosted checkout. The intent rides in the URL fragment, which never
    //    reaches a server log.
    echo 'checkout  ' . $checkoutUrl . '/#/pay/' . rawurlencode($payment['intent']) . PHP_EOL;

    // 3. The checkout posts `p2flux.payment.completed { tx_hash, settlement_receipt }` to your page.
    //    That message is a claim. Verify it server-side - see examples/verify-payment.php.
    echo 'next      verify the transaction hash server-side before marking the order paid' . PHP_EOL;
} catch (P2FluxException $e) {
    fwrite(STDERR, 'P2Flux refused the request: ' . $e->status . ' (' . $e->action . ')' . PHP_EOL);
    exit(1);
}
