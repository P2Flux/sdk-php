# P2Flux PHP SDK — documentation

`p2flux/sdk-php` v0.7.2. A thin client over the P2Flux HTTP API: it normalizes result codes and
nothing else. No scheduler, no storage, no retry loops — your application owns all three.

This page is the index. The guide was split into one page per topic in 0.7.2.

| Page | What it covers |
|---|---|
| [Getting started](getting-started.md) | Install, requirements, configuration, your own HTTP client, environments, first call |
| [One-time payments](payments.md) | Create an intent, hosted checkout, server-side verification, recovery, what to trust |
| [Paying the network fee in USDC](network-fee-in-usdc.md) | `gas_payment_mode => 'payment_token'`: buyers who hold no ETH, accounting, limits, contracts |
| [Subscriptions](subscriptions.md) | Setup, the checkout handoff, charging, charge outcomes, `recoverCharge()`, allowance repair, cancellation |
| [Refunds](refunds.md) | Merchant-sent transfers, verified by P2Flux |
| [Errors and retries](errors.md) | Every public code, grouped by the action it implies |
| [Call and result contract](protocol-contract.md) | All 18 operations in one table, plus the transport contract |

Runnable versions of everything: [`examples/`](../examples/).

The JavaScript SDK (`@p2flux/sdk`) covers the identical public protocol surface. The full protocol
documentation lives at [p2flux.com/docs](https://p2flux.com/docs/), with the canonical
[OpenAPI specification](https://p2flux.com/openapi.json).
