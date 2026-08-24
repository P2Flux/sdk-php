<?php

declare(strict_types=1);

/**
 * Offline tests for the injectable transport and the setup/verify calls, with no API running.
 *
 *   php sdk/php/tests/transport.php
 *
 * smoke.php covers the same client against a real API; this covers the shapes a host application
 * (WordPress, Laravel) exercises when it supplies its own HTTP client.
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';

use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

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

/** Records every call and replays canned responses keyed by path suffix. */
final class StubTransport
{
    /** @var array<int, array{url: string, payload: array<string, mixed>, timeout: int}> */
    public array $calls = [];
    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeout];
        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                return $response;
            }
        }

        return [404, ['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']];
    }
}

// --- the transport is used instead of curl, and receives what the caller configured ----

$stub = new StubTransport([
    '/v1/subscriptions' => [200, ['setup_token' => 'p2setup2.k1.body.mac', 'salt' => '12345', 'amount' => '10.000000']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example/', 'timeout' => 7, 'transport' => $stub]);

$setup = $client->createSubscription(['recipient' => '0x' . str_repeat('a', 40), 'amount' => '10.00', 'period' => 2592000]);
check('createSubscription returns the body', $setup['setup_token'] === 'p2setup2.k1.body.mac');
check('setup carries the salt', $setup['salt'] === '12345');
check('trailing slash in apiUrl is normalized', $stub->calls[0]['url'] === 'https://api.example/v1/subscriptions', $stub->calls[0]['url']);
check('terms pass through untouched', $stub->calls[0]['payload']['period'] === 2592000);
check('no customer fields are invented', array_keys($stub->calls[0]['payload']) === ['recipient', 'amount', 'period']);
check('timeout reaches the transport', $stub->calls[0]['timeout'] === 7);

// --- one-time payment: create then verify server-side ---------------------------------

$stub = new StubTransport([
    '/v1/payments/verify' => [200, ['valid' => true, 'tx_hash' => '0xabc', 'reference' => '0xref', 'amount' => '5.000000']],
    '/v1/payments' => [200, ['intent' => 'p2f1.k1.body.mac', 'reference' => '0xref', 'amount' => '5.000000']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);

$intent = $client->createPayment(['recipient' => '0x' . str_repeat('b', 40), 'amount' => '5.00']);
check('createPayment returns an intent', $intent['intent'] === 'p2f1.k1.body.mac');

$verified = $client->verifyPayment($intent['intent'], '0xabc');
check('verifyPayment confirms', $verified['valid'] === true);
check('verify posts intent and tx_hash', $stub->calls[1]['payload'] === ['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc']);

// The settlement receipt is included only when the caller actually has one - an old server that
// does not know the field never sees it, and null/'' mean "verify for real" exactly like absence.
$client->verifyPayment('p2f1.k1.body.mac', '0xabc', 'p2paid1.k1.sealed.mac');
check('a settlement receipt rides in the payload', $stub->calls[2]['payload'] === ['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc', 'settlement_receipt' => 'p2paid1.k1.sealed.mac']);
$client->verifyPayment('p2f1.k1.body.mac', '0xabc', '');
check('an empty receipt is not sent at all', $stub->calls[3]['payload'] === ['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc']);

// A rejected payment is a 200 body, not an exception - the caller must inspect `valid`.
$rejecting = new StubTransport(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'RECIPIENT_MISMATCH']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $rejecting]);
check('invalid payment does not throw', $client->verifyPayment('p2f1.x', '0xdead')['valid'] === false);

// --- cancellation session never returns the capability --------------------------------

$stub = new StubTransport([
    '/v1/subscriptions/revoke/session' => [200, ['cancel_token' => 'p2cancel1.k1.body.mac', 'expires_at' => 1700000900]],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
$session = $client->createCancellationSession('p2s2.k1.payload.mac');
check('cancellation session issues a cancel token', str_starts_with($session['cancel_token'], 'p2cancel1.'));
check('cancel token is not the capability', !str_contains(json_encode($session), 'p2s2.'));

// --- HTTP errors still throw with a stable status -------------------------------------

$failing = new StubTransport(['/v1/subscriptions' => [400, ['error' => 'PERIOD_OUT_OF_BOUNDS', 'action' => 'INVALID_REQUEST']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $failing]);
try {
    $client->createSubscription(['recipient' => '0x' . str_repeat('c', 40), 'amount' => '1.00', 'period' => 1]);
    check('rejected setup throws', false);
} catch (P2FluxException $e) {
    check('rejected setup throws PERIOD_OUT_OF_BOUNDS', $e->status === 'PERIOD_OUT_OF_BOUNDS', $e->status);
    check('exception carries the action', $e->action === 'INVALID_REQUEST', $e->action);
}

// --- a transport that cannot reach the API is a retryable charge outcome, never a throw -

$dead = new P2FluxClient([
    'apiUrl' => 'https://api.example',
    'transport' => function (): array {
        throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', []);
    },
]);
$down = $dead->charge('p2s2.k1.payload.mac');
check('transport failure is NETWORK_ERROR', $down->status === 'NETWORK_ERROR', $down->status);
check('transport failure is retryable', $down->retryable);
check('transport failure is not ok', $down->ok === false);

// --- a non-callable transport is rejected at construction -----------------------------

try {
    new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => 'not-a-function']);
    check('non-callable transport rejected', false);
} catch (InvalidArgumentException $e) {
    check('non-callable transport rejected', true);
}

// --- recovering a payment whose transaction hash was lost ------------------------------

$stub = new StubTransport([
    '/v1/payments/recover' => [200, ['found' => true, 'valid' => true, 'tx_hash' => '0xrecovered', 'amount' => '5.000000']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);

$recovered = $client->recoverPayment('p2f1.k1.body.mac');
check('recoverPayment returns the located transaction', $recovered['tx_hash'] === '0xrecovered');
// The intent and nothing else: no hash, no hint, nothing a caller could get wrong.
check('recovery sends only the intent', array_keys($stub->calls[0]['payload']) === ['intent']);

/* Nothing settled is an ANSWER, not an exception - a merchant loop that had to catch an exception
 * to learn "keep waiting" is a loop that eventually writes off a real payment. */
$stub = new StubTransport([
    '/v1/payments/recover' => [200, ['found' => false, 'code' => 'PAYMENT_NOT_FOUND', 'as_of_block' => '45688490']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
$pending = $client->recoverPayment('p2f1.k1.body.mac');
check('nothing settled is a result, not an exception', $pending['found'] === false);
check('and it names the block it was true at', $pending['as_of_block'] === '45688490');

// A settlement still confirming keeps its hash - losing it again is what recovery exists to prevent.
$stub = new StubTransport([
    '/v1/payments/recover' => [409, ['found' => true, 'valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => '0xconfirming']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
$confirming = $client->recoverPayment('p2f1.k1.body.mac');
check('a confirming recovery reports its hash', $confirming['tx_hash'] === '0xconfirming');

// A deployment that cannot recover is an operator problem, and must not read as "no payment".
$stub = new StubTransport(['/v1/payments/recover' => [503, ['error' => 'RECOVERY_UNAVAILABLE']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
try {
    $client->recoverPayment('p2f1.k1.body.mac');
    check('an unavailable recovery throws', false);
} catch (P2FluxException $e) {
    check('an unavailable recovery throws', $e->status === 'RECOVERY_UNAVAILABLE');
    check('and maps to a retry', $e->action === 'RETRY_LATER');
}

// --- refunds: a confirming refund is an answer, and dead tokens are permanent ------------

/* 409 as of 2026-08-21, matching PAYMENT_CONFIRMING. It used to be 400, and verifyRefund threw on
 * it regardless - contradicting its own documentation and making a caller catch an exception to
 * learn "wait a moment", which is how a second refund gets sent. */
$stub = new StubTransport([
    '/v1/refunds/verify' => [409, ['error' => 'REFUND_CONFIRMING', 'action' => 'WAIT']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
$confirming = $client->verifyRefund(['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc'], '2500000', '0xdef');
check('a confirming refund is returned, not thrown', ($confirming['error'] ?? '') === 'REFUND_CONFIRMING');

// The same code arrived as 400 before the correction; keyed on the code, so both still work.
$stub = new StubTransport(['/v1/refunds/verify' => [400, ['error' => 'REFUND_CONFIRMING']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
$legacy = $client->verifyRefund(['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc'], '2500000', '0xdef');
check('an older deployment 400 reads the same', ($legacy['error'] ?? '') === 'REFUND_CONFIRMING');

/* A malformed or expired refund token never becomes valid. These had no ACTIONS entry, so the
 * fallback reported them as RETRY_LATER and a merchant would retry a dead token forever. */
$stub = new StubTransport(['/v1/refunds/verify' => [400, ['error' => 'INVALID_REFUND_TOKEN']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $stub]);
try {
    $client->verifyRefund(['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc'], '2500000', '0xdef');
    check('a dead refund token throws', false);
} catch (P2FluxException $e) {
    check('a dead refund token throws', $e->status === 'INVALID_REFUND_TOKEN');
    check('and is permanent, not a retry', $e->action === 'INVALID_REQUEST', $e->action);
}

check('recovery codes carry actions too', P2FluxClient::ACTIONS['PAYMENT_NOT_FOUND'] === 'RETRY_LATER');

// --- the four methods that completed V1 parity (2026-08-24) ---------------------------

$stub = new StubTransport([
    '/v1/payments/resolve' => [200, ['recipient' => '0x' . str_repeat('33', 20), 'amount' => '12.500000', 'chain_id' => 8453, 'confirmations_required' => null]],
    '/v1/subscriptions/resolve' => [200, ['amount' => '5.000000', 'period' => 2592000, 'typed_data' => ['primaryType' => 'Authorization'], 'salt' => '424242']],
    '/v1/subscriptions/finalize' => [200, ['subscription' => 'p2s2.k1.body.mac', 'subscription_id' => '0x' . str_repeat('ab', 32), 'amount' => '5.000000', 'period' => 2592000]],
    '/v1/refunds/resolve' => [200, ['chain_id' => 8453, 'merchant' => '0x' . str_repeat('33', 20), 'payer' => '0x' . str_repeat('55', 20), 'amount_units' => '2500000']],
]);
$client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $stub]);

$resolved = $client->resolvePayment('p2f1.k1.body.mac');
check('resolvePayment posts the intent', $stub->calls[0]['payload'] === ['intent' => 'p2f1.k1.body.mac']);
check('and returns the display terms', $resolved['chain_id'] === 8453 && $resolved['confirmations_required'] === null);

$resolved = $client->resolveSubscription('p2setup2.k1.body.mac');
check('resolveSubscription posts the setup token', $stub->calls[1]['payload'] === ['setup_token' => 'p2setup2.k1.body.mac']);
check('and returns the typed data to sign', ($resolved['typed_data']['primaryType'] ?? '') === 'Authorization');

$finalized = $client->finalizeSubscription('p2setup2.k1.body.mac', '0x' . str_repeat('55', 20), '0x' . str_repeat('cd', 65));
check('finalizeSubscription sends token, payer and signature', $stub->calls[2]['payload'] === [
    'setup_token' => 'p2setup2.k1.body.mac',
    'payer' => '0x' . str_repeat('55', 20),
    'signature' => '0x' . str_repeat('cd', 65),
]);
check('and returns the charge capability', $finalized['subscription'] === 'p2s2.k1.body.mac');

$resolved = $client->resolveRefund('p2refund1.k1.body.mac');
check('resolveRefund posts the refund token', $stub->calls[3]['payload'] === ['refund_token' => 'p2refund1.k1.body.mac']);
check('and returns what it authorizes', $resolved['amount_units'] === '2500000');

$stub = new StubTransport(['/v1/subscriptions/finalize' => [400, ['error' => 'SIGNATURE_VALIDATION_TOO_EXPENSIVE']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $stub]);
try {
    $client->finalizeSubscription('p2setup2.k1.body.mac', '0x' . str_repeat('55', 20), '0x00');
    check('an unaffordable signature throws', false);
} catch (P2FluxException $e) {
    check('an unaffordable signature throws', $e->status === 'SIGNATURE_VALIDATION_TOO_EXPENSIVE');
    check('and points at the customer, the only one who can fix it', $e->action === 'CUSTOMER_ACTION_REQUIRED', $e->action);
}

// --- coverage the audit found missing --------------------------------------------------

$stub = new StubTransport(['/v1/refunds/prepare' => [200, ['refund_token' => 'p2refund1.k1.body.mac', 'refund_amount_units' => '2500000']]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $stub]);
$prep = $client->prepareRefund(['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc'], '2500000');
check('prepareRefund merges the amount into the original', $stub->calls[0]['payload'] === ['intent' => 'p2f1.k1.body.mac', 'tx_hash' => '0xabc', 'amount' => '2500000']);
check('and hands back the token', $prep['refund_token'] === 'p2refund1.k1.body.mac');

try {
    new P2FluxClient(['apiUrl' => '']);
    check('an empty apiUrl is refused', false);
} catch (\InvalidArgumentException $e) {
    check('an empty apiUrl is refused', true);
}

// --- the parity guard ------------------------------------------------------------------

/* The checked-in list of every public V1 merchant/server operation, mirrored in the JS SDK
 * (test/parity.test.ts) and in P2Flux/core. A new public endpoint is added to all three lists,
 * and each SDK fails here until it grows the method - an SDK can no longer fall behind silently.
 * Deliberately absent: /health (operational liveness), /metrics and /ready (loopback-only). */
$REQUIRED_OPERATIONS = [
    '/v1/payments',
    '/v1/payments/resolve',
    '/v1/payments/verify',
    '/v1/payments/recover',
    '/v1/subscriptions',
    '/v1/subscriptions/resolve',
    '/v1/subscriptions/finalize',
    '/v1/charges',
    '/v1/subscriptions/status',
    '/v1/subscriptions/revoke/session',
    '/v1/subscriptions/revoke/prepare',
    '/v1/allowances/revoke/prepare',
    '/v1/refunds/prepare',
    '/v1/refunds/resolve',
    '/v1/refunds/verify',
];

$stub = new StubTransport(['' => [200, ['status' => 'CHARGED', 'valid' => true, 'found' => true]]]);
$client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $stub]);
$hash = '0x' . str_repeat('ab', 32);
$client->createPayment(['recipient' => '0x' . str_repeat('33', 20), 'amount' => '1.00']);
$client->resolvePayment('p2f1.x');
$client->verifyPayment('p2f1.x', $hash);
$client->recoverPayment('p2f1.x');
$client->createSubscription(['recipient' => '0x' . str_repeat('33', 20), 'amount' => '1.00', 'period' => 3600]);
$client->resolveSubscription('p2setup2.x');
$client->finalizeSubscription('p2setup2.x', '0x' . str_repeat('55', 20), '0x00');
$client->charge('p2s2.x');
$client->status('p2s2.x');
$client->createCancellationSession('p2s2.x');
$client->prepareSubscriptionCancellation('p2s2.x');
$client->prepareAllowanceRevocation();
$client->prepareRefund(['intent' => 'p2f1.x', 'tx_hash' => $hash], '1000000');
$client->resolveRefund('p2refund1.x');
$client->verifyRefund(['intent' => 'p2f1.x', 'tx_hash' => $hash], '1000000', $hash);

$reached = array_values(array_unique(array_map(
    static fn (array $call): string => parse_url($call['url'], PHP_URL_PATH),
    $stub->calls
)));
sort($reached);
$expected = $REQUIRED_OPERATIONS;
sort($expected);
check('every public V1 merchant operation is reachable through the SDK', $reached === $expected,
    'missing: ' . implode(', ', array_diff($expected, $reached)) . ' extra: ' . implode(', ', array_diff($reached, $expected)));

echo $failures === 0 ? "\nphp sdk transport OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
