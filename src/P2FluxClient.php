<?php

declare(strict_types=1);

namespace P2Flux;

/**
 * P2Flux PHP SDK - a thin client over the HTTP API, no dependencies beyond curl.
 *
 * P2Flux executes payments. Your application owns the subscription lifecycle: when a renewal is
 * due, who the customer is, what happens after a failure. There is deliberately no scheduler and
 * no state here - call charge() from your existing renewal job (WP-Cron, Laravel scheduler, a
 * worker, whatever you already run).
 *
 *   $p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example']);
 *   $result = $p2flux->charge($subscriptionRef);
 *   if ($result->ok) { // CHARGED or ALREADY_CHARGED
 *       $subscription->markRenewalPaid();
 *   }
 *
 * Pass a `transport` callable to route requests through the host application's HTTP client instead
 * of curl (see __construct) - hosts with their own stack, and tests, both need that.
 */
final class P2FluxClient
{
    /** Status => what the merchant system should do about it. */
    public const ACTIONS = [
        'CHARGED' => 'SUCCESS',
        'ALREADY_CHARGED' => 'SUCCESS',
        // The money moved and the chain has not settled. Not a failure and not a success: leave the
        // period open, change nothing, ask again shortly - and never send a second charge.
        'CONFIRMING' => 'WAIT',
        'PAYMENT_CONFIRMING' => 'WAIT',
        'NOT_DUE' => 'RETRY_LATER',
        'INSUFFICIENT_BALANCE' => 'CUSTOMER_ACTION_REQUIRED',
        'INSUFFICIENT_ALLOWANCE' => 'CUSTOMER_ACTION_REQUIRED',
        'PERMISSION_REVOKED' => 'STOP_SUBSCRIPTION',
        'SUBSCRIPTION_EXPIRED' => 'STOP_SUBSCRIPTION',
        'INVALID_SUBSCRIPTION' => 'INVALID_REQUEST',
        'INVALID_REQUEST' => 'INVALID_REQUEST',
        // Permanent: the amount or period is outside what the terms can express. Fix the request,
        // do not retry it - the fallback used to call these retryable, which reads as an outage.
        'AMOUNT_OUT_OF_BOUNDS' => 'INVALID_REQUEST',
        'PERIOD_OUT_OF_BOUNDS' => 'INVALID_REQUEST',
        'RPC_ERROR' => 'RETRY_LATER',
        'RELAYER_ERROR' => 'RETRY_LATER',
        'TRANSACTION_REVERTED' => 'RETRY_LATER',
        'INTERNAL_ERROR' => 'RETRY_LATER',
        'NETWORK_ERROR' => 'RETRY_LATER',
        // Infrastructure protection, not a payment outcome: the request was turned away before any
        // money could move, so the subscription is untouched and the call is safe to repeat.
        'RATE_LIMITED' => 'RETRY_LATER',
        'CONCURRENCY_LIMIT' => 'RETRY_LATER',
        // Gas could not be priced, or moved above what this subscription authorized. Nothing was
        // spent and the subscription is unchanged; the charge waits for better conditions.
        'GAS_TOO_HIGH' => 'RETRY_LATER',
        'GAS_QUOTE_UNAVAILABLE' => 'RETRY_LATER',
        'GAS_FEE_TOO_HIGH' => 'RETRY_LATER',
        /* Operator-side limits, not payment outcomes: refused before anything reached the chain, so
         * nothing was spent and the subscription is untouched. Nothing a customer can act on. */
        'RELAYER_TX_COST_TOO_HIGH' => 'RETRY_LATER',
        'RELAYER_BUDGET_EXCEEDED' => 'RETRY_LATER',
        'RELAYER_NOT_READY' => 'RETRY_LATER',
        // The service is at its own capacity: come back shortly, not "you asked too often".
        'RPC_BUSY' => 'RETRY_LATER',
    ];

    private string $apiUrl;
    private int $timeout;
    /** @var null|callable(string, array<string, mixed>, int): array{0: int, 1: array<string, mixed>} */
    private $transport;

