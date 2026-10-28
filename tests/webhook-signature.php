<?php

declare(strict_types=1);

/**
 * Signed webhooks: the `p2flux-signature` header, checked against the same fixed vector the API's
 * own suite uses (P2Flux/core tests/webhooks.test.ts) and the JS SDK's.
 *
 *   php tests/webhook-signature.php
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

/** The status of the P2FluxException $work throws, or '' when it returns. */
function thrown(callable $work): string
{
    try {
        $work();
    } catch (P2FluxException $e) {
        return $e->status . '/' . $e->action;
    }

    return '';
}

// Seed 0x5a x 32, as in the API's vector.
const KID = '790901b82a89fe50';
const PUBLIC_KEY = 'DXVQdU4IAKXSN-71gmA1dmubPloVhoqUCrKJlYeI47A';
const BODY = '{"id":"evt_1","type":"payment.paid"}';
const T = 1800000000;
const HEADER = 't=1800000000,kid=790901b82a89fe50,v1=lwzmVe0dReBbLhyVjzTXtdT1W9EVhY7DPkr4C8WR7cGkXrxHOaZKWGpd3DMnhKvqbug464UZHtIHJ-IdGQNfAA';
$keys = [KID => PUBLIC_KEY];

$secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\x5a", 32)));
$signed = static fn (string $body, int $t): string => "t={$t},kid=" . KID . ',v1='
    . rtrim(strtr(base64_encode(sodium_crypto_sign_detached("{$t}.{$body}", $secret)), '+/', '-_'), '=');
check('the test signer reproduces the API vector', $signed(BODY, T) === HEADER);

$noApi = static function (): array {
    throw new RuntimeException('no API call expected');
};
$offline = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $noApi]);

check('the API vector verifies and returns the parsed event',
    $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T]) === ['id' => 'evt_1', 'type' => 'payment.paid']);

$invalid = 'WEBHOOK_SIGNATURE_INVALID/INVALID_REQUEST';
check('a changed body is WEBHOOK_SIGNATURE_INVALID', thrown(fn () => $offline->verifyWebhook(str_replace('paid', 'pa1d', BODY), HEADER, ['keys' => $keys, 'now' => T])) === $invalid);
check('an unknown kid is WEBHOOK_SIGNATURE_INVALID', thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => ['other' => PUBLIC_KEY], 'now' => T])) === $invalid);
check('another key is WEBHOOK_SIGNATURE_INVALID', thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => [KID => str_repeat('A', 43)], 'now' => T])) === $invalid);
check('a moved t is WEBHOOK_SIGNATURE_INVALID', thrown(fn () => $offline->verifyWebhook(BODY, str_replace('t=' . T, 't=' . (T - 1), HEADER), ['keys' => $keys, 'now' => T])) === $invalid);
foreach (['', 'garbage', 't=' . T . ',kid=' . KID, 't=x,kid=' . KID . ',v1=abc', 'kid=' . KID . ',v1=abc'] as $garbage) {
    check("a malformed header ({$garbage}) is WEBHOOK_SIGNATURE_INVALID", thrown(fn () => $offline->verifyWebhook(BODY, $garbage, ['keys' => $keys, 'now' => T])) === $invalid);
}
check('older than 300 s is WEBHOOK_TOO_OLD', thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T + 301])) === 'WEBHOOK_TOO_OLD/INVALID_REQUEST');
check('so is from the future', thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T - 301])) === 'WEBHOOK_TOO_OLD/INVALID_REQUEST');
check('tolerance_sec widens the window', thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T + 3600, 'tolerance_sec' => 3600])) === '');
check('a fresh signature verifies with the default clock', thrown(fn () => $offline->verifyWebhook(BODY, $signed(BODY, time()), ['keys' => $keys])) === '');

