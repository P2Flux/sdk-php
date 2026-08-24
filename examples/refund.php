<?php

declare(strict_types=1);

/**
 * A refund, end to end: prepare the terms, the MERCHANT's own wallet sends the transfer, verify
 * it settled.
 *
 * A refund is a plain USDC transfer from your wallet back to the wallet that paid - no contract,
 * no relayer, no P2Flux custody, no fee. P2Flux derives the payer and the refundable maximum from
 * the original settlement, so this flow can never send money anywhere else.
 *
 * P2Flux keeps NO refund history: enforcing one-refund-per-payment is your job, and the safe
 * place is BEFORE prepare - reserve the order row atomically, then call this.
 */

// In your project: require 'vendor/autoload.php'. Inside this repository the sources load directly.
require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL') ?: 'https://api.p2flux.com']);

// The original settlement, from your order records. Amounts are micro-USDC integer strings.
$originalIntent = 'p2f1....';
$originalTxHash = '0x...';
$refundUnits = '2500000'; // 2.50 USDC

// 1. Prepare: P2Flux locks the terms and names the only allowed sender and recipient.
$prep = $p2flux->prepareRefund(['intent' => $originalIntent, 'tx_hash' => $originalTxHash], $refundUnits);
echo 'send ' . $prep['refund_amount'] . ' USDC from ' . $prep['merchant'] . ' to ' . $prep['payer'] . PHP_EOL;
// For a browser-assisted refund, open your checkout at #/refund/{$prep['refund_token']}.

// 2. YOUR wallet sends the transfer - P2Flux never moves the money. Record the hash.
$refundTxHash = '0x...';

// 3. Verify from the original settlement (no token needed - this works days later, after
//    crashes, from support tooling). Confirming means poll the SAME hash; never send another.
$verdict = $p2flux->verifyRefund(['intent' => $originalIntent, 'tx_hash' => $originalTxHash], $refundUnits, $refundTxHash);
if (($verdict['status'] ?? '') === 'REFUNDED') {
    echo 'refund settled: ' . $verdict['refund_tx_hash'] . PHP_EOL;
} elseif (($verdict['error'] ?? $verdict['code'] ?? '') === 'REFUND_CONFIRMING') {
    echo 'on chain, not yet settled - ask again shortly' . PHP_EOL;
}
