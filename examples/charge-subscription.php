<?php

declare(strict_types=1);

/**
 * Collect one period, from your own renewal job.
 *
 * charge() never throws on a payment outcome. Classify on ->action, not on ->status, so a code this
 * client has never seen still lands in the right branch. The contract allows one charge per billing
 * period, so a repeat after a timeout or a crashed worker answers ALREADY_CHARGED instead of
 * charging twice.
 *
 * Run it:
 *   P2FLUX_SUBSCRIPTION=p2s2... php examples/charge-subscription.php
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

$p2flux = new P2FluxClient(["apiUrl" => p2fluxEnv("P2FLUX_API_URL", "https://api.p2flux.com"), "timeout" => 60]);

// Decrypt it from your own storage. It is never a value you keep in the environment in production.
$capability = p2fluxEnv("P2FLUX_SUBSCRIPTION");

$result = $p2flux->charge($capability);

if ($result->ok && $result->txHash !== null) {
    // CHARGED. The money moved.
    echo "CHARGED    period " . $result->periodIndex . " " . $result->txHash . PHP_EOL;
    echo "next due   " . ($result->nextPeriodAt ?? "?") . PHP_EOL;
} elseif ($result->ok) {
    // ALREADY_CHARGED: this period is collected and names no transaction. That is the normal answer
    // to a retry. examples/recover-charge.php finds the settlement when you need one.
    echo "ALREADY    period " . $result->periodIndex . " collected; recover the hash if you need it" . PHP_EOL;
} elseif ($result->status === "CONFIRMING") {
    // On chain, not settled to the required depth. Keep the period open, change nothing, ask again.
    echo "CONFIRMING " . ($result->txHash ?? "?") . " - never send a second charge" . PHP_EOL;
} else {
    echo match ($result->action) {
        "RETRY_LATER" => "RETRY      " . $result->status . " - nothing was spent" . PHP_EOL,
        "CUSTOMER_ACTION_REQUIRED" => "CUSTOMER   " . $result->status . " - top up or restore the allowance" . PHP_EOL,
        "STOP_SUBSCRIPTION" => "STOP       " . $result->status . " - revoked or expired, final" . PHP_EOL,
        default => "HUMAN      " . $result->status . " - do not retry, fix the request" . PHP_EOL,
    };
}
