<?php

declare(strict_types=1);

/**
 * Start a subscription: create the terms, send the customer to the hosted checkout, then prove the
 * capability that comes back belongs to this order.
 *
 * P2Flux schedules nothing. The capability (`p2s2...`) is the one thing your system stores per
 * subscription. Treat it as a credential: server-side only, encrypted at rest, never in a URL or a
 * log. Charging it is examples/charge-subscription.php.
 *
 * Run it:
 *   P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/subscription-signup.php
 *   P2FLUX_SUBSCRIPTION=p2s2... P2FLUX_SALT=12345 php examples/subscription-signup.php   # step 3
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

$checkoutUrl = p2fluxEnv("P2FLUX_CHECKOUT_URL", "https://pay.p2flux.com");
$p2flux = new P2FluxClient(["apiUrl" => p2fluxEnv("P2FLUX_API_URL", "https://api.p2flux.com")]);
$capability = getenv("P2FLUX_SUBSCRIPTION") ?: null;

try {
    if ($capability === null) {
        // 1. Terms. `period` is in SECONDS. Keep the salt with your pending order: it is how you
        //    prove later that the capability you were handed came from this exact setup.
        $setup = $p2flux->createSubscription([
            "recipient" => p2fluxEnv("P2FLUX_RECIPIENT"),
            "amount" => p2fluxEnv("P2FLUX_AMOUNT", "5.00"),
            "period" => (int) p2fluxEnv("P2FLUX_PERIOD", (string) (30 * 86400)),
            // Optional: bound the standing allowance the checkout asks for. Unlimited by default,
            // so renewals never need the wallet again.
            // "allowance" => ["periods" => 12],
        ]);

        echo "salt      " . $setup["salt"] . PHP_EOL;
        echo "checkout  " . $checkoutUrl . "/#/subscribe/" . rawurlencode($setup["setup_token"]) . PHP_EOL;
        echo "next      the checkout posts p2flux.subscription.created { subscription } to your page;" . PHP_EOL;
        echo "          re-run with P2FLUX_SUBSCRIPTION=<capability> P2FLUX_SALT=" . $setup["salt"] . PHP_EOL;
        exit(0);
    }

    // 2. A cryptographically valid capability can still be the WRONG one. Read the terms from the
    //    chain and compare them to what you sold before you store anything.
    $state = $p2flux->status($capability);
    $expectedSalt = p2fluxEnv("P2FLUX_SALT");

    if ($state["terms"]["salt"] !== $expectedSalt) {
        fwrite(STDERR, "SETUP_MISMATCH: this capability is not the one this order set up" . PHP_EOL);
        exit(1);
    }

    echo "terms ok  " . $state["terms"]["amount_units"] . " units every " . $state["terms"]["period"] . "s" . PHP_EOL;
    echo "recipient " . $state["terms"]["recipient"] . PHP_EOL;
    echo "due       " . var_export($state["due"] ?? null, true) . PHP_EOL;
    echo "next      store the capability encrypted, then charge it from your own renewal job" . PHP_EOL;
} catch (P2FluxException $e) {
    fwrite(STDERR, "P2Flux refused the request: " . $e->status . " (" . $e->action . ")" . PHP_EOL);
    exit(1);
}
