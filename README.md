# p2flux/p2flux-php

PHP client for the P2Flux payments API. PHP 8.1+, curl only, no framework and no Composer runtime
dependencies. This repository is the **canonical source** for the PHP SDK. The full integration guide is
[`docs/guide.md`](docs/guide.md); the call-and-result contract is
[`docs/protocol-contract.md`](docs/protocol-contract.md). Version numbers are shared with the JS
SDK: both are v0.7.0 and expose the same public operations.

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

// Lost the response? ALREADY_CHARGED proves the period was collected and names no transaction.
$settled = $p2flux->recoverCharge($sub['subscription'], $result->periodIndex ?? 0);
```

This SDK covers the **complete public V1 merchant/server API** — the same 18 operations as the JS
SDK (`@p2flux/sdk`): one-time payments (create/resolve/verify with settlement receipts), recovery,
subscription setup/resolve/finalize/charge/status, recurring settlement recovery, cancellation
sessions and preparation, allowance revocation and repair, and refunds (prepare/resolve/verify). No raw REST calls are needed for a normal
integration. `/health` is an operational liveness endpoint, not a merchant operation; `/metrics`
and `/ready` are loopback-only — none belongs in an SDK.

**Parity is tested, not promised.** `tests/transport.php` holds the checked-in list of all 18
public V1 merchant operations and fails if any stops being reachable through the client; the JS
SDK and P2Flux/core carry the same guard, so a new public operation turns every list red until
both SDKs support it.


## Paying the network fee in USDC — no ETH required

Live on Base Mainnet and Base Sepolia. Pass `'gas_payment_mode' => 'payment_token'` when you create a
payment and the hosted checkout lets a buyer who holds USDC and no ETH pay by signing: P2Flux sends
the transaction and pays the Base gas, the buyer pays a quoted network fee in USDC in the same
transaction, and your share still settles straight to your wallet. Subscription signup, allowance
restore and allowance removal work the same way from the hosted checkout, with no change on your side.

```php
$caps = $p2flux->capabilities();                      // ask before offering it
$usdc = array_values(array_filter($caps['tokens'], fn ($t) => $t['symbol'] === 'USDC'))[0] ?? null;
$canSponsor = $usdc && in_array('payment_token', $usdc['gas_payment_modes'], true);

$payment = $p2flux->createPayment([
    'recipient' => $merchantWallet,
    'amount' => '12.50',
    'gas_payment_mode' => $canSponsor ? 'payment_token' : 'native',
]);
// send the buyer to https://pay.p2flux.com/#/pay/{$payment['intent']}

$verdict = $p2flux->verifyPayment($payment['intent'], $txHash);
// $verdict['gas_payment_mode'] === 'payment_token'
// $verdict['accounting']: payment_units, payment_fee_units, network_fee_units, fixed_network_fee_units,
//                         merchant_net_units, buyer_total_units, payer  (USDC base units)
```

The buyer is debited the price plus the quoted network fee and nothing else. P2Flux's 1% and the
fixed 0.10 USDC network fee both come out of the amount, so you fund them exactly as a subscription
collection does. USDC is never converted to ETH.

Per buyer wallet, sponsored transactions are limited to 10 in any rolling hour and 20 in any rolling
day across all merchants and operations. A refused attempt answers `RATE_LIMITED` (HTTP 429, with
`retry_after`) and costs nothing; the hosted checkout tells the buyer to try again later, or to pay
the network fee with ETH where the wallet can. Your `charge()` calls are never counted.

Contracts: Base Mainnet `P2FluxSponsoredSplitter` `0x95E18ec05D4282acB3aab7aD60325bA4EEeEa8df`,
`P2FluxGasSponsor` `0xD1DDAaa301403d18fD4A23Fc69493ef48af90285`; Base Sepolia
`0x876f7b98e8c06291ec916a3223a92038b0a8774f`, `0x2dc51643040d7c396f1199a0664ac095d4b89ec5`.
`capabilities()` returns them per operation as `sponsor_contracts`.

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
  "require": { "p2flux/p2flux-php": "v0.7.0" }
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
