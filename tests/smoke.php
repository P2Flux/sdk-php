<?php

declare(strict_types=1);

/**
 * Smoke test for the PHP SDK against a running API.
 *
 *   php sdk/php/tests/smoke.php http://127.0.0.1:PORT <p2s2 reference>
 *
 * Driven by tests/sdk.test.ts, which starts the stub API and supplies a chargeable reference.
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';

use P2Flux\ChargeResult;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

[, $apiUrl, $subscription] = $argv + [null, null, null];

$failures = 0;
function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "  ok    {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$label}  {$detail}\n";
}

$p2flux = new P2FluxClient(['apiUrl' => $apiUrl, 'timeout' => 10]);

// --- a due subscription charges -------------------------------------------------

$result = $p2flux->charge($subscription);
check('CHARGED', $result->status === 'CHARGED', $result->status);
check('ok', $result->ok);
check('not already paid', $result->alreadyPaid === false);
check('action SUCCESS', $result->action === 'SUCCESS', $result->action);
check('tx hash present', is_string($result->txHash) && str_starts_with($result->txHash, '0x'));
check('next period known', is_string($result->nextPeriodAt), (string) $result->nextPeriodAt);

// --- the merchant's worker crashed and retries the same period -------------------

$retry = $p2flux->charge($subscription);
check('ALREADY_CHARGED', $retry->status === 'ALREADY_CHARGED', $retry->status);
check('retry ok', $retry->ok, 'a retry after a timeout must not look like a failure');
check('retry alreadyPaid', $retry->alreadyPaid);
check('retry action SUCCESS', $retry->action === 'SUCCESS', $retry->action);
check('retry period matches', $retry->periodIndex === $result->periodIndex);

// --- status reconciliation ------------------------------------------------------

$status = $p2flux->status($subscription);
check('status charged_this_period', $status['charged_this_period'] === true);
check('status not due', $status['due'] === false);
check('status has next_period_at', array_key_exists('next_period_at', $status));

// --- prepared calldata ----------------------------------------------------------

$cancel = $p2flux->prepareSubscriptionCancellation($subscription);
check('cancel calldata', str_starts_with($cancel['data'], '0x'));
$allowance = $p2flux->prepareAllowanceRevocation();
check('allowance calldata is approve()', str_starts_with($allowance['data'], '0x095ea7b3'));

// --- malformed reference throws, with a stable status ---------------------------

try {
    $p2flux->status('garbage');
    check('invalid reference throws', false);
} catch (P2FluxException $e) {
    check('invalid reference throws INVALID_SUBSCRIPTION', $e->status === 'INVALID_SUBSCRIPTION', $e->status);
}

// --- an unreachable API is NETWORK_ERROR, never a payment outcome ----------------

$offline = new P2FluxClient(['apiUrl' => 'http://127.0.0.1:1', 'timeout' => 2]);
$down = $offline->charge($subscription);
check('NETWORK_ERROR', $down->status === 'NETWORK_ERROR', $down->status);
check('network error retryable', $down->retryable);
check('network error not ok', $down->ok === false);

// --- terminal outcomes normalize identically to the JS SDK ----------------------
// Revocation is a customer wallet transaction, so it cannot be produced through the API here;
// the mapping itself is what must match.

$revoked = ChargeResult::fromArray(['error' => 'PERMISSION_REVOKED', 'action' => 'STOP_SUBSCRIPTION']);
check('revoked not ok', $revoked->ok === false);
check('revoked stops the subscription', $revoked->action === 'STOP_SUBSCRIPTION');
check('revoked not retryable', $revoked->retryable === false);

$broke = ChargeResult::fromArray(['error' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED']);
check('insufficient balance needs the customer', $broke->action === 'CUSTOMER_ACTION_REQUIRED');
check('insufficient balance not retryable by itself', $broke->retryable === false);

$relayer = ChargeResult::fromArray(['error' => 'RELAYER_ERROR', 'action' => 'RETRY_LATER']);
check('relayer error retryable', $relayer->retryable);

echo $failures === 0 ? "\nphp sdk smoke OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
