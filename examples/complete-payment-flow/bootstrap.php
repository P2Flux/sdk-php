<?php

declare(strict_types=1);

/**
 * Shared setup for the demo: the P2Flux client, and a tiny order store.
 *
 * THE ORDER STORE IS EDUCATIONAL ONLY. It writes one JSON file per order so the demo runs with
 * nothing installed. A production integration keeps orders in its application's database and makes
 * the "paid" transition inside a transaction - see README.md.
 */

require __DIR__ . '/../../vendor/autoload.php';

use P2Flux\P2FluxClient;

function config(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        http_response_code(500);
        exit("Missing required environment variable {$name}\n");
    }

    return $value;
}

function p2flux(): P2FluxClient
{
    static $client = null;

    return $client ??= new P2FluxClient([
        'apiUrl' => config('P2FLUX_API_URL', 'https://api.p2flux.com'),
        'timeout' => 30,
    ]);
}

function checkoutUrl(): string
{
    return rtrim(config('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com'), '/');
}

function storeDir(): string
{
    $dir = getenv('DEMO_STORE_DIR') ?: __DIR__ . '/var';
    if (!is_dir($dir)) {
        mkdir($dir, 0o700, true);
    }

    return $dir;
}

/**
 * @return array<string, mixed>|null
 */
function loadOrder(string $id): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
        return null;   // never let a request id reach the filesystem unchecked
    }
    $file = storeDir() . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/**
 * Write-then-rename, so a reader never sees a half-written order.
 *
 * A real integration does this with its database instead, and takes a row lock (or relies on a
 * unique constraint on the intent) around the unpaid -> paid transition.
 *
 * @param array<string, mixed> $order
 */
function saveOrder(array $order): void
{
    $file = storeDir() . '/' . $order['id'] . '.json';
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
}

/**
 * @param array<string, mixed> $body
 */
function jsonResponse(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}
