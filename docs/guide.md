# P2Flux PHP SDK — documentation

`p2flux/sdk-php` v0.7.3. A thin client over the P2Flux HTTP API: it normalizes result codes and
nothing else. No scheduler, no storage, no retry loops — your application owns all three.

This page is the index.

## Start here

| Page | What it covers |
|---|---|
| [Getting started](getting-started.md) | Install, requirements, configuration, the vocabulary, your own HTTP client, environments |
| [The payment lifecycle](payment-flow.md) | The whole merchant flow end to end, and which step may mark an order paid |

## Operations

| Page | What it covers |
|---|---|
| [One-time payments](payments.md) | Create an intent, hosted checkout, server-side verification |
| [Paying the network fee in USDC](network-fee-in-usdc.md) | `gas_payment_mode => 'payment_token'`: buyers who hold no ETH, accounting, limits, contracts |
| [Subscriptions](subscriptions.md) | Setup, the checkout handoff, charging, charge outcomes, allowance repair, cancellation |
| [Refunds](refunds.md) | Merchant-sent transfers, verified by P2Flux |
| [Recovery](recovery.md) | A lost payment, a lost charge, and the ambiguous request |

## Building it

| Page | What it covers |
|---|---|
| [Laravel](frameworks/laravel.md) | Container binding, injected controller, verify endpoint, scheduler command |
| [Symfony](frameworks/symfony.md) | Service definition, autowired controller, verify endpoint, console command |
| [Testing](testing.md) | Fake transport, canned responses per code, PHPUnit shape, the canned API |
| [Errors and retries](errors.md) | Every public code, grouped by action, with a recipe per situation |
| [Production checklist](production-checklist.md) | What to confirm before real money |
| [Call and result contract](protocol-contract.md) | All 18 operations in one table, plus the transport contract |

## Examples

| File | |
|---|---|
| [`create-payment.php`](../examples/create-payment.php) | Intent → hosted checkout URL |
| [`verify-payment.php`](../examples/verify-payment.php) | The trust boundary |
| [`recover-payment.php`](../examples/recover-payment.php) | Find a settlement whose hash was lost |
| [`create-sponsored-payment.php`](../examples/create-sponsored-payment.php) | A buyer paying with USDC and no ETH |
| [`network-fee-in-usdc.php`](../examples/network-fee-in-usdc.php) | The accounting block, figure by figure |
| [`subscription-signup.php`](../examples/subscription-signup.php) | Terms → checkout → prove the capability |
| [`charge-subscription.php`](../examples/charge-subscription.php) | One period, every outcome |
| [`recover-charge.php`](../examples/recover-charge.php) | The settlement behind an `ALREADY_CHARGED` |
| [`refund.php`](../examples/refund.php) | Prepare, send from your wallet, verify |
| [`complete-payment-flow/`](../examples/complete-payment-flow/) | A runnable merchant integration |

The JavaScript SDK (`@p2flux/sdk`) covers the identical public protocol surface. The full protocol
documentation lives at [p2flux.com/docs](https://p2flux.com/docs/), with the canonical
[OpenAPI specification](https://p2flux.com/openapi.json).
