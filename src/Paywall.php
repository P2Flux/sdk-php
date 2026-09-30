<?php

declare(strict_types=1);

namespace P2Flux;

/**
 * Charge AI agents for a page or an API route, in USDC, over x402 - without an x402 library.
 *
 *   $paywall = new Paywall($p2flux, ['recipient' => '0xYourWallet', 'price' => '0.05']);
 *   $result  = $paywall->guard($_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? null, $currentUrl, $_SERVER['HTTP_USER_AGENT'] ?? null);
 *   foreach ($result['headers'] as $name => $value) { header("$name: $value"); }
 *   if (!$result['allow']) { http_response_code($result['status']); echo json_encode($result['body']); exit; }
 *   // ...serve the content
 *
 * You say who is paid and how much; P2Flux builds what the agent signs and settles what it sends,
 * BEFORE you serve: one payment, one response. Money goes to your wallet; the fee is taken on chain.
 */
final class Paywall
{
    /** AI crawlers and assistants, and HTTP libraries agents are built on. Same list as the JS SDK and the WordPress plugin. */
    public const AGENT_SIGNATURES = [
        'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai', 'PerplexityBot',
        'Perplexity-User', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'meta-externalfetcher', 'cohere-ai',
        'cohere-training-data-crawler', 'Diffbot', 'YouBot', 'DuckAssistBot', 'MistralAI-User', 'AI2Bot', 'Timpibot', 'ImagesiftBot',
        'Omgilibot', 'Google-CloudVertexBot', 'Kangaroo Bot', 'PanguBot', 'Novellum',
        'P2Flux-MCP', 'x402', 'python-requests', 'python-httpx', 'aiohttp', 'axios/', 'node-fetch', 'undici', 'Go-http-client', 'okhttp', 'curl/', 'Wget/', 'Scrapy', 'libwww-perl',
    ];
    /** Never asked to pay under `agentsOnly`: search engines and link previews. */
    private const NEVER = ['Googlebot', 'bingbot', 'DuckDuckBot', 'Applebot', 'YandexBot', 'Baiduspider', 'Slackbot', 'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Discordbot', 'WhatsApp', 'TelegramBot'];

    private const MAX_HEADER = 8192;
    private const USED_TTL = 600;
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    private string $recipient;
    private string $price;
    private bool $agentsOnly;
    private bool $prepaid;
    private string $onUnavailable;
    /** @var null|callable(string): mixed */
    private $cacheGet;
    /** @var null|callable(string, mixed, int): void */
    private $cacheSet;

    /**
     * @param array{
     *   recipient: string, price: string, agentsOnly?: bool, prepaid?: bool, onUnavailable?: 'refuse'|'free',
     *   cacheGet?: callable(string): mixed, cacheSet?: callable(string, mixed, int): void
     * } $options
     *   agentsOnly     true: only AI agents and programs pay, browsers and search engines pass free.
     *                  false (default): every request pays - the right choice for an API.
     *   prepaid        offer the prepaid balance next to pay-per-request (default true).
     *   onUnavailable  when P2Flux cannot be reached: 'refuse' (503, default) or 'free'.
     *   cacheGet/Set   optional: your cache (APCu, Redis, Laravel's Cache). With it the payment
     *                  requirement is fetched once an hour instead of on every unpaid request, and a
     *                  payment already used here is refused without asking P2Flux.
     */
    public function __construct(private readonly P2FluxClient $client, array $options)
    {
        if (empty($options['recipient']) || empty($options['price'])) {
            throw new \InvalidArgumentException('recipient and price are required');
        }
        $this->recipient = (string) $options['recipient'];
        $this->price = (string) $options['price'];
        $this->agentsOnly = (bool) ($options['agentsOnly'] ?? false);
        $this->prepaid = (bool) ($options['prepaid'] ?? true);
        $this->onUnavailable = ($options['onUnavailable'] ?? 'refuse') === 'free' ? 'free' : 'refuse';
        $this->cacheGet = $options['cacheGet'] ?? null;
        $this->cacheSet = $options['cacheSet'] ?? null;
    }

