# Paying the network fee in USDC

Live on Base Mainnet and Base Sepolia. A buyer holding USDC and no ETH signs a token authorization
instead of sending a transaction; P2Flux submits it and pays the Base network fee in ETH, and the
buyer reimburses that cost in USDC inside the same transaction.

**Nothing is waived here.** The network fee is real, it is quoted before the buyer signs, and the
buyer pays it — in USDC rather than in ETH. USDC is never converted, nothing is fronted on credit, and
settlement stays direct: your share moves from the buyer's wallet to yours in that one transaction.

Runnable: [`examples/network-fee-in-usdc.php`](../examples/network-fee-in-usdc.php).

## Check the capability first

Architectural possibility is not support. A token that implements the right standards on a network
P2Flux has not deployed to reports `false`, and the request is refused with
`PAYMENT_TOKEN_GAS_UNSUPPORTED` before a buyer sees anything.

```php
$caps = $p2flux->capabilities();
$usdc = array_values(array_filter($caps['tokens'], fn ($t) => $t['symbol'] === 'USDC'))[0] ?? null;
$noEthPath = $usdc !== null && in_array('payment_token', $usdc['gas_payment_modes'], true);
```

`capabilities()` changes only when the deployment does — read it at start-up, not per checkout.
`$usdc['operations']` reports it per operation (`one_time_payment`, `subscription_signup`,
`allowance_restore`, `allowance_removal`).

## Create the payment

```php
$payment = $p2flux->createPayment([
    'recipient' => $merchantWallet,
    'amount' => '12.50',
    'gas_payment_mode' => $noEthPath ? 'payment_token' : 'native',
]);
```

Everything else is unchanged: the same intent, the same hosted checkout URL, the same
`verifyPayment()`. Without the field nothing changes at all — the buyer sends the transaction and
pays the network fee in ETH, exactly as before. This is opt-in, per payment.

The checkout prices the network fee, shows the buyer the total before anything is signed, re-checks
the price at the moment they click, and asks them to confirm if it moved. A wallet that can pay its
own gas is offered the ordinary path instead.

## Read the accounting

```php
$verdict = $p2flux->verifyPayment($payment['intent'], $txHash);
if (($verdict['valid'] ?? false) && isset($verdict['accounting'])) {
    $verdict['gas_payment_mode'];                       // 'payment_token' or 'native'
    $verdict['accounting']['buyer_total_units'];        // price + the quoted network fee, and nothing else
    $verdict['accounting']['merchant_net_units'];       // price - 1% - the fixed 0.10 network fee
    $verdict['accounting']['payment_fee_units'];        // the 1%
    $verdict['accounting']['fixed_network_fee_units'];  // 0.10 USDC, merchant-funded, as on a renewal
    $verdict['accounting']['network_fee_units'];        // quoted before the buyer signed; exactly what was charged
    $verdict['accounting']['payment_units'];            // the price itself
    $verdict['accounting']['payer'];                    // the wallet that paid
}
```

Every figure is in USDC base units: 1 USDC = 1000000. `recoverPayment()` returns the same block.

**Who funds what does not change.** The 1% and the fixed 0.10 USDC network fee come out of the
amount, exactly as a subscription collection works. The buyer is debited the price plus the quoted
network fee and nothing else.

## Subscriptions take the same path

A customer with no ETH can complete signup, and repair or remove an allowance, from the hosted
checkout — no change on your side, and no additional fee, because a subscription already pays its
fixed network fee on every collection. `resolveSubscription($setupToken, 'payment_token', $payer)`
and `resolveAllowanceRestore($token, 'payment_token')` are the server-side entry points for an
integration that builds its own wallet screen.

## Per-wallet limits

A buyer wallet may ask P2Flux to send at most **10 sponsored transactions in any rolling hour and 20
in any rolling day**, counted across every merchant and operation. Over that, the API answers
`RATE_LIMITED` (HTTP 429) with `retry_after` and nothing is spent; the checkout tells the buyer to
try later, or to pay the network fee with ETH where their wallet can.

Your `charge()` calls are never sponsored transactions and are never counted against this.

## Contracts

`capabilities()` returns `sponsor_contracts` — the contract carrying each operation. On Base
Mainnet: `P2FluxSponsoredSplitter` `0x95E18ec05D4282acB3aab7aD60325bA4EEeEa8df` for one-time
payments, `P2FluxGasSponsor` `0xD1DDAaa301403d18fD4A23Fc69493ef48af90285` for signup, allowance
restore and removal. Read them from the API rather than pinning constants.

## Errors specific to this mode

| Code | Meaning |
|---|---|
| `PAYMENT_TOKEN_GAS_UNSUPPORTED` | This deployment does not offer it for that token. Fall back to `native`; retrying cannot help. |
| `PAYMENT_TOKEN_GAS_UNAVAILABLE` | Temporarily unavailable. Retry later. |
| `PAYMENT_TOKEN_GAS_QUOTE_EXPIRED` | The price moved. The buyer requotes and signs again. |
| `PAYMENT_TOKEN_GAS_LIMIT_EXCEEDED` | Operator-side ceiling reached. Retry later. |
| `INSUFFICIENT_PAYMENT_TOKEN_FOR_GAS` | The wallet cannot cover price plus network fee. |
| `RATE_LIMITED` | The per-wallet limit above. Nothing was spent. |
| `SPONSORSHIP_CONFIRMING` | In flight — look the settlement up, never send another. |

Full list: [Errors and retries](errors.md).
