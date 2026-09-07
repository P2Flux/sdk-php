<?php

declare(strict_types=1);

/**
 * A refund: prepare the terms, the MERCHANT's own wallet sends the transfer, verify it settled.
 *
 * A refund is a plain USDC transfer from your wallet back to the wallet that paid - no contract, no
 * relayer, no P2Flux custody, no fee. P2Flux derives the payer and the refundable maximum from the
 * original settlement, so this flow can never send money anywhere else.
 *
 * P2Flux keeps NO refund history: enforcing one refund per payment is your job, and the safe place
 * is BEFORE prepare - reserve the order row atomically, then call this.
 *
 * Run it:
 *   P2FLUX_INTENT=p2f1... P2FLUX_TX_HASH=0x... P2FLUX_REFUND_UNITS=2500000 php examples/refund.php
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
$p2flux = new P2FluxClient(['apiUrl' => p2fluxEnv('P2FLUX_API_URL', 'https://api.p2flux.com')]);

// The original settlement, from your order records. Amounts are micro-USDC integer strings:
// "2500000" is 2.50 USDC. Decimals are refused - a partial refund in floating point is a rounding bug.
$original = ['intent' => p2fluxEnv('P2FLUX_INTENT'), 'tx_hash' => p2fluxEnv('P2FLUX_TX_HASH')];
// For a renewal instead: ['subscription' => $capability, 'tx_hash' => $hash, 'period_index' => 3]
$refundUnits = p2fluxEnv('P2FLUX_REFUND_UNITS');

try {
    // 1. Prepare: P2Flux locks the terms and names the only allowed sender and recipient.
    $prep = $p2flux->prepareRefund($original, $refundUnits);
    echo 'send ' . $prep['refund_amount'] . ' USDC from ' . $prep['merchant'] . ' to ' . $prep['payer'] . PHP_EOL;
    echo 'refund page ' . $checkoutUrl . '/#/refund/' . rawurlencode($prep['refund_token']) . PHP_EOL;

    // 2. YOUR wallet sends the transfer - P2Flux never moves the money. Record the hash.
    $refundTxHash = getenv('P2FLUX_REFUND_TX_HASH') ?: null;
    if ($refundTxHash === null) {
        echo 'set P2FLUX_REFUND_TX_HASH once the transfer is sent to verify it' . PHP_EOL;
        exit(0);
    }

    // 3. Verify from the ORIGINAL settlement, not the prepare token - so this still works days
    //    later, after a crash or from support tooling.
    $verdict = $p2flux->verifyRefund($original, $refundUnits, $refundTxHash);
    if (($verdict['status'] ?? '') === 'REFUNDED') {
        echo 'REFUNDED  ' . $verdict['refund_tx_hash'] . PHP_EOL;
    } elseif (($verdict['error'] ?? $verdict['code'] ?? '') === 'REFUND_CONFIRMING') {
        // On chain, not settled. Poll the SAME hash. Never send another transfer.
        echo 'CONFIRMING - poll the same hash shortly' . PHP_EOL;
    } else {
        echo 'not a refund of this payment: ' . ($verdict['error'] ?? $verdict['code'] ?? '?') . PHP_EOL;
    }
} catch (P2FluxException $e) {
    fwrite(STDERR, 'P2Flux refused the request: ' . $e->status . ' (' . $e->action . ')' . PHP_EOL);
    exit(1);
}
