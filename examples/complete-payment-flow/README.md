# Complete payment flow

A small merchant integration you can run: create an order, pay it in the hosted checkout, and let
the server decide whether it is paid.

```
index.php    the shop page, one product
create.php   creates the order and the payment intent, opens the checkout, relays the claim
verify.php   the trust boundary: verifyPayment() decides, and only then is the order paid
status.php   what the server believes about an order
bootstrap.php  the client, and a tiny order store
```

## Run it against the canned API

No wallet, no USDC, no chain. `tests/stub-api.php` answers like the real API.

```bash
composer install                                     # from the repository root
php -S 127.0.0.1:8100 tests/stub-api.php &           # the canned API
cd examples/complete-payment-flow
P2FLUX_API_URL=http://127.0.0.1:8100 \
P2FLUX_RECIPIENT=0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee \
  php -S 127.0.0.1:8101
```

Open <http://127.0.0.1:8101/>. The canned checkout cannot open a wallet, so drive the last step
yourself:

```bash
curl -s -X POST http://127.0.0.1:8101/verify.php -H 'Content-Type: application/json' \
  -d '{"order":"<id from the page>","tx_hash":"0x1111111111111111111111111111111111111111111111111111111111111111"}'
```

`tests/complete-flow.php` runs exactly this, end to end, as part of `composer test`.

## Run it for real

Point it at the test environment and use your own wallet:

```bash
P2FLUX_API_URL=https://api-test.p2flux.com \
P2FLUX_CHECKOUT_URL=https://pay-test.p2flux.com \
P2FLUX_RECIPIENT=0xYourPayoutWallet \
  php -S 127.0.0.1:8101
```

Base Sepolia, faucet USDC. Production is `https://api.p2flux.com` with real money.

## What this demonstrates

1. **The intent is created server-side.** A browser can never choose the recipient or the amount.
2. **Your own order reference comes first.** The intent is stored beside it, because verification
   and recovery both need it, and recovery still works after the intent expires.
3. **The browser message is a claim.** `p2flux.payment.completed` is handed to the server and
   nothing else. The page cannot mark anything paid.
4. **The server verifies before fulfilling.** `verifyPayment()` re-reads the settlement on chain.
5. **Verification is repeat-safe.** A second call on a paid order returns the same answer and
   changes nothing, so a double-submitted page or a retried request cannot fulfil twice.
6. **A lost hash is recoverable.** With no `tx_hash` in the claim, `verify.php` falls back to
   `recoverPayment()`, which finds the settlement from the intent alone.

## The order store is educational only

`bootstrap.php` keeps one JSON file per order under `var/` so the demo runs with nothing installed.
It writes to a temporary file and renames it, so a reader never sees a half-written order, and
`verify.php` is safe to call repeatedly.

**Do not model a production integration on it.** Keep orders in your application's database, and
make the unpaid → paid transition inside a transaction: take a row lock (`SELECT … FOR UPDATE`) or
put a unique constraint on the intent, re-check the status inside the lock, and write the
transaction hash in the same statement that flips the status. That is what makes concurrent
verifications - two browser tabs, a retry racing a cron sweep - fulfil exactly once.

Nothing else here changes: the same calls, in the same order, with the same verdicts.

## Next

- [Payment lifecycle](../../docs/payment-flow.md)
- [Production checklist](../../docs/production-checklist.md)
- [Laravel](../../docs/frameworks/laravel.md) · [Symfony](../../docs/frameworks/symfony.md)
