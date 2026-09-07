<?php

declare(strict_types=1);

/**
 * What the buyer actually paid when the network fee was paid in USDC.
 *
 * Verification is the same call as always; the verdict simply carries more. Every figure is in USDC
 * base units, where 1 USDC = 1000000.
 *
 * Run it:
 *   P2FLUX_INTENT=p2f1... P2FLUX_TX_HASH=0x... php examples/network-fee-in-usdc.php
 *
 * Create such a payment with examples/create-sponsored-payment.php.
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
    $verdict = $p2flux->verifyPayment(p2fluxEnv("P2FLUX_INTENT"), p2fluxEnv("P2FLUX_TX_HASH"));

    if (($verdict["valid"] ?? false) !== true) {
        if (($verdict["code"] ?? "") === "RATE_LIMITED") {
            // Per buyer wallet: 10 sponsored transactions in any rolling hour, 20 in any rolling
            // day, across every merchant and operation. Nothing was spent - the buyer retries later,
            // or pays the network fee with ETH if their wallet holds any. charge() is never counted.
            echo "buyer hit the per-wallet limit; retry after " . ($verdict["retry_after"] ?? "?") . "s" . PHP_EOL;
            exit(0);
        }
        echo "not settled: " . ($verdict["code"] ?? "?") . PHP_EOL;
        exit(0);
    }

    echo "paid via " . ($verdict["gas_payment_mode"] ?? "native") . PHP_EOL;

    if (!isset($verdict["accounting"])) {
        // A payment created without gas_payment_mode settles through the same contract and carries
        // no accounting block. Nothing is wrong; there is simply no network fee to attribute.
        echo "no accounting block: this payment used native gas" . PHP_EOL;
        exit(0);
    }

    $a = $verdict["accounting"];
    echo "price               " . $a["payment_units"] . PHP_EOL;
    echo "buyer paid          " . $a["buyer_total_units"] . PHP_EOL;   // price + the quoted network fee, nothing else
    echo "you receive         " . $a["merchant_net_units"] . PHP_EOL;  // price - 1% - the fixed 0.10 network fee
    echo "P2Flux fee          " . $a["payment_fee_units"] . PHP_EOL;
    echo "fixed network fee   " . $a["fixed_network_fee_units"] . PHP_EOL; // merchant-funded, as on a renewal
    echo "buyer network fee   " . $a["network_fee_units"] . PHP_EOL;   // quoted before signing, charged exactly
    echo "payer               " . $a["payer"] . PHP_EOL;
} catch (P2FluxException $e) {
    fwrite(STDERR, "verification could not be completed: " . $e->status . " (" . $e->action . ")" . PHP_EOL);
    exit(1);
}
