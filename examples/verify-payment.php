<?php

declare(strict_types=1);

/**
 * Server-side verification: the only thing that may mark an order paid.
 *
 * P2Flux sends no webhooks. Completion reaches you as a browser message from the hosted checkout
 * (`p2flux.payment.completed { tx_hash, settlement_receipt }`), and a browser message is a claim,
 * not a fact. This is where the claim is checked against the chain.
 *
 * Run it:
 *   P2FLUX_INTENT=p2f1... P2FLUX_TX_HASH=0x... php examples/verify-payment.php
 *
 * No hash at all? examples/recover-payment.php finds the settlement from the intent alone.
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

$p2flux = new P2FluxClient(["apiUrl" => p2fluxEnv("P2FLUX_API_URL", "https://api.p2flux.com")]);

$intent = p2fluxEnv("P2FLUX_INTENT");                       // stored on your order row
$txHash = p2fluxEnv("P2FLUX_TX_HASH");                      // claimed by the browser
$receipt = getenv("P2FLUX_SETTLEMENT_RECEIPT") ?: null;     // couriered by the checkout, optional

// Your own idempotency guard belongs here: if this order is already paid, stop. Verifying twice is
// safe; crediting the customer twice is not.

try {
    // A rejected payment is ["valid" => false, "code" => ...] with HTTP 200, never an exception.
    // Passing the settlement receipt lets the API answer without re-reading the chain; a broken one
    // silently falls back to the full check, so it is always safe to pass what the browser gave you.
    $verdict = $p2flux->verifyPayment($intent, $txHash, $receipt);

    if (($verdict["valid"] ?? false) === true) {
        echo "PAID       " . $verdict["tx_hash"] . " block " . ($verdict["block_number"] ?? "?") . PHP_EOL;
        echo "amount     " . ($verdict["amount"] ?? "?") . PHP_EOL;
        // Keep $verdict["settlement_receipt"] with the order: a repeat verification answers instantly.
        // Mark the order paid HERE, inside your own transaction, and only once.
    } elseif (($verdict["code"] ?? "") === "PAYMENT_CONFIRMING") {
        // On chain, not settled deep enough yet. Poll the SAME hash. Never ask the buyer to pay again.
        echo "CONFIRMING poll the same hash shortly" . PHP_EOL;
    } else {
        // A verdict about the chain: this transaction does not settle this intent.
        echo "REJECTED   " . ($verdict["code"] ?? "?") . PHP_EOL;
    }
} catch (P2FluxException $e) {
    // Transport-level only. An unreachable API says nothing about whether the payment landed - retry,
    // and never treat this as a rejection.
    fwrite(STDERR, "verification could not be completed: " . $e->status . " (" . $e->action . ")" . PHP_EOL);
    exit(1);
}
