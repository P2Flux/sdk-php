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

echo $failures === 0 ? "\nphp sdk transport OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
