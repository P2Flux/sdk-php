<?php

declare(strict_types=1);

namespace P2Flux;

/**
 * An accounting statement for one receiving wallet, read straight from the chain - the same rows and
 * CSV formats as the JS SDK's `@p2flux/sdk/statement`.
 *
 *   $rows = Statement::statement(['wallet' => '0xYourWallet', 'from' => strtotime('2026-10-01'), 'to' => strtotime('2026-11-01')]);
 *   file_put_contents('october.csv', Statement::toCsv($rows, 'xero'));
 *
 * No API call, no key, no dependency: raw JSON-RPC to public endpoints (or your own), so the numbers do
 * not depend on P2Flux being up - or honest. One row per settlement to the wallet (one-time payments
 * through the splitter and the sponsored splitter, subscription charges) and per probable refund: a
 * USDC transfer from the wallet back to a payer in the statement, after their payment and no larger
 * than it (`kind` 'refund?' - a transfer cannot say why it was sent). Amounts are decimal USDC; a
 * refund is negative. `merchant_ref` is filled from the intents you pass, decoded locally.
 *
 * Row keys: date (ISO 8601 UTC), tx_hash, kind (payment|subscription|refund?), payer, reference,
 * merchant_ref, gross, p2flux_fee, network_fee (merchant-funded), net (what reached the wallet).
 */
final class Statement
{
    /**
     * Addresses and deploy blocks (the floor of every log search), as pinned in the P2Flux checkout and
     * the API's registry. `retired_*` list settlement contracts replaced by a rotation (none yet).
     */
    public const NETWORKS = [
        'base' => [
            'chain_id' => 8453,
            'rpc_urls' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'],
            'usdc' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'splitter' => '0x5A3bD0945cd0C80B124870881dE49a717D20E0D0',
            'splitter_deploy_block' => 50362015,
            'sponsored_splitter' => '0x95E18ec05D4282acB3aab7aD60325bA4EEeEa8df',
            'sponsored_splitter_deploy_block' => 50966621,
            'recurring' => '0xb415A9910Ef627e3bEF10F5Cb9DC92a3271e0975',
            'recurring_deploy_block' => 50362127,
            'retired_splitters' => [],
            'retired_sponsored_splitters' => [],
        ],
        'base-sepolia' => [
            'chain_id' => 84532,
            'rpc_urls' => ['https://sepolia.base.org', 'https://base-sepolia-rpc.publicnode.com'],
            'usdc' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'splitter' => '0x3120aa022437db5c6d0439ac7f7852ce8b38e70f',
            'splitter_deploy_block' => 45512197,
            'sponsored_splitter' => '0x876f7b98e8c06291ec916a3223a92038b0a8774f',
            'sponsored_splitter_deploy_block' => 46433064,
            'recurring' => '0x394c3fe285168f333ebf29e8f3585039328f2a73',
            'recurring_deploy_block' => 45635698,
            'retired_splitters' => [],
            'retired_sponsored_splitters' => [],
        ],
    ];

    /** keccak256 of each event signature (checked against the signatures in tests/statement.php). */
    public const TOPICS = [
        'paid' => '0xa35447e6a2d0a89ecfb9d70336046be4a4ab0c579634503abd6ae7b4891dfc3c',
        'sponsored_paid' => '0x416cb67c4e5d87dd0e97ed23f9840596617678f78a7476b9c32f6d5521124ff6',
        'subscription_charged' => '0x5b2b67b5b5878335a49150eeee6843fb39976a9d48ab7015c6dc3d0136795ff3',
        'transfer' => '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
    ];

    private const LOG_SPAN = 10000; // mainnet.base.org serves 10,000 blocks per eth_getLogs
    private const BATCH = 20;
    private const COLUMNS = ['date', 'tx_hash', 'kind', 'payer', 'reference', 'merchant_ref', 'gross', 'p2flux_fee', 'network_fee', 'net'];
    private const AMOUNTS = ['gross', 'p2flux_fee', 'network_fee', 'net'];

    /** @var callable(string, array<int|string, mixed>, int): array{0: int, 1: array<int|string, mixed>} */
    private $transport;

