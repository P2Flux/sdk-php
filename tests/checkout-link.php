<?php

declare(strict_types=1);

/**
 * checkoutLink(): the address that opens a checkout page, on P2Flux's hosted checkout or on one the
 * merchant hosts. No API needed.
 *
 *   php tests/checkout-link.php
 */

require __DIR__ . '/../src/P2FluxException.php';
require __DIR__ . '/../src/ChargeResult.php';
require __DIR__ . '/../src/P2FluxClient.php';

use P2Flux\P2FluxClient;

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

function refuses(callable $fn, string $pattern = ''): bool
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $pattern === '' || preg_match($pattern, $e->getMessage()) === 1;
    }
    return false;
}

$live = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com']);
$link = $live->checkoutLink('pay', 'p2f1.a.b.c');
check('hosted checkout by default (live)', $link === 'https://pay.p2flux.com/#/pay/p2f1.a.b.c', $link);
$test = new P2FluxClient(['apiUrl' => 'https://api-test.p2flux.com/']);
$link = $test->checkoutLink('subscribe', 'tok');
check('hosted checkout by default (test)', $link === 'https://pay-test.p2flux.com/#/subscribe/tok', $link);

$own = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'checkoutUrl' => 'https://pay.example.com/']);
$link = $own->checkoutLink('refund', 'r');
check('self-hosted on a domain', $link === 'https://pay.example.com/#/refund/r', $link);
$sub = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'checkoutUrl' => 'https://example.com/pay//']);
$link = $sub->checkoutLink('approve', 'a');
check('self-hosted on a sub-path', $link === 'https://example.com/pay/#/approve/a', $link);
$link = $own->checkoutLink('pay', 'a/b#c');
check('the token cannot leave the fragment', $link === 'https://pay.example.com/#/pay/a%2Fb%23c', $link);
$local = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'checkoutUrl' => 'http://localhost:5173']);
check('http allowed for localhost only', $local->checkoutLink('pay', 't') === 'http://localhost:5173/#/pay/t');

// A wrong optional setting fails the link, never the client: payments keep working.
foreach (['http://pay.example.com', 'https://pay.example.com/?x=1', 'https://user:pw@pay.example.com', 'not a url', 'ftp://localhost', 'http://localhost.evil.com'] as $bad) {
    $client = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'checkoutUrl' => $bad]);
    check("constructs with checkoutUrl {$bad}", $client instanceof P2FluxClient);
    check("refuses the link for {$bad}", refuses(static fn () => $client->checkoutLink('pay', 't')));
}
$empty = new P2FluxClient(['apiUrl' => 'https://api.p2flux.com', 'checkoutUrl' => '']);
check('an empty setting means not set', $empty->checkoutLink('pay', 't') === 'https://pay.p2flux.com/#/pay/t');
$upper = new P2FluxClient(['apiUrl' => 'https://API.P2FLUX.COM']);
check('the API host is case-insensitive', $upper->checkoutLink('pay', 't') === 'https://pay.p2flux.com/#/pay/t');
$unknown = new P2FluxClient(['apiUrl' => 'http://localhost:3000']);
check('an own API needs an explicit checkoutUrl', refuses(static fn () => $unknown->checkoutLink('pay', 't'), '/checkoutUrl is required/'));
check('an empty token refused', refuses(static fn () => $live->checkoutLink('pay', '')));
check('an unknown page refused', refuses(static fn () => $live->checkoutLink('admin', 't')));

echo $failures === 0 ? "\ncheckout link OK\n" : "\n{$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
