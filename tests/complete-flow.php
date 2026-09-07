<?php

declare(strict_types=1);

/**
 * Runs examples/complete-payment-flow end to end against the canned API.
 *
 *   php tests/complete-flow.php
 *
 * Two PHP servers on loopback - the canned API and the demo itself - and no chain, no wallet and no
 * money anywhere. What it proves is the part a merchant integration gets wrong: that only a valid
 * server-side verdict marks an order paid, and that verifying twice fulfils once.
 */

$root = dirname(__DIR__);
$demo = $root . '/examples/complete-payment-flow';
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

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "run `composer install` first\n");
    exit(1);
}

$store = sys_get_temp_dir() . '/p2flux-demo-' . getmypid();
$servers = [];

function serve(string $docRouter, int $port, array $env, string $docroot): mixed
{
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot];
    if ($docRouter !== '') {
        $command[] = $docRouter;
    }

    return proc_open(
        $command,
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $docroot,
        $env + ['PATH' => getenv('PATH') ?: '/usr/bin']
    );
}

$apiPort = 8300 + (getmypid() % 200);
$demoPort = $apiPort + 1;

$servers[] = serve($root . '/tests/stub-api.php', $apiPort, [], $root);
$servers[] = serve('', $demoPort, [
    'P2FLUX_API_URL' => 'http://127.0.0.1:' . $apiPort,
    'P2FLUX_CHECKOUT_URL' => 'https://pay-test.p2flux.com',
    'P2FLUX_RECIPIENT' => '0x' . str_repeat('e', 40),
    'DEMO_STORE_DIR' => $store,
], $demo);

register_shutdown_function(static function () use ($servers, $store): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            $status = proc_get_status($server);
            if ($status['running'] ?? false) {
                proc_terminate($server);
            }
            proc_close($server);
        }
    }
    foreach (glob($store . '/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($store);
});

foreach ($servers as $server) {
    if (!is_resource($server)) {
        fwrite(STDERR, "could not start a test server\n");
        exit(1);
    }
}

$demoUrl = 'http://127.0.0.1:' . $demoPort;
for ($attempt = 0; $attempt < 60; $attempt++) {
    if (@file_get_contents($demoUrl . '/index.php') !== false) {
        break;
    }
    usleep(100000);
}
check('the demo shop is up', is_string(@file_get_contents($demoUrl . '/index.php')));

/** @return array{0: int, 1: string} */
function request(string $url, string $method = 'GET', ?array $json = null): array
{
    $options = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20]];
    if ($json !== null) {
        $options['http']['header'] = "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($json);
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return [$status, is_string($body) ? $body : ''];
}

function newOrder(string $demoUrl): ?string
{
    [$status, $html] = request($demoUrl . '/create.php', 'POST');
    if ($status !== 200 || preg_match('/const ORDER\s*=\s*"([a-f0-9]+)"/', $html, $m) !== 1) {
        return null;
    }

    return $m[1];
}

$paid = '0x' . str_repeat('1', 64);
$confirming = '0xc0' . str_repeat('1', 62);
$rejected = '0xbad' . str_repeat('1', 61);

// --- creating an order mints an intent and stores it -------------------------------------

[$status, $html] = request($demoUrl . '/create.php', 'POST');
check('create.php answers 200', $status === 200, (string) $status);
check('create.php mints an intent', str_contains($html, 'p2f1.k1.stub.mac'), substr($html, 0, 120));
check('create.php opens the hosted checkout', str_contains($html, '#/pay/'));
check('create.php never marks anything paid', !str_contains($html, '"status":"paid"'));
preg_match('/const ORDER\s*=\s*"([a-f0-9]+)"/', $html, $m);
$order = $m[1] ?? '';
check('the page carries an order id', $order !== '');

[$status, $body] = request($demoUrl . '/status.php?order=' . $order);
check('a new order is pending', $status === 200 && str_contains($body, '"status":"pending"'), $body);

// --- a valid verdict marks it paid, once -------------------------------------------------

[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => $order, 'tx_hash' => $paid]);
$first = json_decode($body, true);
check('verify.php pays the order', $status === 200 && ($first['status'] ?? '') === 'paid', $body);
check('and records the transaction', ($first['tx_hash'] ?? '') === $paid, $body);

[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => $order, 'tx_hash' => $paid]);
$second = json_decode($body, true);
check('a repeat verification is safe', $status === 200 && ($second['status'] ?? '') === 'paid', $body);
check('and reports itself as a repeat', ($second['repeat'] ?? false) === true, $body);
check('and does not change the transaction', ($second['tx_hash'] ?? '') === $paid, $body);

[, $body] = request($demoUrl . '/status.php?order=' . $order);
check('the server says paid', str_contains($body, '"status":"paid"'), $body);

// --- a confirming settlement is NOT paid -------------------------------------------------

$order = newOrder($demoUrl);
check('a second order was created', $order !== null);
[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => $order, 'tx_hash' => $confirming]);
check('confirming answers 202', $status === 202 && str_contains($body, 'confirming'), $body);
[, $body] = request($demoUrl . '/status.php?order=' . $order);
check('and the order stays unpaid', !str_contains($body, '"status":"paid"'), $body);

// --- a transaction that settles nothing is NOT paid --------------------------------------

$order = newOrder($demoUrl);
[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => $order, 'tx_hash' => $rejected]);
check('an unsettled claim answers 200 unsettled', $status === 200 && str_contains($body, 'unsettled'), $body);
check('and names the code', str_contains($body, 'TRANSACTION_NOT_FOUND'), $body);
[, $body] = request($demoUrl . '/status.php?order=' . $order);
check('and the order stays unpaid', !str_contains($body, '"status":"paid"'), $body);

// --- a claim with no hash falls back to recovery -----------------------------------------

$order = newOrder($demoUrl);
[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => $order]);
check('a hashless claim recovers the settlement', $status === 200 && str_contains($body, '"status":"paid"'), $body);

// --- an unknown order is refused ---------------------------------------------------------

[$status, $body] = request($demoUrl . '/verify.php', 'POST', ['order' => 'nope', 'tx_hash' => $paid]);
check('an unknown order is 404', $status === 404, (string) $status);
[$status] = request($demoUrl . '/verify.php', 'POST', ['order' => '../../etc/passwd', 'tx_hash' => $paid]);
check('a traversal attempt is 404', $status === 404, (string) $status);

echo $failures === 0 ? "\ncomplete flow: all checks passed\n" : "\ncomplete flow: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
