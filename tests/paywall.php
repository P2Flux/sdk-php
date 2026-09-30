<?php

declare(strict_types=1);

/**
 * Offline tests for the x402 paywall: the seller's wallet and price decide what is owed; a payment
 * is settled before anything is served and serves one response.
 *
 *   php tests/paywall.php
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';
require __DIR__ . '/../src/Paywall.php';

use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;
use P2Flux\Paywall;

$failures = 0;
function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "  ok    {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$label}  {$detail}\n";
}

const WALLET = '0xb4e43f3fBa5Add75395adAD366627E7d74141Fa9';
const URL = 'https://shop.example/report';
function b64(array $v): string
{
    return base64_encode((string) json_encode($v));
}
function pay(string $id): string
{
    return b64(['id' => $id]);
}
function decode(string $h): array
{
    return json_decode((string) base64_decode($h), true);
}

/** A P2Flux API that pays each payment id once. */
final class FakeApi
{
    public array $calls = [];
    public array $used = [];
    public bool $down = false;
    public bool $badConfig = false;

    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $this->calls[] = [parse_url($url, PHP_URL_PATH), $payload];
        if ($this->down) {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER');
        }
        if ($this->badConfig) {
            return [400, ['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']];
        }
        if (str_ends_with($url, '/challenge')) {
            $units = (string) (int) round(((float) $payload['price']) * 1e6);
            return [200, ['x402Version' => 2, 'ttl' => 3600, 'accepts' => [
                ['scheme' => 'exact', 'network' => 'eip155:84532', 'amount' => $units, 'payTo' => '0xvault'],
                ['scheme' => 'batch-settlement', 'network' => 'eip155:84532', 'amount' => $units, 'payTo' => '0xbatch'],
            ]]];
        }
        $id = decode($payload['payment'])['id'] ?? '';
        if (str_starts_with($id, 'bad-')) {
            return [200, ['paid' => false, 'reason' => 'invalid_exact_evm_insufficient_balance']];
        }
        if (str_starts_with($id, 'stale-')) {
            return [200, ['paid' => false, 'reason' => 'batch_stale', 'payment_required' => b64(['x402Version' => 2, 'error' => 'batch_stale', 'accepts' => [['extra' => ['channelState' => ['charged' => '150000']]]]])]];
        }
        if (isset($this->used[$id])) {
            return [200, ['paid' => false, 'reason' => 'invalid_transaction_state']];
        }
        $this->used[$id] = true;
        return [200, ['paid' => true, 'transaction' => '0x' . str_repeat('ab', 32), 'payer' => '0xagent', 'scheme' => 'exact', 'payment_response' => b64(['success' => true])]];
    }

    public function redeems(): int
    {
        return count(array_filter($this->calls, static fn ($c) => str_ends_with($c[0], '/redeem')));
    }
}
function paywall(FakeApi $api, array $over = []): Paywall
{
    return new Paywall(new P2FluxClient(['apiUrl' => 'https://api-test.p2flux.com/', 'transport' => $api]), $over + ['recipient' => WALLET, 'price' => '0.05']);
}
/** A cache the way a host supplies one. */
final class ArrayCache
{
    public array $data = [];
    public function get(string $k): mixed
    {
        return $this->data[$k] ?? null;
    }
    public function set(string $k, mixed $v, int $ttl): void
    {
        $this->data[$k] = $v;
    }
}

echo "no payment\n";
$api = new FakeApi();
$r = paywall($api)->guard(null, URL);
check('402, not allowed', $r['allow'] === false && $r['status'] === 402);
$required = decode($r['headers']['PAYMENT-REQUIRED']);
check('the requirement names THIS url', $required['resource']['url'] === URL && $required['x402Version'] === 2);
check('pay-per-request and prepaid are offered', array_column($required['accepts'], 'scheme') === ['exact', 'batch-settlement']);
check('no-store', str_contains($r['headers']['Cache-Control'], 'no-store'));
check('the seller wallet and price are what P2Flux is asked about', $api->calls === [['/x402/paywall/challenge', ['recipient' => WALLET, 'price' => '0.05']]]);
check('prepaid: false offers pay-per-request only', array_column(decode(paywall(new FakeApi(), ['prepaid' => false])->guard(null, URL)['headers']['PAYMENT-REQUIRED'])['accepts'], 'scheme') === ['exact']);
check('a price override is what is asked', decode(paywall(new FakeApi())->guard(null, URL, null, ['price' => '0.20'])['headers']['PAYMENT-REQUIRED'])['accepts'][0]['amount'] === '200000');

