# Charge AI agents (x402 paywall)

`P2Flux\Paywall` charges AI agents for a page or an API route, in USDC, over x402. Live on Base
Mainnet and Base Sepolia. No x402 library, no account, no API key.

Runnable: [`examples/paywall.php`](../examples/paywall.php).

```php
use P2Flux\P2FluxClient;
use P2Flux\Paywall;

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com']);   // https://api-test.p2flux.com for Base Sepolia
$paywall = new Paywall($p2flux, ['recipient' => '0xYourWallet', 'price' => '0.05']);
```

You say who is paid and how much. P2Flux builds what the agent signs and settles what it sends. The
payment is settled **before** you serve: one payment, one response. The money goes to your wallet in
the settlement transaction; P2Flux takes its fee on chain and never holds funds.

The network comes from the client's `apiUrl`: `https://api.p2flux.com` is Base with real USDC,
`https://api-test.p2flux.com` is Base Sepolia.

## The cycle

1. A request without payment gets `402 Payment Required`. The `PAYMENT-REQUIRED` header (base64 JSON)
   says what to pay and to whom.
2. The agent signs a USDC payment and repeats the request with a `PAYMENT-SIGNATURE` header. Pass
   the legacy `X-PAYMENT` header when there is no `PAYMENT-SIGNATURE`.
3. The paywall hands the header to P2Flux, which settles it on Base.
4. You serve the content with the `PAYMENT-RESPONSE` header.

The agent needs USDC on Base and no ETH.

## Options

| Option | Default | |
|---|---|---|
| `recipient` | required | Your wallet on Base. Every payment goes to it. |
| `price` | required | USDC per request, e.g. `'0.05'`. At least `0.01`. |
| `agentsOnly` | `false` | `true`: only AI agents and programs pay; browsers and search engines pass free |
| `prepaid` | `true` | Offer the prepaid balance next to pay-per-request, when P2Flux offers it |
| `onUnavailable` | `'refuse'` | What happens when P2Flux cannot be reached. See below. |
| `cacheGet` | none | `fn (string $key): mixed`, returning `null` for a miss |
| `cacheSet` | none | `fn (string $key, mixed $value, int $ttlSeconds): void` |

A missing `recipient` or `price` throws `InvalidArgumentException`. A recipient or price P2Flux
refuses (a malformed wallet, a price below 0.01) throws `P2FluxException` with action
`INVALID_REQUEST`: a configuration bug to fix, not a 402.

## Guarding a request

```php
$url = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$result = $paywall->guard(
    $_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMENT'] ?? null,
    $url,                                    // the full URL the client asked for
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    ['price' => '0.10', 'mimeType' => 'application/json', 'signed' => isset($_SERVER['HTTP_SIGNATURE_AGENT'])],
);

foreach ($result['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
if (!$result['allow']) {
    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode($result['body']);
    exit;
}
// serve the content
```

The fourth argument is optional:

| Key | |
|---|---|
| `price` | A different price for this request |
| `mimeType` | The `resource.mimeType` in the 402. Default `text/html`. |
| `signed` | The request carries a `Signature-Agent` header (Web Bot Auth). See agents only, below. |

The result is an array:

| Key | |
|---|---|
| `allow` | `true`: serve the content. `false`: answer `status`, `headers` and `body` instead. |
| `paid` | `allow: true` only. `false` when the request passed free (`agentsOnly`, or `onUnavailable` `'free'`). |
| `status` | `allow: false` only. `402` pay first; `503` P2Flux unreachable; `200` see below. |
| `headers` | Send them, whatever `allow` is. |
| `body` | `allow: false` only. The JSON to send. |
| `payer` | The agent's wallet, when P2Flux reports it. |
| `transaction` | The settlement transaction hash. Absent for a prepaid payment. |
| `receipt` | Prepaid only: a unique id for this paid request. |
| `scheme` | `exact` (pay-per-request) or `batch-settlement` (prepaid). |

`status` 200 with `allow` false is an agent taking its unused prepaid balance back. The body is
`{"refunded": true}` and the headers carry its receipt. Serve no content; send it as it is.

The headers:

| Header | When |
|---|---|
| `PAYMENT-REQUIRED` | On a 402: what to pay, base64 JSON |
| `PAYMENT-RESPONSE` | On a paid request, and on a prepaid refund: the settlement, base64 JSON |
| `Retry-After: 60` | On a 503 |
| `Cache-Control: no-store, private` | On every answer except a request that passed free |

## The cache

