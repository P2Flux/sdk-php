<?php

declare(strict_types=1);

/**
 * Keccak, checkMerchantRef and the accounting statement, against a recorded-log JSON-RPC stub: no
 * network, no chain. Same fixtures and expected rows/CSV as the JS SDK's test/statement.test.ts.
 *
 *   php tests/statement.php
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';
require __DIR__ . '/../src/Keccak.php';
require __DIR__ . '/../src/Statement.php';

use P2Flux\Keccak;
use P2Flux\P2FluxClient;
use P2Flux\Statement;

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

// --- keccak: standard vectors and the JS implementation's outputs --------------------------
check('keccak of the empty string', Keccak::hash256('') === '0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470');
check('keccak of abc', Keccak::hash256('abc') === '0x4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45');
check('keccak at 135 bytes (one padding byte)', Keccak::hash256(str_repeat('a', 135)) === '0x34367dc248bbd832f4e3e69dfaac2f92638bd0bbd18f2912ba4ef454919cf446');
check('keccak across the block boundary', Keccak::hash256(str_repeat('a', 200)) === '0x96ea54061def936c4be90b518992fdc6f12f535068a256229aca54267b4d084d');
foreach (['paid' => 'Paid(bytes32,address,address,uint256,uint256)', 'sponsored_paid' => 'SponsoredPaid(bytes32,address,address,uint256,uint256,uint256,uint256)',
    'subscription_charged' => 'SubscriptionCharged(bytes32,address,address,uint256,uint256,uint256,uint256,uint256)', 'transfer' => 'Transfer(address,address,uint256)'] as $k => $sig) {
    check("topic {$k} is keccak({$sig})", Statement::TOPICS[$k] === Keccak::hash256($sig));
}

// --- checkMerchantRef: the API's published vector ------------------------------------------
$salt = '0x' . str_repeat('11', 32);
$reference = '0x03f0752ac8d6e9e6b5bffa5a84881ce0d69c595da28be9ddd91fca4306478f52';
check('checkMerchantRef: the published vector', P2FluxClient::checkMerchantRef($reference, $salt, 'INV-1042'));
check('another ref is false', !P2FluxClient::checkMerchantRef($reference, $salt, 'INV-1043'));
check('another salt is false', !P2FluxClient::checkMerchantRef($reference, '0x' . str_repeat('12', 32), 'INV-1042'));
check('malformed input is false', !P2FluxClient::checkMerchantRef($reference, '0x11', 'INV-1042') && !P2FluxClient::checkMerchantRef('nope', $salt, 'INV-1042')
    && !P2FluxClient::checkMerchantRef($reference, $salt, 'a@b.com'));

// --- the statement --------------------------------------------------------------------------
$net = Statement::NETWORKS['base-sepolia'];
const GENESIS = 1790000000;
const LATEST = 46500000;
$tsOf = static fn (int $n): int => GENESIS + 2 * $n;
$addr = static fn (string $c): string => '0x' . str_repeat($c, 40);
$topic = static fn (string $a): string => '0x' . str_pad(substr($a, 2), 64, '0', STR_PAD_LEFT);
$data = static fn (int ...$n): string => '0x' . implode('', array_map(static fn (int $v): string => str_pad(dechex($v), 64, '0', STR_PAD_LEFT), $n));
$hash = static fn (string $c): string => '0x' . str_repeat($c, 64);

$WALLET = $addr('a');
[$P1, $P2, $P3, $STRANGER] = [$addr('1'), $addr('2'), $addr('3'), $addr('9')];
[$REF1, $REF2, $SUB] = [$hash('e'), $hash('f'), $hash('d')];
[$B1, $B2, $B3, $B4, $B5] = [46440000, 46441000, 46442000, 46443000, 46444000];
[$BS, $B6, $P4, $REF3] = [46300000, 46445000, $addr('4'), $hash('b')]; // "September" payment by P4, "October" refund
$log = static fn (string $address, array $topics, string $d, int $block, string $tx, int $index = 0): array =>
    ['address' => $address, 'topics' => $topics, 'data' => $d, 'blockNumber' => '0x' . dechex($block), 'transactionHash' => $tx, 'logIndex' => '0x' . dechex($index)];
$T = Statement::TOPICS;
$transfer = static fn (string $from, string $to, int $units, int $block, string $tx, int $index = 0): array =>
    $log($net['usdc'], [$T['transfer'], $topic($from), $topic($to)], $data($units), $block, $tx, $index);
$LOGS = [
    $log($net['splitter'], [$T['paid'], $REF1, $topic($WALLET), $topic($net['usdc'])], $data(9900000, 100000), $B1, $hash('1'), 3),
    $log($net['splitter'], [$T['paid'], $hash('0'), $topic($addr('b')), $topic($net['usdc'])], $data(1, 0), $B1, $hash('7')),
    $log($net['sponsored_splitter'], [$T['sponsored_paid'], $REF2, $topic($WALLET), $topic($P2)], $data(12275000, 125000, 4147, 100000), $B2, $hash('2')),
    $log($net['recurring'], [$T['subscription_charged'], $SUB, $topic($P3), $topic($WALLET)], $data(4, 4900000, 50000, 50000, 3000), $B3, $hash('3')),
    $transfer($WALLET, $P1, 1000000, $B4, $hash('4')),
    $transfer($WALLET, $STRANGER, 2000000, $B5, $hash('5')),
    $transfer($WALLET, $P2, 99000000, $B5, $hash('6'), 1),
    $log($net['splitter'], [$T['paid'], $REF3, $topic($WALLET), $topic($net['usdc'])], $data(19800000, 200000), $BS, $hash('9'), 2),
    $transfer($WALLET, $P4, 5000000, $B6, $hash('a')),
    $log($net['splitter'], [$T['paid'], $hash('c'), $topic($WALLET), $topic($net['usdc'])], $data(1, 0), 46000000, $hash('8')),
    // The refund case: wallet W2 (addr 7), payer P5 (addr 8) pays 10 and 1; W2 sends 10, 10, 10, 1.
    $log($net['sponsored_splitter'], [$T['sponsored_paid'], $hash('5'), $topic($addr('7')), $topic($addr('8'))], $data(9900000, 100000, 0, 0), 46440100, $hash('c')),
    $log($net['sponsored_splitter'], [$T['sponsored_paid'], $hash('6'), $topic($addr('7')), $topic($addr('8'))], $data(990000, 10000, 0, 0), 46440200, $hash('d')),
    $transfer($addr('7'), $addr('8'), 10000000, 46441100, $hash('e')),
    $transfer($addr('7'), $addr('8'), 10000000, 46441200, $hash('f')),
    $transfer($addr('7'), $addr('8'), 10000000, 46441300, $hash('0')),
    $transfer($addr('7'), $addr('8'), 1000000, 46441400, '0x' . str_repeat('ab', 32)),
];
$RECEIPTS = [$hash('9') => ['from' => $P4, 'logs' => [$transfer($P4, $WALLET, 19800000, $BS, $hash('9'), 1)]], $hash('1') => ['from' => $addr('5'), 'logs' => [$transfer($P1, $WALLET, 9900000, $B1, $hash('1'), 1), $transfer($P1, $addr('f'), 100000, $B1, $hash('1'), 2)]]];

$matches = static fn (mixed $want, ?string $got): bool => $want === null
    || (is_array($want) ? in_array(strtolower((string) $got), array_map('strtolower', $want), true) : strtolower($want) === strtolower((string) $got));
$seen = [];
$stub = static function (string $url, array $batch, int $timeout) use (&$seen, $LOGS, $RECEIPTS, $tsOf, $matches): array {
    $out = [];
    foreach ($batch as $r) {
        $seen[] = $r;
        [$method, $params] = [$r['method'], $r['params']];
        $answer = ['error' => ['code' => -32601, 'message' => "no {$method}"]];
        if ($method === 'eth_getBlockByNumber') {
            $n = $params[0] === 'latest' ? LATEST : hexdec($params[0]);
            $answer = ['result' => ['number' => '0x' . dechex($n), 'timestamp' => '0x' . dechex($tsOf($n))]];
        } elseif ($method === 'eth_getLogs') {
            $f = $params[0];
            [$from, $to] = [hexdec($f['fromBlock']), hexdec($f['toBlock'])];
            $answer = $to - $from > 5000
                ? ['error' => ['code' => -32005, 'message' => 'query exceeds max block range 5000']]
                : ['result' => array_values(array_filter($LOGS, static function (array $l) use ($f, $from, $to, $matches): bool {
                    $n = hexdec($l['blockNumber']);
                    foreach ($f['topics'] as $i => $t) {
                        if (!$matches($t, $l['topics'][$i] ?? null)) {
                            return false;
                        }
                    }
                    return strtolower($l['address']) === strtolower($f['address']) && $n >= $from && $n <= $to;
                }))];
        } elseif ($method === 'eth_getTransactionReceipt') {
            $answer = ['result' => $RECEIPTS[$params[0]] ?? null];
        }
        $out[] = ['jsonrpc' => '2.0', 'id' => $r['id']] + $answer;
    }

    return [200, array_reverse($out)]; // batches may come back in any order
};
$intent = static fn (array $payload): string => 'p2f1.k1.' . rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.sig';
$run = static fn (array $extra = []): array => Statement::statement($extra + [
    'wallet' => $WALLET,
    'from' => (new DateTimeImmutable())->setTimestamp($tsOf($B1 - 50000)),
    'to' => $tsOf($B5 + 10),
    'network' => 'base-sepolia',
    'rpc_urls' => ['https://rpc.example'],
    'transport' => $stub,
]);
$iso = static fn (int $block): string => gmdate('Y-m-d\TH:i:s.000\Z', $tsOf($block));

// A pasted intent is decoded without its signature: a merchant_ref that breaks the API's rule is not joined.
$forged = $run(['intents' => [$intent(['v' => 1, 'reference' => $REF1, 'mr' => '=HYPERLINK("http://x")']), $intent(['v' => 1, 'reference' => $REF2, 'mr' => 'a b'])]]);
check('merchant_ref from a pasted intent only when it follows MERCHANT_REF', array_column($forged, 'merchant_ref') === ['', '', '', ''], json_encode(array_column($forged, 'merchant_ref')));
$rows = $run(['intents' => [$intent(['v' => 1, 'reference' => $REF1, 'mr' => 'INV-1042']), 'not-an-intent']]);
$expected = [
    ['date' => $iso($B1), 'tx_hash' => $hash('1'), 'kind' => 'payment', 'payer' => $P1, 'reference' => $REF1, 'merchant_ref' => 'INV-1042', 'gross' => '10.000000', 'p2flux_fee' => '0.100000', 'network_fee' => '0.000000', 'net' => '9.900000'],
    ['date' => $iso($B2), 'tx_hash' => $hash('2'), 'kind' => 'payment', 'payer' => $P2, 'reference' => $REF2, 'merchant_ref' => '', 'gross' => '12.500000', 'p2flux_fee' => '0.125000', 'network_fee' => '0.100000', 'net' => '12.275000'],
    ['date' => $iso($B3), 'tx_hash' => $hash('3'), 'kind' => 'subscription', 'payer' => $P3, 'reference' => $SUB, 'merchant_ref' => '', 'gross' => '5.000000', 'p2flux_fee' => '0.050000', 'network_fee' => '0.050000', 'net' => '4.900000'],
    ['date' => $iso($B4), 'tx_hash' => $hash('4'), 'kind' => 'refund?', 'payer' => $P1, 'reference' => $REF1, 'merchant_ref' => 'INV-1042', 'gross' => '-1.000000', 'p2flux_fee' => '0.000000', 'network_fee' => '0.000000', 'net' => '-1.000000'],
];
check('native, sponsored, subscription and a refund to a known payer, in block order', $rows === $expected, json_encode($rows));

$seen = [];
$window = $run(['from' => $tsOf($B2), 'to' => $tsOf($B3)]);
check('the window is by date', array_column($window, 'tx_hash') === [$hash('2'), $hash('3')], json_encode(array_column($window, 'tx_hash')));
$logCalls = array_filter($seen, static fn (array $r): bool => $r['method'] === 'eth_getLogs');
check('no read below a deploy block, no empty range', array_filter($logCalls, static fn (array $r): bool =>
    hexdec($r['params'][0]['fromBlock']) < $net['splitter_deploy_block'] || hexdec($r['params'][0]['toBlock']) < hexdec($r['params'][0]['fromBlock'])) === []);
check('no read below the window when the wallet sent nothing', array_filter($logCalls, static fn (array $r): bool => hexdec($r['params'][0]['fromBlock']) < $B2 - 10) === []);
$october = ['from' => $tsOf($B6 - 1000), 'to' => $tsOf($B6 + 10)];
$refund = $run($october + ['intents' => [$intent(['v' => 1, 'reference' => $REF3, 'mr' => 'INV-0930'])]]);
check('September payment, October refund: the October statement shows the refund, not the payment', $refund === [
    ['date' => $iso($B6), 'tx_hash' => $hash('a'), 'kind' => 'refund?', 'payer' => $P4, 'reference' => $REF3, 'merchant_ref' => 'INV-0930', 'gross' => '-5.000000', 'p2flux_fee' => '0.000000', 'network_fee' => '0.000000', 'net' => '-5.000000'],
], json_encode($refund));
check('a 1-day look-back (or none) does not reach a payment ~3.4 days back', $run($october + ['lookback_days' => 1]) === [] && $run($october + ['lookback_days' => 0]) === []);
$refunds = $run(['wallet' => $addr('7')]);
check('refunds: P5 pays 10 and 1, the wallet sends 10, 10, 10, 1 - one -10 and one -1, the other 10s are not refunds',
    array_map(static fn (array $r): array => [$r['kind'], $r['reference'], $r['net']], $refunds) === [
        ['payment', $hash('5'), '9.900000'], ['payment', $hash('6'), '0.990000'], ['refund?', $hash('5'), '-10.000000'], ['refund?', $hash('6'), '-1.000000'],
    ], json_encode($refunds));
check('refunds: CSV byte-identical to the JS SDK', Statement::toCsv($refunds) === file_get_contents(__DIR__ . '/fixtures/statement-refunds.csv'));
check('a wallet with nothing in the window is an empty list', $run(['wallet' => $addr('c')]) === []);
foreach (['bad wallet' => ['wallet' => 'nope'], 'from after to' => ['from' => $tsOf($B2), 'to' => $tsOf($B1)], 'unknown network' => ['network' => 'mars'], 'negative lookback_days' => ['lookback_days' => -1]] as $label => $bad) {
    $threw = false;
    try {
        $run($bad);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    check("{$label} is an InvalidArgumentException", $threw);
}
$threw = false;
try {
    $run(['transport' => static fn (): array => [503, []]]);
} catch (RuntimeException) {
    $threw = true;
}
check('an RPC that keeps failing is an error, not a short statement', $threw);

// --- currencies: USD 1:1, EUR and GBP at the ECB rate of the row's day, from the API's /v1/rates ----
$asked = [];
$withRates = static function (callable $rpc) use (&$asked): callable {
    return static function (string $url, array $payload, int $timeout) use ($rpc, &$asked): array {
        if (!str_ends_with($url, '/v1/rates')) {
            return $rpc($url, $payload, $timeout);
        }
        $asked[] = $payload;
        $rates = [];
        for ($d = strtotime($payload['from'] . 'T00:00:00Z'); $d <= strtotime($payload['to'] . 'T00:00:00Z'); $d += 86400) {
            $rates[gmdate('Y-m-d', $d)] = ['USD' => 1.0799, 'GBP' => 0.83728, 'rate_date' => gmdate('Y-m-d', $d)];
        }

        return [200, ['source' => 'ECB', 'base' => 'EUR', 'fetched_at' => 1, 'rates' => $rates]];
    };
};
$FIXTURE = json_decode((string) file_get_contents(__DIR__ . '/fixtures/statement-currencies.json'), true);
$rows = $run(['currencies' => ['EUR', 'GBP'], 'api_url' => 'https://api.p2flux.example', 'transport' => $withRates($stub),
    'intents' => [$intent(['v' => 1, 'reference' => $REF1, 'mr' => 'INV-1042'])]]);
check('currencies: one rates call over the rows\' days', count($asked) === 1 && $asked[0] === ['from' => substr($rows[0]['date'], 0, 10), 'to' => substr(end($rows)['date'], 0, 10)], json_encode($asked));
check('currencies: value_usd 1:1, EUR and GBP of net, half away from zero', array_map(static fn (array $r): array => [$r['net'], $r['value_usd'], $r['value_eur'], $r['value_gbp']], $rows) === [
    ['9.900000', '9.90', '9.17', '7.68'], ['12.275000', '12.28', '11.37', '9.52'], ['4.900000', '4.90', '4.54', '3.80'], ['-1.000000', '-1.00', '-0.93', '-0.78'],
], json_encode($rows));
check('currencies: generic CSV is byte-identical to the JS SDK', Statement::toCsv($rows) === $FIXTURE['generic']);
check('currencies: xero CSV is byte-identical to the JS SDK', Statement::toCsv($rows, 'xero') === $FIXTURE['xero']);
check('currencies: quickbooks CSV is byte-identical to the JS SDK', Statement::toCsv($rows, 'quickbooks') === $FIXTURE['quickbooks']);
$gbp = $run(['currencies' => ['GBP'], 'transport' => $withRates($stub)]);
check('GBP only: value_usd, value_gbp, rate_date', array_slice(array_keys($gbp[0]), -4) === ['net', 'value_usd', 'value_gbp', 'rate_date']);
$asked = [];
$plain = $run(['transport' => $withRates($stub)]);
check('no currencies: no rates call, no new keys', $asked === [] && !isset($plain[0]['value_usd']));
foreach ([
    'a day without a rate' => static fn (string $u, array $p, int $t): array => str_ends_with($u, '/v1/rates') ? [200, ['rates' => []]] : $stub($u, $p, $t),
    'rates unavailable' => static fn (string $u, array $p, int $t): array => str_ends_with($u, '/v1/rates') ? [503, ['error' => 'RATES_UNAVAILABLE']] : $stub($u, $p, $t),
] as $label => $transport) {
    $threw = false;
    try {
        $run(['currencies' => ['EUR'], 'transport' => $transport]);
    } catch (RuntimeException) {
        $threw = true;
    }
    check("{$label} is an error", $threw);
}
$threw = false;
try {
    $run(['currencies' => ['JPY']]);
} catch (InvalidArgumentException) {
    $threw = true;
}
check('an unknown currency is refused', $threw);

// --- CSV: same bytes as the JS SDK ------------------------------------------------------------
$ROWS = [
    ['date' => '2026-10-01T12:00:00.000Z', 'tx_hash' => $hash('1'), 'kind' => 'payment', 'payer' => $P1, 'reference' => $REF1, 'merchant_ref' => '-INV,"1"', 'gross' => '10.000000', 'p2flux_fee' => '0.100000', 'network_fee' => '0.000000', 'net' => '9.900000'],
    ['date' => '2026-10-02T08:30:00.000Z', 'tx_hash' => $hash('4'), 'kind' => 'refund?', 'payer' => $P1, 'reference' => $REF1, 'merchant_ref' => '', 'gross' => '-1.000000', 'p2flux_fee' => '0.000000', 'network_fee' => '0.000000', 'net' => '-1.000000'],
];
$h1 = $hash('1');
$h4 = $hash('4');
check('generic CSV', Statement::toCsv($ROWS) === implode("\r\n", [
    'date,tx_hash,kind,payer,reference,merchant_ref,gross,p2flux_fee,network_fee,net',
    "2026-10-01T12:00:00.000Z,{$h1},payment,{$P1},{$REF1},\"'-INV,\"\"1\"\"\",10.000000,0.100000,0.000000,9.900000",
    "2026-10-02T08:30:00.000Z,{$h4},refund?,{$P1},{$REF1},,-1.000000,0.000000,0.000000,-1.000000",
]) . "\r\n");
check('xero CSV', Statement::toCsv($ROWS, 'xero') === implode("\r\n", [
    'Date,Amount,Payee,Description,Reference',
    "01/10/2026,9.900000,{$P1},\"P2Flux payment {$h1}: gross 10.000000, P2Flux fee 0.100000, network fee 0.000000\",\"'-INV,\"\"1\"\"\"",
    "02/10/2026,-1.000000,{$P1},\"P2Flux refund? {$h4}: gross -1.000000, P2Flux fee 0.000000, network fee 0.000000\",{$REF1}",
]) . "\r\n");
check('quickbooks CSV', Statement::toCsv($ROWS, 'quickbooks') === implode("\r\n", [
    'Date,Description,Amount',
    "10/01/2026,\"P2Flux payment -INV,\"\"1\"\" {$h1}: gross 10.000000, P2Flux fee 0.100000, network fee 0.000000\",9.900000",
    "10/02/2026,\"P2Flux refund? {$REF1} {$h4}: gross -1.000000, P2Flux fee 0.000000, network fee 0.000000\",-1.000000",
]) . "\r\n");
$threw = false;
try {
    Statement::toCsv($ROWS, 'excel');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('an unknown format is refused', $threw);

echo $failures === 0 ? "\nstatement OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