// Keys from capabilities, fetched once.
$calls = 0;
$api = static function (string $url) use (&$calls): array {
    $calls++;
    return [200, ['chain_id' => 8453, 'webhook_signing_keys' => [['kid' => KID, 'alg' => 'Ed25519', 'public_key' => PUBLIC_KEY]]]];
};
$client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'transport' => $api]);
$client->verifyWebhook(BODY, HEADER, ['now' => T]);
$client->verifyWebhook(BODY, HEADER, ['now' => T]);
check('keys come from capabilities once and are cached', $calls === 1, "calls: {$calls}");
$bare = new P2FluxClient(['apiUrl' => 'https://bare.p2flux.example', 'transport' => static fn (): array => [200, ['chain_id' => 8453]]]);
check('an API without signing keys verifies nothing', thrown(fn () => $bare->verifyWebhook(BODY, HEADER, ['now' => T])) === $invalid);

// handleNotification with the header.
$ref = '0x' . str_repeat('12', 32);
$event = json_encode(['id' => 'evt_2', 'type' => 'payment.paid', 'data' => ['reference' => $ref, 'intent' => 'p2f1.k.e30.s', 'tx_hash' => '0x' . str_repeat('ab', 32)]]);
$now = time();
$forged = $offline->handleNotification($event, ['reference' => $ref, 'signature' => $signed(str_replace('evt_2', 'evt_3', $event), $now), 'keys' => $keys]);
check('handleNotification refuses a forged body before asking the API', $forged === ['confirmed' => false, 'event_id' => '', 'type' => '', 'reason' => 'WEBHOOK_SIGNATURE_INVALID']);
check('and a stale one', $offline->handleNotification($event, ['reference' => $ref, 'signature' => $signed($event, $now - 3600), 'keys' => $keys])['reason'] === 'WEBHOOK_TOO_OLD');
$passed = $offline->handleNotification($event, ['reference' => $ref, 'signature' => $signed($event, $now), 'keys' => $keys]);
check('a good signature passes on to the usual checks', $passed['reason'] === 'NOT_YOUR_ORDER' && $passed['event_id'] === 'evt_2');
$threw = false;
try {
    $offline->handleNotification(json_decode($event, true), ['reference' => $ref, 'signature' => $signed($event, $now), 'keys' => $keys]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('a signature needs the raw body', $threw);

// tolerance_sec: an int >= 0 (a numeric string is cast); anything else is a programming error.
$badTolerance = static function (mixed $tolerance) use ($offline, $keys): bool {
    try {
        $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T + 3600, 'tolerance_sec' => $tolerance]);
    } catch (InvalidArgumentException) {
        return true;
    } catch (P2FluxException) {
    }

    return false;
};
check("tolerance_sec 'abc', -1, 1.5 or an array is an InvalidArgumentException", $badTolerance('abc') && $badTolerance(-1) && $badTolerance(1.5) && $badTolerance([]));
check("tolerance_sec '3600' (numeric string) is cast", thrown(fn () => $offline->verifyWebhook(BODY, HEADER, ['keys' => $keys, 'now' => T + 3600, 'tolerance_sec' => '3600'])) === '');

// The key cache is per process (per API URL), not per client: a host that builds a client per request
// (Blesta, PHP-FPM) does not fetch capabilities for every forged kid.
$calls = 0;
for ($i = 0; $i < 20; $i++) {
    $perRequest = new P2FluxClient(['apiUrl' => 'https://per-request.p2flux.example', 'transport' => $api]);
    thrown(fn () => $perRequest->verifyWebhook(BODY, "t=" . T . ",kid=forged{$i},v1=AAAA", ['now' => T]));
}
check('20 per-request clients, forged kids: one capabilities fetch', $calls === 1, "calls: {$calls}");
check('and the cached key still verifies on a new client', thrown(fn () => (new P2FluxClient(['apiUrl' => 'https://per-request.p2flux.example', 'transport' => $noApi]))->verifyWebhook(BODY, HEADER, ['now' => T])) === '');

echo $failures === 0 ? "\nwebhook signatures OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
