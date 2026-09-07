# Symfony

There is no P2Flux Symfony bundle, and none is needed. The SDK is a plain PHP class: register it as
a service once, and autowiring injects it everywhere.

Tested shape: Symfony 6.4/7.x on PHP 8.2+.

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
```

There is no API key: P2Flux v1 has no API authentication, and a payment is bound to its recipient
and amount by the buyer's signature. `P2FLUX_RECIPIENT` is your public payout wallet.

`config/services.yaml`:

```yaml
parameters:
    p2flux.checkout_url: '%env(P2FLUX_CHECKOUT_URL)%'
    p2flux.recipient: '%env(P2FLUX_RECIPIENT)%'

services:
    _defaults:
        autowire: true
        autoconfigure: true

    P2Flux\P2FluxClient:
        arguments:
            $options:
                apiUrl: '%env(P2FLUX_API_URL)%'
                timeout: 30
```

The constructor takes a single `array $options`, so the service definition names it as `$options`.
Autowiring then injects `P2FluxClient` into any controller or service that type-hints it.

## Create a payment

```php
namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PaymentController extends AbstractController
{
    public function __construct(
        private readonly P2FluxClient $p2flux,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/payments', methods: ['POST'])]
    public function create(): JsonResponse
    {
        $order = new Order('12.50');
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        try {
            // Recipient and amount come from configuration and from your own records, never from
            // the request body.
            $payment = $this->p2flux->createPayment([
                'recipient' => $this->getParameter('p2flux.recipient'),
                'amount' => $order->getAmount(),
            ]);
        } catch (P2FluxException $e) {
            return $this->json(['error' => $e->status], 502);
        }

        $order->setIntent($payment['intent']);
        $this->entityManager->flush();

        return $this->json([
            'order' => $order->getId(),
            'checkout' => $this->getParameter('p2flux.checkout_url')
                . '/#/pay/' . rawurlencode($payment['intent']),
        ]);
    }
}
```

## Verify before fulfilling

```php
#[Route('/payments/verify', methods: ['POST'])]
public function verify(Request $request, OrderRepository $orders): JsonResponse
{
    $input = $request->toArray();
    $order = $orders->find($input['order'] ?? 0);

    if ($order === null) {
        return $this->json(['status' => 'unknown_order'], 404);
    }
    if ($order->isPaid()) {
        return $this->json(['status' => 'paid', 'tx_hash' => $order->getTxHash()]);
    }

    try {
        $verdict = $this->p2flux->verifyPayment(
            $order->getIntent(),
            (string) ($input['tx_hash'] ?? ''),
            $input['settlement_receipt'] ?? null,
        );
    } catch (P2FluxException $e) {
        // Never reached a verdict: unknown, not rejected. The client retries.
        return $this->json(['status' => 'unavailable', 'code' => $e->status], 503);
    }

    if (($verdict['valid'] ?? false) !== true) {
        return ($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING'
            ? $this->json(['status' => 'confirming'], 202)
            : $this->json(['status' => 'unsettled', 'code' => $verdict['code'] ?? null]);
    }

    // Fulfil exactly once. A pessimistic write lock re-reads the row inside the transaction, so two
    // concurrent verifications cannot both mark it paid.
    $this->entityManager->wrapInTransaction(function () use ($order, $verdict): void {
        $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
        if ($order->isPaid()) {
            return;
        }
        $order->markPaid($verdict['tx_hash'], $verdict['settlement_receipt'] ?? null);
    });

    return $this->json(['status' => 'paid', 'tx_hash' => $verdict['tx_hash']]);
}
```

A unique constraint on the intent column gives the same guarantee if you would rather let the
database refuse the second write.

## Recurring charges from a command

```php
#[AsCommand(name: 'p2flux:charge-due')]
class ChargeDueCommand extends Command
{
    public function __construct(
        private readonly P2FluxClient $p2flux,
        private readonly SubscriptionRepository $subscriptions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->subscriptions->due() as $subscription) {
            $result = $this->p2flux->charge($subscription->capability());

            if ($result->ok) {
                $subscription->markPeriodPaid($result->periodIndex, $result->txHash);
            } elseif ($result->action === 'STOP_SUBSCRIPTION') {
                $subscription->stop($result->status);
            }
            // CONFIRMING and RETRY_LATER: change nothing and ask again later.
        }

        return Command::SUCCESS;
    }
}
```

`charge()` never throws on a payment outcome — classify on `->action`, not on `->status`. Store the
`p2s2` capability encrypted; it is a bearer credential.

## Exception handling

```php
#[AsEventListener(event: KernelEvents::EXCEPTION)]
public function onKernelException(ExceptionEvent $event): void
{
    $e = $event->getThrowable();
    if (!$e instanceof P2FluxException) {
        return;
    }

    $status = match ($e->action) {
        'INVALID_REQUEST' => 422,
        'RETRY_LATER' => 503,
        default => 502,
    };
    $event->setResponse(new JsonResponse(['error' => $e->status], $status));
}
```

## Next

- [Testing](../testing.md) — replace the service with a fake transport in `config/services_test.yaml`
- [The payment lifecycle](../payment-flow.md) · [Production checklist](../production-checklist.md)
- [`examples/complete-payment-flow/`](../../examples/complete-payment-flow/) — the same flow in plain PHP
