<?php

declare(strict_types=1);

/** What the SERVER believes about an order. The only answer that matters. */

require __DIR__ . '/bootstrap.php';

$order = loadOrder((string) ($_GET['order'] ?? ''));
if ($order === null) {
    jsonResponse(404, ['status' => 'unknown_order']);
    exit;
}

jsonResponse(200, [
    'order' => $order['id'],
    'status' => $order['status'],
    'amount' => $order['amount'],
    'tx_hash' => $order['tx_hash'],
]);
