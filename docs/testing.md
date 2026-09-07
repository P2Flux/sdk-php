# Testing your integration

Everything below runs offline. No wallet, no USDC, no chain, no API.

The SDK takes a `transport` callable, so a test replaces the HTTP layer without touching your own
code. That is the whole mechanism — there is nothing else to mock.

## The transport contract

```php
$transport = fn (string $url, array $payload, int $timeout): array => [$httpStatus, $decodedBody];
```

It receives the absolute URL, the request payload as an array and the timeout in seconds, and
returns the HTTP status with the **decoded** body. Throw
`P2FluxException('NETWORK_ERROR', 'RETRY_LATER', [...])` to simulate a request that never reached the
API.

## A fake transport

Routes by path suffix and records what was sent, which is usually all a test needs:

```php
use P2Flux\P2FluxException;

final class FakeP2Flux
{
    /** @var list<array{url: string, payload: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses keyed by path suffix */
    public function __construct(private array $responses = [])
    {
    }

    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];

        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        }

        return [404, ['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']];
    }
}
```

## Canned responses

Enough to cover every branch your integration has:

```php
use P2Flux\P2FluxClient;

$fake = new FakeP2Flux([
    '/v1/capabilities' => [200, [
        'chain_id' => 8453,
        'network' => 'Base',
        'tokens' => [[
            'symbol' => 'USDC',
            'decimals' => 6,
            'gas_payment_modes' => ['native', 'payment_token'],
            'operations' => ['one_time_payment' => true],
        ]],
    ]],
    '/v1/payments/verify' => [200, [
        'valid' => true,
        'tx_hash' => '0x' . str_repeat('1', 64),
        'block_number' => 50966621,
        'amount' => '12.500000',
        'settlement_receipt' => 'p2r2.k1.test.mac',
    ]],
    '/v1/payments' => [200, [
        'intent' => 'p2f1.k1.test.mac',
        'reference' => '0xref',
        'amount' => '12.500000',
    ]],
]);

$p2flux = new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $fake]);
```

The rejection and waiting branches, which are the ones integrations get wrong:

```php
// still confirming: your code must poll, not re-charge and not fulfil
'/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => '0xabc']],

// this transaction settles nothing: the order stays unpaid
'/v1/payments/verify' => [200, ['valid' => false, 'code' => 'TRANSACTION_NOT_FOUND']],

// the buyer's wallet hit the sponsored-transaction limit; nothing was spent
'/v1/payments' => [429, ['error' => 'RATE_LIMITED', 'action' => 'RETRY_LATER', 'retry_after' => 120]],

// charge outcomes
'/v1/charges' => [200, ['status' => 'CHARGED', 'action' => 'SUCCESS', 'tx_hash' => '0xdef', 'period_index' => 3]],
'/v1/charges' => [200, ['status' => 'ALREADY_CHARGED', 'action' => 'SUCCESS', 'period_index' => 3]],
'/v1/charges' => [200, ['status' => 'INSUFFICIENT_BALANCE', 'action' => 'CUSTOMER_ACTION_REQUIRED']],
```

An unreachable API — the case where the answer is "unknown", never "declined":

```php
$fake = new FakeP2Flux([
    '/v1/payments/verify' => new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => 'timeout']),
]);
```

## A test

PHPUnit here, but nothing depends on it — the SDK ships no dev dependencies and the fake is 20
lines of plain PHP.

```php
use PHPUnit\Framework\TestCase;
use P2Flux\P2FluxClient;

final class CheckoutTest extends TestCase
{
    public function test_an_order_is_paid_only_on_a_valid_verdict(): void
    {
        $fake = new FakeP2Flux(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING']]]);
        $order = new Order(intent: 'p2f1.k1.test.mac');

        (new Checkout(new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $fake])))
            ->verify($order, '0xabc');

        self::assertSame('pending', $order->status);
    }

    public function test_the_recipient_is_never_taken_from_the_request(): void
    {
        $fake = new FakeP2Flux(['/v1/payments' => [200, ['intent' => 'p2f1.k1.test.mac', 'reference' => '0x', 'amount' => '12.500000']]]);

        (new Checkout(new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $fake])))
            ->create('12.50');

        self::assertSame(config('p2flux.recipient'), $fake->calls[0]['payload']['recipient']);
    }
}
```

Assert on `$fake->calls` for the things that matter: that the recipient came from your config, that
the amount matches the order, that `charge()` was called once per period.

## In Laravel

Replace the container binding, and the rest of your application is untouched:

```php
$fake = new FakeP2Flux(['/v1/payments/verify' => [200, ['valid' => true, 'tx_hash' => '0xabc']]]);

$this->app->instance(P2FluxClient::class, new P2FluxClient([
    'apiUrl' => 'https://api.example',
    'transport' => $fake,
]));
```

## In Symfony

Override the service for the test environment in `config/services_test.yaml`:

```yaml
services:
    P2Flux\P2FluxClient:
        arguments:
            $options:
                apiUrl: 'https://api.example'
                transport: '@App\Tests\FakeP2Flux'

    App\Tests\FakeP2Flux:
        public: true
```

Then fetch it from the container with `self::getContainer()->get(FakeP2Flux::class)` to assert on
what was sent.

## Against a running canned API

For end-to-end tests through your HTTP layer, this repository ships one:
`tests/stub-api.php` answers like the real API over loopback.

```bash
php -S 127.0.0.1:8100 tests/stub-api.php &
P2FLUX_API_URL=http://127.0.0.1:8100 vendor/bin/phpunit
```

It answers `PAYMENT_CONFIRMING` for a transaction hash starting `0xc0` and `TRANSACTION_NOT_FOUND`
for one starting `0xbad`, so the waiting and rejection paths are reachable without a chain.
[`examples/complete-payment-flow/`](../examples/complete-payment-flow/) runs against exactly this.

## Against the test environment

When you do want real chain behaviour, point at Base Sepolia and faucet USDC:

```dotenv
P2FLUX_API_URL=https://api-test.p2flux.com
P2FLUX_CHECKOUT_URL=https://pay-test.p2flux.com
```

Tokens are bound to the deployment that issued them, so a test capability is refused by production
and the reverse — see [Getting started](getting-started.md#environments).

## Next

- [Errors and retries](errors.md) — the codes worth a test each
- [Production checklist](production-checklist.md)
