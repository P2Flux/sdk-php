<?php

declare(strict_types=1);

/**
 * The buyer paid and your page never heard: find the settlement from the intent alone.
 *
 * The popup closed, the tab crashed, the callback died. recoverPayment() reads the contract logs
 * for the exact payment the intent describes, so it can never hand you somebody elses transaction.
 * Pure reads and idempotent - safe to run from a cron over every order you are unsure about.
 *
 * Run it:
 *   P2FLUX_INTENT=p2f1... php examples/recover-payment.php
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

try {
    $found = $p2flux->recoverPayment(p2fluxEnv("P2FLUX_INTENT"));

    if (($found["found"] ?? false) !== true) {
        // PAYMENT_NOT_FOUND is a statement about one block height, not a permanent verdict: a slow
        // wallet can still settle afterwards. Stop retrying on your own business rules, and never
        // mint a second intent for the same order.
        echo "NOT FOUND  as of block " . ($found["as_of_block"] ?? "?") . PHP_EOL;
        exit(0);
    }

    if (($found["valid"] ?? false) === true) {
        echo "RECOVERED  " . $found["tx_hash"] . " block " . ($found["block_number"] ?? "?") . PHP_EOL;
        echo "amount     " . ($found["amount"] ?? "?") . PHP_EOL;
        // Mark the order paid here, once, exactly as your verify path does.
    } else {
        // Located but still confirming. Keep the hash and poll it - the work is already done.
        echo "CONFIRMING " . ($found["tx_hash"] ?? "?") . PHP_EOL;
    }
} catch (P2FluxException $e) {
    fwrite(STDERR, "recovery could not be completed: " . $e->status . " (" . $e->action . ")" . PHP_EOL);
    exit(1);
}