    /** Whether a request is an AI agent or a program rather than a person's browser. */
    public static function isAgent(?string $userAgent, bool $hasPayment, bool $signed = false): bool
    {
        if ($hasPayment) {
            return true;
        }
        $ua = trim((string) $userAgent);
        if ($ua === '' || strtolower($ua) === 'node') {
            return true;
        }
        foreach (self::NEVER as $never) {
            if (stripos($ua, $never) !== false) {
                return false;
            }
        }
        // Browsers do not sign requests as bots (Web Bot Auth: a Signature-Agent header). Not verified here.
        if ($signed) {
            return true;
        }
        foreach (self::AGENT_SIGNATURES as $signature) {
            if (stripos($ua, $signature) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide one request.
     *
     * @param string|null $paymentHeader the PAYMENT-SIGNATURE (or legacy X-PAYMENT) header, null if none
     * @param string      $url           the full URL the client asked for
     * @param array{price?: string, mimeType?: string, signed?: bool} $overrides signed: the request carries a Signature-Agent header (Web Bot Auth)
     * @return array{allow: true, paid: bool, headers: array<string, string>, payer?: string, transaction?: string, receipt?: string, scheme?: string}
     *        |array{allow: false, status: int, headers: array<string, string>, body: array<string, mixed>}
     *
     * @throws P2FluxException when P2Flux rejects YOUR configuration (wallet or price) - a bug to fix, not a 402
     */
    public function guard(?string $paymentHeader, string $url, ?string $userAgent = null, array $overrides = []): array
    {
        $price = (string) ($overrides['price'] ?? $this->price);
        $mimeType = (string) ($overrides['mimeType'] ?? 'text/html');
        if ($this->agentsOnly && !self::isAgent($userAgent, $paymentHeader !== null, (bool) ($overrides['signed'] ?? false))) {
            return ['allow' => true, 'paid' => false, 'headers' => []];
        }
        if ($paymentHeader === null) {
            return $this->required($price, $url, $mimeType, null);
        }
        $paymentHeader = trim($paymentHeader);
        if (strlen($paymentHeader) > self::MAX_HEADER || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $paymentHeader) !== 1) {
            return $this->required($price, $url, $mimeType, 'invalid_payload');
        }
        $usedKey = 'p2flux_paywall_used_' . hash('sha256', $paymentHeader);
        if ($this->cacheGet !== null && ($this->cacheGet)($usedKey) !== null) {
            return $this->required($price, $url, $mimeType, 'invalid_transaction_state');
        }

        try {
            $answer = $this->client->paywallRedeem($this->recipient, $price, $paymentHeader, $url);
        } catch (P2FluxException $e) {
            if ($e->action === 'INVALID_REQUEST') {
                throw $e;
            }

            return $this->unavailable();
        }

        if (($answer['paid'] ?? false) === true) {
            $this->remember($usedKey);
            $result = ['allow' => true, 'paid' => true, 'headers' => self::NO_STORE];
            if (is_string($answer['payment_response'] ?? null)) {
                $result['headers']['PAYMENT-RESPONSE'] = $answer['payment_response'];
            }
            foreach (['payer', 'transaction', 'receipt', 'scheme'] as $field) {
                if (is_string($answer[$field] ?? null)) {
                    $result[$field] = $answer[$field];
                }
            }

            return $result;
        }

        // The agent took its unused prepaid balance back: the receipt, no content.
        if (($answer['refunded'] ?? false) === true && is_string($answer['payment_response'] ?? null)) {
            return [
                'allow' => false,
                'status' => 200,
                'headers' => ['PAYMENT-RESPONSE' => $answer['payment_response']] + self::NO_STORE,
                'body' => ['refunded' => true],
            ];
        }

        $reason = is_string($answer['reason'] ?? null) ? $answer['reason'] : 'payment_refused';
        if ($reason === 'invalid_transaction_state') {
            $this->remember($usedKey);
        }
        // A prepaid refusal carries P2Flux's own 402: the channel state the agent resynchronises to.
        if (is_string($answer['payment_required'] ?? null)) {
            $body = json_decode((string) base64_decode($answer['payment_required'], true), true);

            return [
                'allow' => false,
                'status' => 402,
                'headers' => ['PAYMENT-REQUIRED' => $answer['payment_required']] + self::NO_STORE,
                'body' => is_array($body) ? $body : ['error' => $reason],
            ];
        }

        return $this->required($price, $url, $mimeType, $reason);
    }

    /**
     * Usage pricing: the agent signs for at most `$maxPrice` (x402 `upto`); `$work` runs only after
     * P2Flux confirmed the payment will settle and returns `['amount' => '0.23', 'value' => ...]` -
     * what the request cost. If the settlement then fails, the value is NOT returned.
     *
     * @param callable(): array{amount: string, value: mixed} $work
     * @return array{allow: true, paid: bool, headers: array<string, string>, value: mixed, amount?: string, payer?: string, transaction?: string}
     *        |array{allow: false, status: int, headers: array<string, string>, body: array<string, mixed>}
     *
     * @throws P2FluxException when P2Flux rejects YOUR configuration or an amount above the maximum
     */
    public function usage(?string $paymentHeader, string $url, string $maxPrice, callable $work, ?string $userAgent = null, string $mimeType = 'application/json'): array
    {
        if ($this->agentsOnly && !self::isAgent($userAgent, $paymentHeader !== null)) {
            return ['allow' => true, 'paid' => false, 'headers' => [], 'value' => $work()['value']];
        }
        if ($paymentHeader === null) {
            return $this->required($maxPrice, $url, $mimeType, null, true);
        }
        $paymentHeader = trim($paymentHeader);
        if (strlen($paymentHeader) > self::MAX_HEADER || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $paymentHeader) !== 1) {
            return $this->required($maxPrice, $url, $mimeType, 'invalid_payload', true);
        }
        $usedKey = 'p2flux_paywall_used_' . hash('sha256', $paymentHeader);
        if ($this->cacheGet !== null && ($this->cacheGet)($usedKey) !== null) {
            return $this->required($maxPrice, $url, $mimeType, 'invalid_transaction_state', true);
        }
        try {
            $verdict = $this->client->paywallVerify($this->recipient, $maxPrice, $paymentHeader);
        } catch (P2FluxException $e) {
            if ($e->action === 'INVALID_REQUEST') {
                throw $e;
            }

            return $this->unavailable();
        }
        if (($verdict['valid'] ?? false) !== true) {
            return $this->required($maxPrice, $url, $mimeType, is_string($verdict['reason'] ?? null) ? $verdict['reason'] : 'payment_refused', true);
        }

        $done = $work();
        try {
            $answer = $this->client->paywallRedeem($this->recipient, $maxPrice, $paymentHeader, $url, (string) $done['amount']);
        } catch (P2FluxException $e) {
            if ($e->action === 'INVALID_REQUEST') {
                throw $e;
            }

            return $this->unavailable();
        }
        if (($answer['paid'] ?? false) !== true) {
            return $this->required($maxPrice, $url, $mimeType, is_string($answer['reason'] ?? null) ? $answer['reason'] : 'payment_refused', true);
        }
        $this->remember($usedKey);
        $out = ['allow' => true, 'paid' => true, 'headers' => self::NO_STORE, 'value' => $done['value']];
        if (is_string($answer['payment_response'] ?? null)) {
            $out['headers'] = ['PAYMENT-RESPONSE' => $answer['payment_response']] + self::NO_STORE;
        }
        foreach (['amount', 'payer', 'transaction'] as $key) {
            if (is_string($answer[$key] ?? null)) {
                $out[$key] = $answer[$key];
            }
        }

        return $out;
    }

    private function remember(string $key): void
    {
        if ($this->cacheSet !== null) {
            ($this->cacheSet)($key, 1, self::USED_TTL);
        }
    }

    /** @return array<string, mixed> */
    private function required(string $price, string $url, string $mimeType, ?string $error, bool $usage = false): array
    {
        $cacheKey = 'p2flux_paywall_ch_' . md5(strtolower($this->recipient) . '|' . $price . ($usage ? '|upto' : ''));
        $cached = $this->cacheGet !== null ? ($this->cacheGet)($cacheKey) : null;
        $accepts = is_array($cached) && is_array($cached['accepts'] ?? null) ? $cached['accepts'] : null;
        // Usage pricing: what lets an agent without ETH pay. Passed on as P2Flux wrote it.
        $extensions = is_array($cached) ? ($cached['extensions'] ?? null) : null;
        if (!is_array($accepts) || $accepts === []) {
            try {
                $challenge = $this->client->paywallChallenge($this->recipient, $price, $usage);
            } catch (P2FluxException $e) {
                if ($e->action === 'INVALID_REQUEST') {
                    throw $e;
                }

                return $this->unavailable();
            }
            $accepts = is_array($challenge['accepts'] ?? null) ? $challenge['accepts'] : [];
            if ($accepts === []) {
                return $this->unavailable();
            }
            $extensions = is_array($challenge['extensions'] ?? null) ? $challenge['extensions'] : null;
            if ($this->cacheSet !== null) {
                ($this->cacheSet)($cacheKey, ['accepts' => $accepts, 'extensions' => $extensions], min(3600, max(60, (int) ($challenge['ttl'] ?? 600))));
            }
        }
        if (!$this->prepaid) {
            $accepts = array_values(array_filter($accepts, static fn ($a) => is_array($a) && ($a['scheme'] ?? '') !== 'batch-settlement'));
        }
        $body = ['x402Version' => 2];
        if ($error !== null) {
            $body['error'] = $error;
        }
        $body['resource'] = ['url' => $url, 'mimeType' => $mimeType];
        $body['accepts'] = $accepts;
        if ($extensions !== null) {
            $body['extensions'] = $extensions;
        }

        return [
            'allow' => false,
            'status' => 402,
            'headers' => ['PAYMENT-REQUIRED' => base64_encode((string) json_encode($body, JSON_UNESCAPED_SLASHES))] + self::NO_STORE,
            'body' => $body,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(): array
    {
        if ($this->onUnavailable === 'free') {
            return ['allow' => true, 'paid' => false, 'headers' => []];
        }

        return [
            'allow' => false,
            'status' => 503,
            'headers' => ['Retry-After' => '60'] + self::NO_STORE,
            'body' => ['error' => 'payment_service_unavailable'],
        ];
    }
}
