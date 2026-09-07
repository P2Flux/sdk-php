# Laravel

There is no P2Flux Laravel package, and none is needed. The SDK is a plain PHP class with no
framework ties: bind it once in the container and inject it like any other service.

Tested shape: Laravel 11/12 on PHP 8.2+. Nothing here is Laravel-version-specific.

## Install

```bash
composer require p2flux/sdk-php
```

## Configure

`.env`:

```dotenv
P2FLUX_API_URL=https://api-test.p2flux.com
P2FLUX_CHECKOUT_URL=https://pay-test.p2flux.com
P2FLUX_RECIPIENT=0xYourPayoutWallet
P2FLUX_TIMEOUT=30
```

`config/p2flux.php`:

```php
<?php

return [
    'api_url' => env('P2FLUX_API_URL', 'https://api.p2flux.com'),
    'checkout_url' => env('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com'),
    'recipient' => env('P2FLUX_RECIPIENT'),
    'timeout' => (int) env('P2FLUX_TIMEOUT', 30),
];
```

There is no API key: P2Flux v1 has no API authentication, and a payment is bound to its recipient
and amount by the buyer's signature. `P2FLUX_RECIPIENT` is your public payout wallet, not a secret.

## Bind the client

`app/Providers/AppServiceProvider.php`:

```php
use Illuminate\Contracts\Foundation\Application;
use P2Flux\P2FluxClient;

public function register(): void
{
    $this->app->singleton(P2FluxClient::class, function (Application $app): P2FluxClient {
        return new P2FluxClient([
            'apiUrl' => $app['config']->get('p2flux.api_url'),
            'timeout' => $app['config']->get('p2flux.timeout'),
        ]);
    });
}
```

One binding, injected everywhere. Prefer this to a facade or a static helper: constructor injection
is what lets you hand a fake client to a test — see [Testing](../testing.md).

## Create a payment

```php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

class PaymentController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function create(Request $request): JsonResponse
    {
        $order = Order::create([
            'user_id' => $request->user()->id,
            'amount' => '12.50',
            'status' => 'pending',
        ]);

        try {
            // The recipient and the amount come from config and from your own records - never
            // from the request body. A browser must not be able to choose either.
            $payment = $this->p2flux->createPayment([
                'recipient' => config('p2flux.recipient'),
                'amount' => $order->amount,
            ]);
        } catch (P2FluxException $e) {
            report($e);

            return response()->json(['error' => $e->status], 502);
        }

        $order->update(['p2flux_intent' => $payment['intent']]);

        return response()->json([
            'order' => $order->id,
            'checkout' => config('p2flux.checkout_url') . '/#/pay/' . rawurlencode($payment['intent']),
        ]);
    }
}
```

## Verify before fulfilling

The browser posts the claim here. This action is the trust boundary, and it must be safe to call
twice.

```php
public function verify(Request $request): JsonResponse
{
    $input = $request->validate([
        'order' => ['required', 'integer'],
        'tx_hash' => ['required', 'string'],
        'settlement_receipt' => ['nullable', 'string'],
    ]);

    $order = Order::where('user_id', $request->user()->id)->findOrFail($input['order']);

    if ($order->status === 'paid') {
        return response()->json(['status' => 'paid', 'tx_hash' => $order->tx_hash]);
    }

    try {
        $verdict = $this->p2flux->verifyPayment(
            $order->p2flux_intent,
            $input['tx_hash'],
            $input['settlement_receipt'] ?? null,
        );
    } catch (P2FluxException $e) {
        // The request never reached a verdict. Unknown, not rejected - the client retries.
        return response()->json(['status' => 'unavailable', 'code' => $e->status], 503);
    }

    if (($verdict['valid'] ?? false) !== true) {
        return ($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING'
            ? response()->json(['status' => 'confirming'], 202)
            : response()->json(['status' => 'unsettled', 'code' => $verdict['code'] ?? null]);
    }

    // Fulfil exactly once: re-check the status under a row lock, inside the transaction.
    DB::transaction(function () use ($order, $verdict): void {
        $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
        if ($fresh->status === 'paid') {
            return;
        }
        $fresh->update([
            'status' => 'paid',
            'tx_hash' => $verdict['tx_hash'],
            'settlement_receipt' => $verdict['settlement_receipt'] ?? null,
        ]);
        // Dispatch fulfilment from here, so it happens once with the status change.
    });

    return response()->json(['status' => 'paid', 'tx_hash' => $verdict['tx_hash']]);
}
```

Routes:

```php
Route::post('/payments', [PaymentController::class, 'create'])->middleware('auth');
Route::post('/payments/verify', [PaymentController::class, 'verify'])->middleware('auth');
```

## Recurring charges from the scheduler

P2Flux has no scheduler. Your renewal job decides a period is due and calls `charge()`, which never
throws on a payment outcome:

```php
namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use P2Flux\P2FluxClient;

class ChargeDueSubscriptions extends Command
{
    protected $signature = 'p2flux:charge-due';

    public function handle(P2FluxClient $p2flux): int
    {
        foreach (Subscription::due()->cursor() as $subscription) {
            $result = $p2flux->charge(decrypt($subscription->capability));

            match (true) {
                $result->ok => $subscription->markPeriodPaid($result->periodIndex, $result->txHash),
                $result->status === 'CONFIRMING' => null,                      // poll; never charge again
                $result->action === 'STOP_SUBSCRIPTION' => $subscription->stop($result->status),
                $result->action === 'CUSTOMER_ACTION_REQUIRED' => $subscription->needsCustomer($result->status),
                default => $this->warn("retry later: {$result->status}"),
            };
        }

        return self::SUCCESS;
    }
}
```

Store the `p2s2` capability encrypted (Laravel's `encrypt()`/`decrypt()` or an
`encrypted` cast). It is a bearer credential: server-side only, never in a log or a URL.

## Exception handling

`P2FluxException` carries `->status`, `->action` and `->raw`. Classify on `->action`, so a code this
client has never seen still lands in the right branch:

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (P2FluxException $e) {
        return match ($e->action) {
            'INVALID_REQUEST' => response()->json(['error' => $e->status], 422),
            'RETRY_LATER' => response()->json(['error' => $e->status], 503),
            default => response()->json(['error' => $e->status], 502),
        };
    });
})
```

Log `$e->status` and your own order id. Never log the capability, a setup token or an intent.

## Next

- [Testing](../testing.md) — swap the client for a fake with `$this->app->instance(...)`
- [The payment lifecycle](../payment-flow.md) · [Production checklist](../production-checklist.md)
- [`examples/complete-payment-flow/`](../../examples/complete-payment-flow/) — the same flow in plain PHP