    /** @param list<string> $urls */
    private function __construct(private readonly array $urls, ?callable $transport, private readonly int $timeout)
    {
        $this->transport = $transport ?? new CurlTransport($timeout);
    }

    /**
     * The statement rows for a wallet between two times, oldest first.
     *
     * @param array{wallet: string, from: int|\DateTimeInterface, to: int|\DateTimeInterface, network?: 'base'|'base-sepolia', rpc_urls?: list<string>, intents?: list<string>, transport?: callable, timeout?: int} $options
     *        from/to are inclusive (unix seconds or a DateTime). network defaults to 'base'; rpc_urls to
     *        its public keyless endpoints, tried in order. intents (from createPayment) fill merchant_ref.
     *        transport has the client's signature: (url, payload, timeout) => [httpStatus, decodedBody].
     * @return list<array{date: string, tx_hash: string, kind: string, payer: string, reference: string, merchant_ref: string, gross: string, p2flux_fee: string, network_fee: string, net: string}>
     */
    public static function statement(array $options): array
    {
        $net = self::NETWORKS[$options['network'] ?? 'base'] ?? null;
        if ($net === null) {
            throw new \InvalidArgumentException('unknown network: ' . (string) ($options['network'] ?? ''));
        }
        $wallet = (string) ($options['wallet'] ?? '');
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet) !== 1) {
            throw new \InvalidArgumentException('wallet must be a 0x address');
        }
        $seconds = static fn (mixed $t): ?int => $t instanceof \DateTimeInterface ? $t->getTimestamp() : (is_int($t) ? $t : null);
        $fromTs = $seconds($options['from'] ?? null);
        $toTs = $seconds($options['to'] ?? null);
        if ($fromTs === null || $toTs === null || $fromTs > $toTs) {
            throw new \InvalidArgumentException('from must be a time before to');
        }
        $self = new self($options['rpc_urls'] ?? $net['rpc_urls'], $options['transport'] ?? null, $options['timeout'] ?? 60);

        return $self->rows($net, strtolower($wallet), $fromTs, $toTs, self::merchantRefs($options['intents'] ?? []));
    }

    /**
     * The rows as CSV (CRLF line ends).
     *
     * - generic: every column, as in the rows.
     * - xero: a bank-statement import - Date (dd/mm/yyyy), Amount (net), Payee, Description, Reference
     *   (merchant_ref, else the on-chain reference).
     * - quickbooks: the 3-column bank upload - Date (mm/dd/yyyy), Description, Amount (net).
     *
     * The amount booked is net, what reached the wallet; gross and fees are in the description.
     *
     * @param list<array<string, string>> $rows
     */
    public static function toCsv(array $rows, string $format = 'generic'): string
    {
        $describe = static fn (array $r): string => "{$r['tx_hash']}: gross {$r['gross']}, P2Flux fee {$r['p2flux_fee']}, network fee {$r['network_fee']}";
        $date = static fn (array $r, bool $us): string => $us
            ? substr($r['date'], 5, 2) . '/' . substr($r['date'], 8, 2) . '/' . substr($r['date'], 0, 4)
            : substr($r['date'], 8, 2) . '/' . substr($r['date'], 5, 2) . '/' . substr($r['date'], 0, 4);
        if ($format === 'generic') {
            $lines = [implode(',', self::COLUMNS)];
            foreach ($rows as $r) {
                $lines[] = implode(',', array_map(static fn (string $c): string => self::cell($r[$c], !in_array($c, self::AMOUNTS, true)), self::COLUMNS));
            }
        } elseif ($format === 'xero') {
            $lines = ['Date,Amount,Payee,Description,Reference'];
            foreach ($rows as $r) {
                $lines[] = implode(',', [$date($r, false), $r['net'], self::cell($r['payer']), self::cell("P2Flux {$r['kind']} " . $describe($r)), self::cell($r['merchant_ref'] !== '' ? $r['merchant_ref'] : $r['reference'])]);
            }
        } elseif ($format === 'quickbooks') {
            $lines = ['Date,Description,Amount'];
            foreach ($rows as $r) {
                $ref = $r['merchant_ref'] !== '' ? $r['merchant_ref'] : $r['reference'];
                $lines[] = implode(',', [$date($r, true), self::cell("P2Flux {$r['kind']} {$ref} " . $describe($r)), $r['net']]);
            }
        } else {
            throw new \InvalidArgumentException("unknown CSV format: {$format}");
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** A CSV cell: quoted when needed; a text cell a spreadsheet would run as a formula gets a leading '. */
    private static function cell(string $value, bool $text = true): string
    {
        $v = $text && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;

        return preg_match('/[",\r\n]/', $v) === 1 ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }

    /**
     * @param array<string, mixed> $net
     * @param array<string, string> $refs
     * @return list<array<string, string>>
     */
    private function rows(array $net, string $wallet, int $fromTs, int $toTs, array $refs): array
    {
        $walletTopic = self::topicOf($wallet);
        $latest = $this->block('latest');
        // Widened by a few blocks either way; rows are then held to the dates themselves.
        $fromBlock = max(0, $this->blockNear($fromTs, $latest) - 5);
        $toBlock = min($latest['n'], $this->blockNear($toTs, $latest) + 5);
        $scan = function (string $address, int $deployBlock, array $topics) use ($fromBlock, $toBlock): array {
            $a = max($fromBlock, $deployBlock);

            return $a > $toBlock ? [] : $this->getLogs($address, $topics, $a, $toBlock);
        };

        $entries = [];
        $row = static function (array $l, string $kind, string $payer, string $reference, int $gross, int $fee, int $networkFee, int $netUnits) use ($refs): array {
            return [
                'block' => hexdec($l['blockNumber']),
                'index' => hexdec($l['logIndex']),
                'gross' => $gross,
                'row' => [
                    'tx_hash' => $l['transactionHash'],
                    'kind' => $kind,
                    'payer' => strtolower($payer),
                    'reference' => strtolower($reference),
                    'merchant_ref' => $refs[strtolower($reference)] ?? '',
                    'gross' => self::usdc($gross),
                    'p2flux_fee' => self::usdc($fee),
                    'network_fee' => self::usdc($networkFee),
                    'net' => self::usdc($netUnits),
                ],
            ];
        };

        // One-time payments through the splitter: the payer is whoever's USDC reached the wallet in that transaction.
        $paid = [];
        foreach (array_merge([['address' => $net['splitter'], 'deploy_block' => $net['splitter_deploy_block']]], $net['retired_splitters']) as $c) {
            array_push($paid, ...$scan($c['address'], $c['deploy_block'], [self::TOPICS['paid'], null, $walletTopic]));
        }
        $receipts = $this->rpc(array_map(static fn (array $l): array => ['eth_getTransactionReceipt', [$l['transactionHash']]], $paid));
        foreach ($paid as $i => $l) {
            [$netUnits, $fee] = self::words($l['data']) + [0, 0];
            $payer = (string) ($receipts[$i]['from'] ?? '');
            foreach ($receipts[$i]['logs'] ?? [] as $r) {
                if (strtolower($r['address']) === strtolower($net['usdc']) && ($r['topics'][0] ?? '') === self::TOPICS['transfer']
                    && self::addressOf($r['topics'][2] ?? '') === $wallet && (self::words($r['data'])[0] ?? -1) === $netUnits) {
                    $payer = self::addressOf($r['topics'][1]);
                    break;
                }
            }
            $entries[] = $row($l, 'payment', $payer, $l['topics'][1], $netUnits + $fee, $fee, 0, $netUnits);
        }

        // Sponsored: the merchant funds the fee and the fixed network fee out of the amount; the buyer's quoted network fee is on top.
        foreach (array_merge([['address' => $net['sponsored_splitter'], 'deploy_block' => $net['sponsored_splitter_deploy_block']]], $net['retired_sponsored_splitters']) as $c) {
            foreach ($scan($c['address'], $c['deploy_block'], [self::TOPICS['sponsored_paid'], null, $walletTopic]) as $l) {
                [$netUnits, $fee, , $fixed] = self::words($l['data']) + [0, 0, 0, 0];
                $entries[] = $row($l, 'payment', self::addressOf($l['topics'][3]), $l['topics'][1], $netUnits + $fee + $fixed, $fee, $fixed, $netUnits);
            }
        }

        // Subscription charges: net + fee + networkFee is the amount; the payer's gas reimbursement is on top.
        foreach ($scan($net['recurring'], $net['recurring_deploy_block'], [self::TOPICS['subscription_charged'], null, null, $walletTopic]) as $l) {
            [, $netUnits, $fee, $networkFee] = self::words($l['data']) + [0, 0, 0, 0];
            $entries[] = $row($l, 'subscription', self::addressOf($l['topics'][2]), $l['topics'][1], $netUnits + $fee + $networkFee, $fee, $networkFee, $netUnits);
        }

        // Probable refunds: USDC from the wallet to a payer above, later than one of their payments and no larger.
        $payers = array_values(array_unique(array_filter(
            array_map(static fn (array $e): string => $e['row']['payer'], $entries),
            static fn (string $p): bool => preg_match('/^0x[0-9a-f]{40}$/', $p) === 1
        )));
        foreach (array_chunk($payers, 100) as $chunk) {
            $topics = [self::TOPICS['transfer'], $walletTopic, array_map([self::class, 'topicOf'], $chunk)];
            foreach ($this->getLogs($net['usdc'], $topics, $fromBlock, $toBlock) as $l) {
                $to = self::addressOf($l['topics'][2]);
                $amount = self::words($l['data'])[0] ?? 0;
                $b = hexdec($l['blockNumber']);
                $index = hexdec($l['logIndex']);
                $original = null;
                foreach ($entries as $e) {
                    if ($e['row']['kind'] !== 'refund?' && $e['row']['payer'] === $to && ($e['block'] < $b || ($e['block'] === $b && $e['index'] < $index)) && $e['gross'] >= $amount
                        && ($original === null || [$e['block'], $e['index']] > [$original['block'], $original['index']])) {
                        $original = $e;
                    }
                }
                if ($original !== null) {
                    $entries[] = $row($l, 'refund?', $to, $original['row']['reference'], -$amount, 0, 0, -$amount);
                }
            }
        }

        // Dates, one batched read per distinct block.
        $blocks = array_values(array_unique(array_column($entries, 'block')));
        $times = $this->rpc(array_map(static fn (int $n): array => ['eth_getBlockByNumber', ['0x' . dechex($n), false]], $blocks));
        $tsByBlock = [];
        foreach ($blocks as $i => $n) {
            $tsByBlock[$n] = hexdec($times[$i]['timestamp']);
        }

        $entries = array_values(array_filter($entries, static fn (array $e): bool => $tsByBlock[$e['block']] >= $fromTs && $tsByBlock[$e['block']] <= $toTs));
        usort($entries, static fn (array $x, array $y): int => [$x['block'], $x['index']] <=> [$y['block'], $y['index']]);

        return array_map(
            static fn (array $e): array => ['date' => gmdate('Y-m-d\TH:i:s.000\Z', $tsByBlock[$e['block']])] + $e['row'],
            $entries
        );
    }

    /**
     * Batched JSON-RPC over the URLs, the next URL on any failure; an error inside a batch fails the batch.
     *
     * @param list<array{0: string, 1: list<mixed>}> $calls
     * @return list<mixed>
     */
    private function rpc(array $calls): array
    {
        $out = [];
        foreach (array_chunk($calls, self::BATCH) as $chunk) {
            $batch = [];
            foreach ($chunk as $id => [$method, $params]) {
                $batch[] = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
            }
            $answers = null;
            $last = new \RuntimeException('no RPC URL');
            foreach ($this->urls as $url) {
                try {
                    [$status, $replies] = ($this->transport)($url, $batch, $this->timeout);
                    if ($status >= 400 || !array_is_list($replies)) {
                        throw new \RuntimeException("RPC {$url}: HTTP {$status}");
                    }
                    $byId = array_column($replies, null, 'id');
                    $answers = [];
                    foreach (array_keys($chunk) as $id) {
                        $r = $byId[$id] ?? null;
                        if (!is_array($r) || isset($r['error']) || !array_key_exists('result', $r)) {
                            throw new \RuntimeException("RPC {$url}: " . (string) ($r['error']['message'] ?? 'no answer'));
                        }
                        $answers[] = $r['result'];
                    }
                    break;
                } catch (\RuntimeException $e) { // P2FluxException (NETWORK_ERROR) is one too
                    $answers = null;
                    $last = $e;
                }
            }
            if ($answers === null) {
                throw $last;
            }
            array_push($out, ...$answers);
        }

        return $out;
    }

    /**
     * Every log matching $topics on $address in [from, to], halving any range the node refuses.
     *
     * @param list<mixed> $topics
     * @return list<array<string, mixed>>
     */
    private function getLogs(string $address, array $topics, int $from, int $to): array
    {
        $out = [];
        $range = function (int $a, int $b) use (&$range, &$out, $address, $topics): void {
            try {
                [$logs] = $this->rpc([['eth_getLogs', [['address' => $address, 'topics' => $topics, 'fromBlock' => '0x' . dechex($a), 'toBlock' => '0x' . dechex($b)]]]]);
                array_push($out, ...$logs);
            } catch (\RuntimeException $e) {
                if ($a === $b) {
                    throw $e;
                }
                $mid = intdiv($a + $b, 2);
                $range($a, $mid);
                $range($mid + 1, $b);
            }
        };
        for ($a = $from; $a <= $to; $a += self::LOG_SPAN) {
            $range($a, min($to, $a + self::LOG_SPAN - 1));
        }

        return $out;
    }

    /** @return array{n: int, ts: int} */
    private function block(int|string $tag): array
    {
        [$b] = $this->rpc([['eth_getBlockByNumber', [$tag === 'latest' ? $tag : '0x' . dechex((int) $tag), false]]]);

        return ['n' => hexdec($b['number']), 'ts' => hexdec($b['timestamp'])];
    }

    /**
     * The block near $ts: an estimate at Base's 2 s per block, corrected twice. Callers widen and filter by date.
     *
     * @param array{n: int, ts: int} $latest
     */
    private function blockNear(int $ts, array $latest): int
    {
        if ($ts >= $latest['ts']) {
            return $latest['n'];
        }
        $n = max(0, $latest['n'] - (int) ceil(($latest['ts'] - $ts) / 2));
        for ($i = 0; $i < 2; $i++) {
            $b = $this->block($n);
            if ($b['ts'] === $ts) {
                break;
            }
            $n = min($latest['n'], max(0, $n + (int) round(($ts - $b['ts']) / 2)));
        }

        return $n;
    }

    /**
     * reference (lowercase) => merchant_ref, read from the intents' own payloads.
     *
     * @param list<string> $intents
     * @return array<string, string>
     */
    private static function merchantRefs(array $intents): array
    {
        $map = [];
        foreach ($intents as $intent) {
            $json = base64_decode(strtr(explode('.', (string) $intent)[2] ?? '', '-_', '+/'), true);
            $p = is_string($json) ? json_decode($json, true) : null;
            if (is_array($p) && is_string($p['reference'] ?? null) && is_string($p['mr'] ?? null)) {
                $map[strtolower($p['reference'])] = $p['mr'];
            }
        }

        return $map;
    }

    private static function topicOf(string $address): string
    {
        return '0x' . str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    private static function addressOf(string $topic): string
    {
        return '0x' . strtolower(substr($topic, -40));
    }

    /**
     * The data's 32-byte words as ints. ponytail: int64 - USDC amounts fit by twelve orders of magnitude.
     *
     * @return list<int>
     */
    private static function words(string $data): array
    {
        $words = [];
        foreach ($data === '0x' ? [] : str_split(substr($data, 2), 64) as $w) {
            if (strlen($w) !== 64 || ltrim(substr($w, 0, 49), '0') !== '') {
                throw new \UnexpectedValueException('log value out of range');
            }
            $words[] = (int) hexdec(substr($w, 49));
        }

        return $words;
    }

    private static function usdc(int $units): string
    {
        $s = str_pad((string) abs($units), 7, '0', STR_PAD_LEFT);

        return ($units < 0 ? '-' : '') . substr($s, 0, -6) . '.' . substr($s, -6);
    }
}