    /**
     * @param array{apiUrl: string, timeout?: int, transport?: callable} $options
     *        timeout defaults to 60 s: a charge waits for on-chain confirmation, which on a busy
     *        public RPC can take tens of seconds. Abandoning it early is safe but noisy - the
     *        payment may still land, and the next call returns ALREADY_CHARGED.
     *
     *        transport replaces curl with your own HTTP client - a host framework's (WordPress's
     *        wp_remote_post, Guzzle, Symfony HttpClient) or a stub in tests. It receives the
     *        absolute URL, the payload array and the timeout, and must return
     *        [int $httpStatus, array $decodedBody]. Throw P2FluxException('NETWORK_ERROR', ...)
     *        when the request never reached the API; charge() turns that into a retryable result.
     */
    public function __construct(array $options)
    {
        if (empty($options['apiUrl'])) {
            throw new \InvalidArgumentException('apiUrl is required');
        }
        if (isset($options['transport']) && !is_callable($options['transport'])) {
            throw new \InvalidArgumentException('transport must be callable');
        }
        $this->apiUrl = rtrim($options['apiUrl'], '/');
        $this->timeout = $options['timeout'] ?? 60;
        $this->transport = $options['transport'] ?? null;
    }

    /**
     * Technical terms for a recurring authorization. No customer, order or product fields exist -
     * the API rejects unknown properties. The returned setup_token goes in the checkout URL
     * fragment; `salt` identifies this exact setup, so a capability finalized from a different one
     * can be told apart later (compare it against the salt in status()).
     *
     * @param array{recipient: string, amount: string, period: int, end?: int} $terms
     * @return array<string, mixed>
     */
    public function createSubscription(array $terms): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions', $terms);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Technical terms for a one-time payment. The reference is server-generated: keep your own
     * order -> reference mapping.
     *
     * @param array{recipient: string, amount: string} $terms
     * @return array<string, mixed>
     */
    public function createPayment(array $terms): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments', $terms);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * The trust boundary for one-time payments: re-reads the receipt on chain and checks it against
     * the signed intent. Never grant access on a browser's word alone - call this server-side.
     *
     * A rejected payment is `['valid' => false, 'code' => ...]` with HTTP 200, not an exception.
     *
     * @return array<string, mixed>
     */
    public function verifyPayment(string $intent, string $txHash): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments/verify', ['intent' => $intent, 'tx_hash' => $txHash]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Exchange a p2s2 for a short-lived cancellation token that can safely reach the customer's
     * browser: it carries the authorization fields needed to build revoke(), and neither the
     * customer's signature nor any ability to charge. Open <checkout>/#/cancel/<cancel_token>.
     *
     * The contract still requires the payer's own wallet to send the transaction, so possession of
     * the token alone cannot revoke anything.
     *
     * @return array<string, mixed>
     */
    public function createCancellationSession(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/revoke/session', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Attempt one recurring charge.
     *
     * Never throws for a payment outcome - inspect $result->status and $result->action. Only an
     * unreachable API produces NETWORK_ERROR, which is itself retryable.
     *
     * Safe to retry: the contract allows one charge per billing period, so a repeat call after a
     * timeout or a crashed worker returns ALREADY_CHARGED instead of charging again.
     */
    public function charge(string $subscriptionRef): ChargeResult
    {
        try {
            [, $body] = $this->post('/v1/charges', ['subscription' => $subscriptionRef]);
        } catch (P2FluxException $e) {
            return ChargeResult::fromArray(['status' => 'NETWORK_ERROR']);
        }

        return ChargeResult::fromArray($body);
    }

    /**
     * Current state, read straight from the chain. Use it to reconcile after downtime.
     *
     * @return array<string, mixed>
     */
    public function status(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/status', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Calldata that cancels this one subscription. Only the customer's wallet can send it -
     * P2Flux cannot revoke wallet authority and does not pretend to.
     *
     * @return array<string, mixed>
     */
    public function prepareSubscriptionCancellation(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/revoke/prepare', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Calldata that removes the token allowance entirely - stops every P2Flux subscription paid
     * in that token from this wallet.
     *
     * @return array<string, mixed>
     */
    public function prepareAllowanceRevocation(): array
    {
        [$httpStatus, $body] = $this->post('/v1/allowances/revoke/prepare', []);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $path, array $payload): array
    {
        if ($this->transport !== null) {
            [$httpStatus, $body] = ($this->transport)($this->apiUrl . $path, $payload, $this->timeout);

            return [(int) $httpStatus, is_array($body) ? $body : []];
        }

        return $this->curlPost($this->apiUrl . $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function curlPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload === [] ? new \stdClass() : $payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpStatus === 0) {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => $error]);
        }

        $body = json_decode((string) $raw, true);

        return [$httpStatus, is_array($body) ? $body : []];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function throwIfError(int $httpStatus, array $body): void
    {
        if ($httpStatus < 400) {
            return;
        }
        $status = (string) ($body['error'] ?? 'INTERNAL_ERROR');
        throw new P2FluxException($status, (string) ($body['action'] ?? self::ACTIONS[$status] ?? 'RETRY_LATER'), $body);
    }
}
