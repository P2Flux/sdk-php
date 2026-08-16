# p2flux/p2flux-php

PHP client for the P2Flux payments API. PHP 8.1+, curl only, no framework and no Composer runtime
dependencies. This repository is the **canonical source** for the PHP SDK.

```php
use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example', 'timeout' => 30]);

$result = $p2flux->charge($subscriptionRef);   // never throws on a payment outcome
$state  = $p2flux->status($subscriptionRef);
```

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

```php
$p2flux = new P2FluxClient([
    'apiUrl'    => 'https://api.p2flux.example',
    'transport' => function (string $url, array $options): array {
        $res = wp_remote_post($url, $options);
        return [wp_remote_retrieve_response_code($res), wp_remote_retrieve_body($res)];
    },
]);
```

## Install

Not on Packagist yet. Until it is, install from this repository by tag:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/P2Flux/sdk-php" }],
  "require": { "p2flux/p2flux-php": "v0.1.0" }
}
```

Pin an exact tag. Vendoring a copy is fine too — but vendor a released tag, so there is still only
one place this code is edited.

## Tests

```bash
php tests/transport.php    # offline: stub transport, no API needed
```

`tests/smoke.php` runs the same client against a live API and is driven by the integration suite in
the private P2Flux/core repository, which supplies a running stub and a chargeable reference.

## License

MIT.
