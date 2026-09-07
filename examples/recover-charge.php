<?php

declare(strict_types=1);

/**
 * ALREADY_CHARGED proved a period was collected and named no transaction. Find it.
 *
 * P2Flux stores nothing, so the hash lives only in the contracts log. Without it a paid period
 * cannot be attributed to an order, audited, or refunded - both refund calls start from the
 * original settlement.
 *
 * The period index is required and exact: you are reconciling one specific collection, today or a
 * year from now, and the answer must not move under you. Take it from the charge result or status().
 *
 * Run it:
 *   P2FLUX_SUBSCRIPTION=p2s2... P2FLUX_PERIOD_INDEX=3 php examples/recover-charge.php
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

$capability = p2fluxEnv("P2FLUX_SUBSCRIPTION");
$periodIndex = (int) p2fluxEnv("P2FLUX_PERIOD_INDEX");

// Optional and never evidence: where your own records say you attempted the charge. It narrows the
// search and can never turn a miss into a hit, so omitting it is always safe.
$attemptedAt = getenv("P2FLUX_ATTEMPTED_AT");
$hint = $attemptedAt === false || $attemptedAt === "" ? null : ["attempted_at" => (int) $attemptedAt];

try {
    $found = $p2flux->recoverCharge($capability, $periodIndex, $hint);

    if (($found["found"] ?? false) !== true) {
        // Ordinary, not an error: there is no catch-up billing, so a period that was never collected
        // is a normal history, and a later period says nothing about an earlier one.
        echo "NOT FOUND  period " . $periodIndex . " as of block " . ($found["as_of_block"] ?? "?") . PHP_EOL;
        exit(0);
    }

    // Check the settlement against what you expected before acting on it.
    echo "FOUND      " . $found["tx_hash"] . " block " . ($found["block_number"] ?? "?") . PHP_EOL;
    echo "period     " . ($found["period_index"] ?? "?") . PHP_EOL;
    echo "amount     " . ($found["amount_units"] ?? "?") . " units to " . ($found["recipient"] ?? "?") . PHP_EOL;
} catch (P2FluxException $e) {
    // RECOVERY_UNAVAILABLE (bounded search budget) and PAYMENT_RECOVERY_INCONSISTENT arrive here.
    // The second one is abnormal: never treat it as a payment.
    fwrite(STDERR, "recovery could not be completed: " . $e->status . " (" . $e->action . ")" . PHP_EOL);
    exit(1);
}
