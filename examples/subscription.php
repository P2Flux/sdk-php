<?php

declare(strict_types=1);

/**
 * A subscription, end to end: create the terms, let the customer authorize on the hosted
 * checkout, store the capability, charge each period from your own renewal job, and hand the
 * customer a safe way to cancel.
 *
 * Production is real USDC on Base Mainnet. Set P2FLUX_API_URL=https://api-test.p2flux.com
 * (Base Sepolia, faucet money) while integrating.
 */

// In your project: require 'vendor/autoload.php'. Inside this repository the sources load directly.
require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';
require __DIR__ . '/../src/CurlTransport.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL') ?: 'https://api.p2flux.com']);

// 1. Create the terms when the customer picks a plan. Period is SECONDS (30 days here).
$setup = $p2flux->createSubscription([
    'recipient' => '0x1111111111111111111111111111111111111111', // example address - use your own
    'amount'    => '5.00',
    'period'    => 30 * 86400,
]);

// 2. Keep $setup['salt'] with your pending order, then send the customer to the hosted checkout.
//    Their wallet approves USDC and signs one EIP-712 authorization; no further prompts ever.
echo 'send customer to https://pay.p2flux.com/#/subscribe/' . $setup['setup_token'] . PHP_EOL;

// 3. The hosted checkout finalizes and returns the capability to your success handler. If you run
//    your OWN checkout page instead, finalize server-side with the signature it collected:
$payerAddress = '0x2222222222222222222222222222222222222222';
$eip712Signature = '0x...';
$finalized = $p2flux->finalizeSubscription($setup['setup_token'], $payerAddress, $eip712Signature);

// 4. Store $finalized['subscription'] (the p2s2 capability) - encrypted at rest, never in a URL
//    or log. It is the ONE thing you keep; everything else is read back from the chain on demand.
$capability = $finalized['subscription'];

// 5. Your renewal job - yours, on your schedule; P2Flux has no scheduler - charges each period:
$result = $p2flux->charge($capability);
if ($result->ok) {
    echo 'period ' . $result->periodIndex . ' paid ' . ($result->alreadyPaid ? '(recovered)' : $result->txHash) . PHP_EOL;
} elseif ($result->status === 'CONFIRMING') {
    echo 'on chain, not yet settled - keep the period open and ask again; never charge twice' . PHP_EOL;
} elseif ($result->action === 'STOP_SUBSCRIPTION') {
    echo 'customer revoked or subscription ended: ' . $result->status . PHP_EOL;
} elseif ($result->action === 'CUSTOMER_ACTION_REQUIRED') {
    echo 'customer must top up or restore the allowance: ' . $result->status . PHP_EOL;
} else {
    echo 'retry later: ' . $result->status . PHP_EOL;
}

// 6. Reconcile any time from the chain - after downtime, before dunning, in support tooling:
$state = $p2flux->status($capability);
echo 'due: ' . var_export($state['due'] ?? null, true) . ' charged this period: ' . var_export($state['charged_this_period'] ?? null, true) . PHP_EOL;

// 7. Cancellation: never give the browser the capability - it can charge. Hand it a session:
$session = $p2flux->createCancellationSession($capability);
echo 'cancel page: https://pay.p2flux.com/#/cancel/' . $session['cancel_token'] . PHP_EOL;
// Only the customer's own wallet can actually revoke; P2Flux prepares the calldata for it.
