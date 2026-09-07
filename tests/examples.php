<?php

declare(strict_types=1);

/**
 * Runs every public example against a canned API, so documentation cannot rot silently.
 *
 *   php tests/examples.php
 *
 * No network, no chain, no money: the examples talk to tests/stub-api.php on loopback. Requires a
 * Composer install, because the examples load vendor/autoload.php like any consumer would.
 */

$root = dirname(__DIR__);
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
    fwrite(STDERR, "run `composer install` first: the examples load vendor/autoload.php\n");
    exit(1);
}

/** @return array{0: int, 1: string} */
function run(string $script, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $script],
        $descriptors,
        $pipes,
        null,
        $env + ['PATH' => getenv('PATH') ?: '/usr/bin']
    );
    if (!is_resource($process)) {
        return [255, 'could not start php'];
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

// --- every example is syntactically valid ------------------------------------------------

$examples = array_merge(
    glob($root . '/examples/*.php') ?: [],
    glob($root . '/examples/*/*.php') ?: []
);
check('examples exist', $examples !== []);
foreach ($examples as $example) {
    $lint = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($example) . ' 2>&1', $lint, $lintStatus);
    check('lints: ' . basename($example), $lintStatus === 0, implode(' ', $lint));
    $source = (string) file_get_contents($example);
    /* Composer's autoloader, never the sources directly. The demo's own files reach it either
     * through their own require or through bootstrap.php, which is the one place it is loaded. */
    $loadsAutoload = str_contains($source, "vendor/autoload.php")
        || str_contains($source, "require __DIR__ . '/bootstrap.php';");
    check('loads composer autoload only: ' . basename($example), $loadsAutoload && !str_contains($source, "/../src/"));
    check('no hard-coded secrets: ' . basename($example), preg_match('/0x[0-9a-fA-F]{64}/', $source) === 0);
}

// --- the stub API answers on loopback ----------------------------------------------------

$port = 8000 + (getmypid() % 1000);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/tests/stub-api.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the stub API\n");
    exit(1);
}
register_shutdown_function(static function () use ($server): void {
    $status = proc_get_status($server);
    if ($status['running'] ?? false) {
        proc_terminate($server);
    }
    proc_close($server);
});

$apiUrl = 'http://127.0.0.1:' . $port;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @file_get_contents($apiUrl . '/v1/capabilities');
    if ($probe !== false) {
        break;
    }
    usleep(100000);
}
check('stub API is up', is_string(@file_get_contents($apiUrl . '/v1/capabilities')));

$base = [
    'P2FLUX_API_URL' => $apiUrl,
    'P2FLUX_CHECKOUT_URL' => 'https://pay-test.p2flux.com',
    'P2FLUX_RECIPIENT' => '0x' . str_repeat('e', 40),
    'P2FLUX_INTENT' => 'p2f1.k1.stub.mac',
    'P2FLUX_TX_HASH' => '0x' . str_repeat('1', 64),
    'P2FLUX_SUBSCRIPTION' => 'p2s2.k1.stub.mac',
    'P2FLUX_REFUND_UNITS' => '2500000',
    'P2FLUX_REFUND_TX_HASH' => '0x' . str_repeat('3', 64),
    'P2FLUX_SALT' => '12345',
    'P2FLUX_PERIOD_INDEX' => '3',
];

$expected = [
    'create-payment.php' => 'p2f1.k1.stub.mac',
    'create-sponsored-payment.php' => 'buyer can pay without ETH: yes',
    'verify-payment.php' => 'PAID',
    'recover-payment.php' => 'RECOVERED',
    'network-fee-in-usdc.php' => 'paid via payment_token',
    'subscription-signup.php' => 'terms ok',
    'charge-subscription.php' => 'CHARGED',
    'recover-charge.php' => 'FOUND',
    'refund.php' => 'REFUNDED',
];

check('every example is covered by this test', count($expected) === count(glob($root . '/examples/*.php') ?: []));

foreach ($expected as $name => $needle) {
    [$code, $output] = run($root . '/examples/' . $name, $base);
    check("runs: {$name}", $code === 0, trim($output));
    check("output: {$name} contains \"{$needle}\"", str_contains($output, $needle), trim($output));
}

// --- the branches that must NOT read as success -------------------------------------------

$confirming = ['P2FLUX_TX_HASH' => '0xc0' . str_repeat('1', 62)] + $base;
[$code, $output] = run($root . '/examples/verify-payment.php', $confirming);
check('verify-payment.php reports confirming', $code === 0 && str_contains($output, 'CONFIRMING'), trim($output));
check('and never says PAID for it', !str_contains($output, 'PAID'), trim($output));

$rejected = ['P2FLUX_TX_HASH' => '0xbad' . str_repeat('1', 61)] + $base;
[$code, $output] = run($root . '/examples/verify-payment.php', $rejected);
check('verify-payment.php reports a rejection', $code === 0 && str_contains($output, 'REJECTED'), trim($output));

// --- the missing-configuration branch ----------------------------------------------------

foreach ([
    'create-payment.php' => 'P2FLUX_RECIPIENT',
    'refund.php' => 'P2FLUX_INTENT',
    'charge-subscription.php' => 'P2FLUX_SUBSCRIPTION',
    'recover-charge.php' => 'P2FLUX_PERIOD_INDEX',
    'subscription-signup.php' => 'P2FLUX_SALT',
] as $name => $missing) {
    $env = $base;
    unset($env[$missing]);
    [$code, $output] = run($root . '/examples/' . $name, $env);
    check("fails clearly without {$missing}: {$name}", $code !== 0 && str_contains($output, $missing), trim($output));
}

echo $failures === 0 ? "\nexamples: all checks passed\n" : "\nexamples: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
