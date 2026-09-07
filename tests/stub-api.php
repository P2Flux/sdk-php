<?php

declare(strict_types=1);

/**
 * A canned P2Flux API for validating the examples and the documentation offline.
 *
 *   php -S 127.0.0.1:8123 tests/stub-api.php
 *
 * It answers the shapes the real API answers and touches no network, no chain and no money. It is
 * a fixture, not a simulator: it never decides anything, it only replays.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$request = json_decode(file_get_contents('php://input') ?: '[]', true);
$request = is_array($request) ? $request : [];

$responses = [
    '/v1/capabilities' => [
        'chain_id' => 8453,
        'network' => 'Base',
        'native_currency' => 'ETH',
        'supported' => true,
        'tokens' => [[
            'address' => '0x' . str_repeat('c', 40),
            'symbol' => 'USDC',
            'decimals' => 6,
            'gas_payment_modes' => ['native', 'payment_token'],
            'fixed_network_fee_units' => '100000',
            'operations' => [
                'one_time_payment' => true,
                'subscription_signup' => true,
                'allowance_restore' => true,
                'allowance_removal' => true,
            ],
            'sponsor_contracts' => [
                'one_time_payment' => '0x' . str_repeat('a', 40),
                'subscription_signup' => '0x' . str_repeat('b', 40),
            ],
            'zero_native_revoke' => false,
        ]],
    ],
    '/v1/payments' => ['intent' => 'p2f1.k1.stub.mac', 'reference' => '0xref', 'amount' => '12.500000'],
    '/v1/payments/verify' => [
        'valid' => true,
        'tx_hash' => '0x' . str_repeat('1', 64),
        'block_number' => 50966621,
        'reference' => '0xref',
        'amount' => '12.500000',
        'gas_payment_mode' => 'payment_token',
        'settlement_receipt' => 'p2r2.k1.stub.mac',
        'accounting' => [
            'payment_units' => '12500000',
            'payment_fee_units' => '125000',
            'network_fee_units' => '4147',
            'fixed_network_fee_units' => '100000',
            'merchant_net_units' => '12275000',
            'buyer_total_units' => '12504147',
            'payer' => '0x' . str_repeat('d', 40),
        ],
    ],
    '/v1/payments/recover' => [
        'found' => true,
        'valid' => true,
        'tx_hash' => '0x' . str_repeat('1', 64),
        'block_number' => 50966621,
        'amount' => '12.500000',
        'as_of_block' => 50966700,
    ],
    '/v1/payments/resolve' => ['intent' => 'p2f1.k1.stub.mac', 'amount' => '12.500000', 'recipient' => '0x' . str_repeat('e', 40)],
    '/v1/subscriptions' => ['setup_token' => 'p2setup2.k1.stub.mac', 'salt' => '12345', 'amount' => '5.000000'],
    '/v1/subscriptions/status' => [
        'terms' => [
            'salt' => '12345',
            'amount_units' => '5000000',
            'recipient' => '0x' . str_repeat('e', 40),
            'period' => 2592000,
        ],
        'due' => true,
        'charged_this_period' => false,
        'period_index' => 3,
    ],
    '/v1/charges' => [
        'status' => 'CHARGED',
        'action' => 'SUCCESS',
        'tx_hash' => '0x' . str_repeat('2', 64),
        'amount' => '5.000000',
        'period_index' => 3,
        'next_period_at' => '2026-10-07T00:00:00Z',
    ],
    '/v1/charges/recover' => [
        'found' => true,
        'tx_hash' => '0x' . str_repeat('2', 64),
        'block_number' => 50966800,
        'period_index' => 3,
        'amount_units' => '5000000',
    ],
    '/v1/subscriptions/revoke/session' => ['cancel_token' => 'p2cancel1.k1.stub.mac'],
    '/v1/allowances/restore/session' => ['approve_token' => 'p2approve1.k1.stub.mac'],
    '/v1/refunds/prepare' => [
        'refund_token' => 'p2refund1.k1.stub.mac',
        'refund_amount' => '2.500000',
        'merchant' => '0x' . str_repeat('e', 40),
        'payer' => '0x' . str_repeat('d', 40),
    ],
    '/v1/refunds/verify' => ['status' => 'REFUNDED', 'refund_tx_hash' => '0x' . str_repeat('3', 64)],
];

/* Two request-aware answers, so the examples and the complete-flow demo can exercise the branches
 * that matter without a chain: a transaction that is still confirming, and one that settles nothing.
 * Everything else is a flat replay. */
if ($path === '/v1/payments/verify') {
    $txHash = (string) ($request['tx_hash'] ?? '');
    if (str_starts_with($txHash, '0xc0')) {
        $responses[$path] = ['valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => $txHash];
    } elseif (str_starts_with($txHash, '0xbad')) {
        $responses[$path] = ['valid' => false, 'code' => 'TRANSACTION_NOT_FOUND'];
    }
}

header('Content-Type: application/json');
if (!isset($responses[$path])) {
    http_response_code(404);
    echo json_encode(['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']);
    return;
}

echo json_encode($responses[$path]);
