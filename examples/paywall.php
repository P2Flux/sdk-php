<?php

declare(strict_types=1);

/**
 * Charge AI agents for a page, in USDC, over x402.
 *
 * Serve this file with any PHP server. A request without payment gets 402 with the price; an agent
 * pays and repeats it; the content is served once per payment. People with a browser pass free here
 * (`agentsOnly`); leave it out for an API, where every caller pays.
 */

require __DIR__ . '/../vendor/autoload.php';

use P2Flux\P2FluxClient;
use P2Flux\Paywall;

// Production is https://api.p2flux.com (real USDC on Base): set P2FLUX_API_URL to it, with a mainnet
// wallet you control as P2FLUX_RECIPIENT. The fallback here is the test API (Base Sepolia, test USDC)
// so the example never takes real money by accident. Test the 402 -> pay -> 200 cycle there first.
$p2flux = new P2FluxClient(['apiUrl' => getenv('P2FLUX_API_URL') ?: 'https://api-test.p2flux.com']);
$paywall = new Paywall($p2flux, [
    'recipient' => getenv('P2FLUX_RECIPIENT'),   // your wallet on Base
    'price' => '0.05',                           // USDC per request
    'agentsOnly' => true,
]);

$url = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
$result = $paywall->guard(
    $_SERVER['HTTP_PAYMENT_SIGNATURE'] ?? $_SERVER['HTTP_X_PAYMENT'] ?? null,
    $url,
    $_SERVER['HTTP_USER_AGENT'] ?? null,
);

foreach ($result['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
if (!$result['allow']) {
    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode($result['body']);
    exit;
}

echo '<h1>The paid article</h1><p>Served once per payment.</p>';
