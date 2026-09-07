<?php

declare(strict_types=1);

/**
 * Step 3 - the trust boundary. The browser's claim is checked against the chain, and only a valid
 * verdict marks the order paid.
 *
 * Repeat-safe on purpose: a double-submitted page, a retried fetch and a cron sweep all land here,
 * and none of them may fulfil twice.
 */

require __DIR__ . '/bootstrap.php';

use P2Flux\P2FluxException;

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
$input = is_array($input) ? $input : [];

$order = loadOrder((string) ($input['order'] ?? ''));
if ($order === null) {
    jsonResponse(404, ['status' => 'unknown_order']);
    exit;
}

// Already settled by an earlier call: answer the same thing again and touch nothing.
if ($order['status'] === 'paid') {
    jsonResponse(200, ['status' => 'paid', 'tx_hash' => $order['tx_hash'], 'repeat' => true]);
    exit;
}

$txHash = (string) ($input['tx_hash'] ?? '');
$receipt = isset($input['settlement_receipt']) ? (string) $input['settlement_receipt'] : null;

try {
    // No hash in the claim (a dead callback): the intent alone can still find the settlement.
    $verdict = $txHash === ''
        ? p2flux()->recoverPayment($order['intent'])
        : p2flux()->verifyPayment($order['intent'], $txHash, $receipt);
} catch (P2FluxException $e) {
    // The request never reached a verdict. Unknown, not rejected - the caller retries.
    jsonResponse(503, ['status' => 'unavailable', 'code' => $e->status, 'action' => $e->action]);
    exit;
}

if (($verdict['valid'] ?? false) === true) {
    // A production integration does this inside a database transaction, re-checking the unpaid
    // status under a row lock so two concurrent verifications cannot both fulfil.
    $order['status'] = 'paid';
    $order['tx_hash'] = $verdict['tx_hash'] ?? $txHash;
    $order['block_number'] = $verdict['block_number'] ?? null;
    $order['settlement_receipt'] = $verdict['settlement_receipt'] ?? null;
    $order['paid_at'] = gmdate('c');
    saveOrder($order);

    jsonResponse(200, ['status' => 'paid', 'tx_hash' => $order['tx_hash']]);
    exit;
}

$code = (string) ($verdict['code'] ?? 'NOT_SETTLED');

if ($code === 'PAYMENT_CONFIRMING' || (($verdict['found'] ?? false) === true && ($verdict['valid'] ?? false) !== true)) {
    // On chain, not settled to the required depth. Keep the order open and poll the SAME hash.
    $order['tx_hash'] = $verdict['tx_hash'] ?? $txHash;
    saveOrder($order);
    jsonResponse(202, ['status' => 'confirming', 'tx_hash' => $order['tx_hash']]);
    exit;
}

// A verdict about the chain: this transaction does not settle this intent. The order stays unpaid.
jsonResponse(200, ['status' => 'unsettled', 'code' => $code]);