echo "payment\n";
$api = new FakeApi();
$r = paywall($api)->guard(pay('p1'), URL);
check('paid: allowed, with the receipt header', $r['allow'] === true && $r['paid'] === true && decode($r['headers']['PAYMENT-RESPONSE'])['success'] === true);
check('transaction and payer are returned', $r['transaction'] === '0x' . str_repeat('ab', 32) && $r['payer'] === '0xagent');
check('settled with the seller terms', end($api->calls) === ['/x402/paywall/redeem', ['recipient' => WALLET, 'price' => '0.05', 'payment' => pay('p1'), 'resource' => URL]]);
$again = paywall($api)->guard(pay('p1'), 'https://shop.example/other');
check('the same payment again is refused', $again['allow'] === false && decode($again['headers']['PAYMENT-REQUIRED'])['error'] === 'invalid_transaction_state');
$bad = paywall($api)->guard(pay('bad-1'), URL);
check('a refused payment: 402 with the reason', $bad['allow'] === false && decode($bad['headers']['PAYMENT-REQUIRED'])['error'] === 'invalid_exact_evm_insufficient_balance');
$stale = paywall($api)->guard(pay('stale-1'), URL);
check('a prepaid refusal forwards P2Flux own 402', $stale['allow'] === false && decode($stale['headers']['PAYMENT-REQUIRED'])['accepts'][0]['extra']['channelState']['charged'] === '150000' && $stale['body']['error'] === 'batch_stale');

echo "hostile headers\n";
$api = new FakeApi();
foreach (['not base64 !!', str_repeat('A', 9000), '{"id":1}', ''] as $h) {
    $r = paywall($api)->guard($h, URL);
    check('refused as invalid_payload: ' . substr($h, 0, 12), $r['allow'] === false && decode($r['headers']['PAYMENT-REQUIRED'])['error'] === 'invalid_payload');
}
check('none of them was sent to be settled', $api->redeems() === 0);

echo "with a cache\n";
$api = new FakeApi();
$cache = new ArrayCache();
$p = paywall($api, ['cacheGet' => [$cache, 'get'], 'cacheSet' => [$cache, 'set']]);
$p->guard(null, URL);
$p->guard(null, URL . '?2');
check('the requirement is fetched once', count($api->calls) === 1);
$p->guard(pay('c1'), URL);
$before = $api->redeems();
$r = $p->guard(pay('c1'), URL);
check('a used payment is refused without asking P2Flux', $r['allow'] === false && $api->redeems() === $before && decode($r['headers']['PAYMENT-REQUIRED'])['error'] === 'invalid_transaction_state');

echo "P2Flux unreachable / misconfigured\n";
$down = new FakeApi();
$down->down = true;
$r = paywall($down)->guard(pay('x'), URL);
check('503 with Retry-After by default', $r['allow'] === false && $r['status'] === 503 && $r['headers']['Retry-After'] === '60');
check('503 also when the requirement cannot be fetched', paywall($down)->guard(null, URL)['status'] === 503);
check("'free' serves without payment", paywall($down, ['onUnavailable' => 'free'])->guard(null, URL) === ['allow' => true, 'paid' => false, 'headers' => []]);
$badConfig = new FakeApi();
$badConfig->badConfig = true;
$threw = false;
try {
    paywall($badConfig)->guard(null, URL);
} catch (P2FluxException $e) {
    $threw = $e->action === 'INVALID_REQUEST';
}
check('a wallet or price P2Flux rejects throws - it is the seller configuration, not a 402', $threw);
$threw = false;
try {
    new Paywall(new P2FluxClient(['apiUrl' => 'https://x', 'transport' => new FakeApi()]), ['recipient' => WALLET]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('recipient and price are required', $threw);

echo "agentsOnly\n";
$api = new FakeApi();
$p = paywall($api, ['agentsOnly' => true]);
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
check('a browser passes free', $p->guard(null, URL, $chrome) === ['allow' => true, 'paid' => false, 'headers' => []]);
check('a search engine passes free', $p->guard(null, URL, 'Mozilla/5.0 (compatible; Googlebot/2.1)')['allow'] === true);
check('nothing was asked of P2Flux for them', $api->calls === []);
check('an AI crawler pays', $p->guard(null, URL, 'Mozilla/5.0 (compatible; GPTBot/1.2)')['allow'] === false);
check('no user agent pays', $p->guard(null, URL, null)['allow'] === false);
check('a browser that sends a payment is an agent', $p->guard(pay('bad-b'), URL, $chrome)['allow'] === false);
check('node is an agent; a crawler name inside a search engine UA is not', Paywall::isAgent('node', false) && !Paywall::isAgent('Googlebot GPTBot', false));

echo "\n" . ($failures === 0 ? 'all passed' : "{$failures} failed") . "\n";
exit($failures === 0 ? 0 : 1);
