# p2flux/p2flux-php

PHP client for the P2Flux payments API. PHP 8.1+, curl only, no framework and no Composer runtime
dependencies. This repository is the **canonical source** for the PHP SDK.

```php
use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'timeout' => 30]);

// One-time: create -> hosted checkout -> verify
$payment = $p2flux->createPayment(['recipient' => $merchantWallet, 'amount' => '12.50']);
// send the buyer to https://pay.p2flux.com/#/pay/{$payment['intent']}
$verdict = $p2flux->verifyPayment($payment['intent'], $txHash);

// Recurring: create -> hosted checkout authorizes -> finalize -> charge from YOUR renewal job
$setup  = $p2flux->createSubscription(['recipient' => $merchantWallet, 'amount' => '5.00', 'period' => 30 * 86400]);
$sub    = $p2flux->finalizeSubscription($setup['setup_token'], $payer, $signature);
$result = $p2flux->charge($sub['subscription']);   // never throws on a payment outcome
$state  = $p2flux->status($sub['subscription']);
```

This SDK covers the **complete public V1 merchant/server API** — the same 15 operations as the JS
SDK (`@p2flux/sdk`): one-time payments (create/resolve/verify with settlement receipts), recovery,
subscription setup/resolve/finalize/charge/status, cancellation sessions and preparation, allowance
revocation, and refunds (prepare/resolve/verify). No raw REST calls are needed for a normal
integration. `/health` is an operational liveness endpoint, not a merchant operation; `/metrics`
and `/ready` are loopback-only — none belongs in an SDK.

**Parity is tested, not promised.** `tests/transport.php` holds the checked-in list of all 15
public V1 merchant operations and fails if any stops being reachable through the client; the JS
SDK and P2Flux/core carry the same guard, so a new public operation turns every list red until
both SDKs support it.

## The one rule worth knowing

**`charge()` never throws on a payment outcome.** "The customer has no funds" is an answer, not an
error. Only transport-level surprises are exceptional, and those come back as `NETWORK_ERROR` /
`RETRY_LATER` rather than as a verdict — an unreachable API says nothing about whether the charge
landed, and treating it as a decline would let you cancel a subscription that just paid.

```php
$result = $p2flux->charge($ref);

if ($result->ok) {
    // CHARGED or ALREADY_CHARGED - both mean this period is paid, so a retry that races an
    // earlier success is not a double charge.
    return;
}
match ($result->action) {
    'WAIT'                     => null,                   // confirming; the money moved
    'RETRY_LATER'              => $this->retryLater(),
    'CUSTOMER_ACTION_REQUIRED' => $this->emailCustomer(), // top up or re-approve
    'STOP_SUBSCRIPTION'        => $this->stopBilling(),   // revoked or expired; final
};
```

The full result contract is in [`docs/protocol-contract.md`](docs/protocol-contract.md).

## Bring your own HTTP client

The `transport` option takes any callable, so a host framework supplies its own stack — WordPress's
`wp_remote_post`, Guzzle, Symfony HttpClient. The SDK itself pulls in nothing.

The callable receives the absolute URL, the payload as an array, and the timeout in seconds, and
must return `[int $httpStatus, array $decodedBody]` — the body decoded, not the JSON string.

```php
$p2flux = new P2FluxClient([
    'apiUrl'    => 'https://api.p2flux.com',
    'transport' => function (string $url, array $payload, int $timeout): array {
        $res = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => $timeout,
        ]);
        if (is_wp_error($res)) {
            throw new P2Flux\P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => $res->get_error_message()]);
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return [(int) wp_remote_retrieve_response_code($res), is_array($body) ? $body : []];
    },
]);
```

## Install

Not on Packagist yet. Until it is, install from this repository by tag:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/P2Flux/sdk-php" }],
  "require": { "p2flux/p2flux-php": "v0.5.0" }
}
```

Pin an exact tag. Vendoring a copy is fine too — but vendor a released tag, so there is still only
one place this code is edited.

## Examples

[`examples/`](examples/) — a one-time payment end to end (`one-time.php`), a subscription from
setup to cancellation (`subscription.php`), and a refund (`refund.php`).

## Tests

```bash
php tests/transport.php    # offline: stub transport, no API needed
```

`tests/smoke.php` runs the same client against a live API and is driven by the integration suite in
the private P2Flux/core repository, which supplies a running stub and a chargeable reference.

## License

MIT.