Give the paywall your cache (APCu, Redis, your framework's cache). Without one:

- every unpaid request asks P2Flux for the payment requirement;
- a payment already used is remembered by this PHP process only. Under PHP-FPM that is one request.

With `cacheGet` and `cacheSet`:

- the requirement is kept for the TTL P2Flux gives it, between 60 and 3600 seconds (an hour today);
- a payment already used is refused for 10 minutes without asking P2Flux.

P2Flux refuses a used payment either way: a payment is settled once. The cache saves the round trip.

```php
$paywall = new Paywall($p2flux, [
    'recipient' => '0xYourWallet',
    'price' => '0.05',
    'cacheGet' => static fn (string $key): mixed => apcu_fetch($key) ?: null,
    'cacheSet' => static function (string $key, mixed $value, int $ttl): void {
        $value === null ? apcu_delete($key) : apcu_store($key, $value, $ttl);
    },
]);
```

`cacheSet` is called with `null` and a TTL of 1 to forget a key: delete it, or store it for one second.

## When P2Flux cannot be reached

This is a decision, not a detail. Choose it:

- `'onUnavailable' => 'refuse'` (default): `status` 503, `Retry-After: 60`, body
  `{"error": "payment_service_unavailable"}`. Nothing is served unpaid.
- `'onUnavailable' => 'free'`: the request is served without payment (`allow` true, `paid` false).
  Content stays available while P2Flux is down, and nobody pays for it.

`INVALID_REQUEST` is not "unavailable": it throws, as above.

## Agents only

With `'agentsOnly' => true`, people read free and agents pay. `Paywall::isAgent($userAgent,
$hasPayment, $signed)` decides, in this order:

1. A request carrying a payment is an agent.
2. An empty user agent, or `node`, is an agent.
3. Search engines and link previews (Googlebot, bingbot, Applebot, Slackbot, Twitterbot and others)
   are never asked to pay.
4. A signed request (`'signed' => true`, a `Signature-Agent` header) is an agent. The signature is
   not verified here; it only decides who is asked to pay.
5. A user agent containing one of `Paywall::AGENT_SIGNATURES` is an agent: AI crawlers and
   assistants (GPTBot, ClaudeBot, PerplexityBot and others) and HTTP libraries (`curl/`,
   `python-requests`, `Go-http-client` and others). The list is the same as the JS SDK's and the
   WordPress plugin's.

Everything else passes free. A user agent is easy to fake: use `agentsOnly` for content you are
happy to show people, not for an API.

## Prepaid balance

Next to pay-per-request, the 402 offers x402 batch-settlement when P2Flux offers it. The agent puts
USDC aside once in the standard x402 escrow contract and then pays each request with a signed voucher,
with no transaction per request. You are paid out through an on-chain vault contract, less 3%. The
agent's unused balance stays its own; its request to take it back is answered for you (`status` 200,
above).

`'prepaid' => false` offers pay-per-request only.

## Usage pricing

When the cost is known only after the work (tokens, rows, seconds), the agent signs for at most
`$maxPrice` (x402 `upto`) and you charge what it cost, at least 0.01:

```php
$result = $paywall->usage($_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? null, $url, '1', function () {
    $rows = run_query();
    return ['amount' => number_format(count($rows) * 0.001, 6, '.', ''), 'value' => $rows];
}, $_SERVER['HTTP_USER_AGENT'] ?? null);
```

Arguments after the callable: the user agent, and the `mimeType` (default `application/json`). The
work runs only after P2Flux confirmed the payment will settle, and once per payment. If the
settlement then fails, the value is not returned. A paid result carries `value`, `amount` (what was
charged), `payer` and `transaction`. An amount above `$maxPrice` throws `P2FluxException`.

## The calls underneath

`Paywall` wraps three client methods. Use them directly to build your own:

| Method | |
|---|---|
| `paywallChallenge($recipient, $price, $usage = false)` | `accepts` and `ttl`: put `accepts`, with the URL as `resource`, in the base64 JSON of a 402's `PAYMENT-REQUIRED` header. Reusable for `ttl` seconds. |
| `paywallRedeem($recipient, $price, $paymentHeader, $resource = '', $amount = null)` | Settles the agent's header for your wallet and price. `paid: true`: serve once, with `payment_response` as the `PAYMENT-RESPONSE` header. A refusal is `paid: false` with `reason`, not an exception. |
| `paywallVerify($recipient, $maxPrice, $paymentHeader)` | Usage pricing: `valid: true` means the payment will settle for up to `$maxPrice`. Nothing moves. Do the work, then redeem with `$amount`. |

All three throw `P2FluxException` on a transport failure or a refused configuration.

## Fees

| | P2Flux fee |
|---|---|
| Pay-per-request | 1%, at least 0.003 USDC |
| Prepaid | 3% |

The fee is split out on chain. There is no free tier.

## From test to live

1. Run the whole cycle on `https://api-test.p2flux.com` first: a request without payment gets 402, an
   agent with test USDC pays, the repeat gets 200 with `PAYMENT-RESPONSE`. The
   [P2Flux MCP server](https://p2flux.com/docs/mcp.html) can play the agent.
2. Set `recipient` to a mainnet wallet **you control**. Payments to it are final.
3. Set the client's `apiUrl` to `https://api.p2flux.com`.
4. Decide `onUnavailable` on purpose, and give the paywall a cache.
5. Check `price` per route: real USDC from here on.

Laravel: `p2flux/laravel` ships this as the `p2flux.paywall` middleware.

More: [AI agent payments](https://p2flux.com/docs/agents.html) on p2flux.com.
