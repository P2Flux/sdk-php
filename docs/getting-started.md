# Getting started

The P2Flux PHP SDK is a thin client over the P2Flux HTTP API: it normalizes result codes and
nothing else. No scheduler, no storage, no retry loops — your application owns all three.

- [Install](#install)
- [Requirements](#requirements)
- [Your first call](#your-first-call)
- [Configuration](#configuration)
- [Your own HTTP client](#your-own-http-client)
- [Environments](#environments)
- [Where to go next](#where-to-go-next)

## Install

```bash
composer require p2flux/sdk-php
```

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| Extensions | `ext-json` required; `ext-curl` only for the default transport |
| Runtime dependencies | none |

A host that supplies its own HTTP client through the `transport` option never loads `ext-curl`.

## The words

P2Flux's vocabulary, once, so the rest of the documentation reads plainly.

| Term | What it is |
|---|---|
| **intent** | A signed one-time payment: recipient, amount, reference, all fixed. `createPayment()` mints it, `verifyPayment()` checks a transaction against it. Yours to store. |
| **reference** | The on-chain identifier inside an intent. P2Flux generates it; keep your own order id beside it. |
| **hosted checkout** | The page at `pay.p2flux.com` where the buyer's wallet does the work. Not part of this SDK. |
| **settlement receipt** | A short-lived sealed token proving a verification already happened. Passing it back makes a repeat verify instant. |
| **setup token** | A signed set of subscription terms, valid for fifteen minutes, that the checkout turns into a capability. |
| **salt** | Identifies one exact setup. Compare it in `status()` to prove a capability came from the setup you created. |
| **capability** (`p2s2…`) | The bearer credential that lets you charge a subscription. Encrypted, server-side, never in a URL or a log. |
| **period** | One billing interval, in seconds. The contract allows one charge per period, which is what makes retries safe. |
| **allowance** | The ERC-20 permission the customer grants. It can run out without the subscription being dead — that is `INSUFFICIENT_ALLOWANCE`. |
| **sponsorship** | P2Flux sending the transaction for a buyer with no ETH, who reimburses the network fee in USDC. |
| **base units** | Integer USDC: 1 USDC = 1000000. Every `_units` field is one of these. |


## Your first call

`capabilities()` needs no credentials and moves no money — it is the quickest proof that your
client reaches the API.

```php
require 'vendor/autoload.php';

use P2Flux\P2FluxClient;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com']);

$caps = $p2flux->capabilities();
echo $caps['chain_id'];            // 8453 on Base Mainnet
echo $caps['tokens'][0]['symbol']; // USDC
```

## Configuration

**There is no API key.** V1 has no API authentication: a payment is bound to an exact recipient,
amount and period by the customer's own signature, and the contract refuses a second charge in a
period. What you configure is which deployment to talk to.

```php
$p2flux = new P2FluxClient([
    'apiUrl'  => 'https://api-test.p2flux.com',   // required
    'timeout' => 30,                              // optional, seconds; default 60
    'transport' => $callable,                     // optional, see below
]);
```

`timeout` defaults to 60 seconds because a charge waits for on-chain confirmation, which can take
tens of seconds on a busy public RPC. A timed-out charge is safe: the next call answers
`ALREADY_CHARGED`.

The examples in [`examples/`](../examples/) read their configuration from the environment, which is
where a wallet address or a stored capability belongs — never in source:

| Variable | Used for |
|---|---|
| `P2FLUX_API_URL` | API base URL (default `https://api.p2flux.com`) |
| `P2FLUX_CHECKOUT_URL` | Hosted checkout base URL (default `https://pay.p2flux.com`) |
| `P2FLUX_RECIPIENT` | Your payout wallet |
| `P2FLUX_INTENT`, `P2FLUX_TX_HASH` | An existing payment being verified |
| `P2FLUX_SUBSCRIPTION` | A stored `p2s2` capability |

## Your own HTTP client

The `transport` option is a callable that receives the absolute URL, the payload as an array and
the timeout in seconds, and returns `[int $httpStatus, array $decodedBody]` — the body decoded, not
the JSON string. Throw `P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ...)` when the request never
reached the API; `charge()` turns that into a retryable result rather than a decline.

```php
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

$p2flux = new P2FluxClient([
    'apiUrl'    => 'https://api.p2flux.com',
    'transport' => function (string $url, array $payload, int $timeout): array {
        $res = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload === [] ? new \stdClass() : $payload),
            'timeout' => $timeout,   // WordPress defaults to 5 s, which abandons most charges
        ]);
        if (is_wp_error($res)) {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => $res->get_error_message()]);
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return [(int) wp_remote_retrieve_response_code($res), is_array($body) ? $body : []];
    },
]);
```

Note the empty-payload case: an operation with no arguments (`prepareAllowanceRevocation()`) must
send `{}`, not `[]`.

## Environments

| | API | Hosted checkout | Chain |
|---|---|---|---|
| Test | `https://api-test.p2flux.com` | `https://pay-test.p2flux.com` | Base Sepolia (84532), faucet USDC |
| Production | `https://api.p2flux.com` | `https://pay.p2flux.com` | Base Mainnet (8453), real USDC |

Both environments support paying the network fee in USDC.

The two are separate deployments with separate signing keys. **Every token — intent, setup token,
capability, cancel token, refund token, approve token — is bound to the deployment that issued it**
and is refused by the other one. An integration that lets a test-environment capability reach a
production client (or the reverse) gets `INVALID_SUBSCRIPTION`, never a charge on the wrong chain.

Store the environment alongside every order and subscription you create, and build the client for
that stored environment when you verify, charge, recover or refund it later.

## Where to go next

- [The payment lifecycle](payment-flow.md) — the whole merchant flow, browser half included
- [One-time payments](payments.md) — create, hosted checkout, server-side verification
- [Paying the network fee in USDC](network-fee-in-usdc.md) — buyers who hold no ETH
- [Subscriptions](subscriptions.md) — setup, charging, allowance repair, cancellation
- [Refunds](refunds.md)
- [Recovery](recovery.md) — when a response or a callback is lost
- [Errors and retries](errors.md)
- [Laravel](frameworks/laravel.md) · [Symfony](frameworks/symfony.md) · [Testing](testing.md)
- [Production checklist](production-checklist.md)
- [Call and result contract](protocol-contract.md) — every method, one table
- [`examples/`](../examples/) — runnable versions of everything here
