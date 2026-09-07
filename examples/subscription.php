<?php

declare(strict_types=1);

/**
 * A subscription: create the terms, let the customer authorize on the hosted checkout, then charge
 * each period from YOUR renewal job. P2Flux has no scheduler and stores nothing.
 *
 * Run it:
 *   P2FLUX_RECIPIENT=0xYourPayoutWallet php examples/subscription.php
 *   P2FLUX_SUBSCRIPTION=p2s2... php examples/subscription.php   # the renewal half
 *
 * The capability (`p2s2...`) is what the checkout hands your success page. It is a bearer token:
 * server-side only, encrypted at rest, never in a URL or a log.
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
$capability = getenv('P2FLUX_SUBSCRIPTION') ?: null;

try {
    if ($capability === null) {
        // 1. Setup. `period` is in SECONDS. Keep the returned salt with your pending order: it is
        //    how you prove later that the capability you received belongs to this exact setup.
        $setup = $p2flux->createSubscription([
            'recipient' => p2fluxEnv('P2FLUX_RECIPIENT'),
            'amount' => p2fluxEnv('P2FLUX_AMOUNT', '5.00'),
            'period' => (int) p2fluxEnv('P2FLUX_PERIOD', (string) (30 * 86400)),
            // Optional: bound the standing allowance the checkout asks for. Default is unlimited.
            // 'allowance' => ['periods' => 12],
        ]);

        echo 'salt      ' . $setup['salt'] . PHP_EOL;
        echo 'checkout  ' . $checkoutUrl . '/#/subscribe/' . rawurlencode($setup['setup_token']) . PHP_EOL;
        echo 'next      store the p2s2 capability the checkout posts back, then re-run with' . PHP_EOL;
        echo '          P2FLUX_SUBSCRIPTION=<capability> to charge a period' . PHP_EOL;
        exit(0);
    }

    // 2. Before storing a capability, prove it is the subscription THIS order set up - a
    //    cryptographically valid capability can still be the wrong one.
    $state = $p2flux->status($capability);
    echo 'terms     ' . $state['terms']['amount_units'] . ' units every ' . $state['terms']['period'] . 's' . PHP_EOL;
    echo 'due       ' . var_export($state['due'] ?? null, true) . PHP_EOL;

    // 3. Your renewal job charges when YOUR schedule says the period is due. charge() never throws
    //    on a payment outcome: classify on ->action, so an unknown code still lands correctly.
    $result = $p2flux->charge($capability);

    if ($result->ok && $result->txHash !== null) {
        echo 'CHARGED   period ' . $result->periodIndex . ' ' . $result->txHash . PHP_EOL;
    } elseif ($result->ok) {
        // ALREADY_CHARGED: the period is collected and names no transaction. recoverCharge() finds
        // the settlement when you need it to attribute, audit or refund the period.
        $found = $p2flux->recoverCharge($capability, (int) $result->periodIndex);
        echo 'ALREADY   period ' . $result->periodIndex . ' ' . ($found['tx_hash'] ?? 'settlement not located yet') . PHP_EOL;
    } elseif ($result->status === 'CONFIRMING') {
        echo 'CONFIRMING ' . $result->txHash . ' - keep the period open, never charge twice' . PHP_EOL;
    } else {
        echo match ($result->action) {
            'RETRY_LATER' => 'retry later: ' . $result->status . PHP_EOL,
            'CUSTOMER_ACTION_REQUIRED' => 'customer must act: ' . $result->status . PHP_EOL,
            'STOP_SUBSCRIPTION' => 'stop billing: ' . $result->status . PHP_EOL,
            default => 'needs a human: ' . $result->status . PHP_EOL,
        };
    }

    // 4. INSUFFICIENT_ALLOWANCE is not a dead subscription - one approve() from the customer fixes
    //    it, and the signed authorization stays intact:
    //      $session = $p2flux->createAllowanceRestoreSession($capability);
    //      open $checkoutUrl . '/#/approve/' . rawurlencode($session['approve_token']);

    // 5. Cancellation: never hand a browser the capability, it can charge. Hand it a session token.
    $session = $p2flux->createCancellationSession($capability);
    echo 'cancel    ' . $checkoutUrl . '/#/cancel/' . rawurlencode($session['cancel_token']) . PHP_EOL;
} catch (P2FluxException $e) {
    fwrite(STDERR, 'P2Flux refused the request: ' . $e->status . ' (' . $e->action . ')' . PHP_EOL);
    exit(1);
}
