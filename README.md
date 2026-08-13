# p2flux/p2flux-php

PHP 8.1+ client over the P2Flux HTTP API. curl and json only, no framework. See
[`../README.md`](../README.md) for the result table and the integration model.

```php
use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'timeout' => 30]);

$setup  = $p2flux->createSubscription(['recipient' => $wallet, 'amount' => '10.00', 'period' => 2592000]);
$result = $p2flux->charge($ref);                          // never throws; check $result->ok
$state  = $p2flux->status($ref);                          // throws P2FluxException on a bad ref
$cancel = $p2flux->prepareSubscriptionCancellation($ref);
$stop   = $p2flux->prepareAllowanceRevocation();

$intent = $p2flux->createPayment(['recipient' => $wallet, 'amount' => '5.00']);
$proof  = $p2flux->verifyPayment($intent['intent'], $txHash);   // check $proof['valid'] === true
$link   = $p2flux->createCancellationSession($ref);             // $link['cancel_token'] for the browser
```

`charge()` returns a `ChargeResult` (`status`, `ok`, `alreadyPaid`, `action`, `retryable`,
`txHash`, `amount`, `subscriptionId`, `periodIndex`, `nextPeriodAt`, `raw`). The other calls throw
`P2FluxException` carrying the same `status`/`action` — except `verifyPayment()`, where a rejected
payment is `['valid' => false, 'code' => ...]` with HTTP 200.

Hosts with their own HTTP stack pass a `transport` callable and curl is never touched:

```php
$p2flux = new P2FluxClient([
    'apiUrl'    => $url,
    'transport' => function (string $url, array $payload, int $timeout): array {
        $res = wp_remote_post($url, [
            'body'    => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => $timeout,
        ]);
        if (is_wp_error($res)) {
            throw new \P2Flux\P2FluxException('NETWORK_ERROR', 'RETRY_LATER', []);
        }

        return [wp_remote_retrieve_response_code($res), json_decode(wp_remote_retrieve_body($res), true) ?: []];
    },
]);
```

Tests:

```bash
php tests/transport.php                                   # offline, stubbed transport
php tests/smoke.php http://127.0.0.1:3000 <p2s2 reference> # against a running API
```

`npm test` runs both automatically against the stub API (`tests/sdk.test.ts`).
