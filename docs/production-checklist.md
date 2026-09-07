# Production checklist

Short, and every line is something that has gone wrong in a real integration.

## Money

- [ ] **Payments are created server-side.** The recipient and the amount come from your
      configuration and your own records, never from a request body.
      → [The payment lifecycle](payment-flow.md)
- [ ] **Nothing is fulfilled on a browser message.** `p2flux.payment.completed` is a claim; the
      server's `verifyPayment()` verdict is the decision. → [Payments](payments.md)
- [ ] **The paid transition happens once**, under a row lock or a unique constraint, with the status
      re-checked inside the transaction. Repeat callbacks are normal.
- [ ] **Your own reference is stored with the intent**, before the buyer leaves the page. Recovery
      needs the intent, and works long after it expires. → [Recovery](recovery.md)
- [ ] **`CONFIRMING` is not a failure and not a success.** Poll the same hash. Never ask the buyer to
      pay again and never send a second charge.
- [ ] **`ALREADY_CHARGED` is a success.** It is the normal answer to a retry after a timeout.
      → [Subscriptions](subscriptions.md)
- [ ] **One refund per payment is enforced by you**, before `prepareRefund()`. P2Flux keeps no refund
      history. → [Refunds](refunds.md)

## Secrets and logs

- [ ] **The `p2s2` capability is encrypted at rest**, server-side only. It can charge. Never in a
      URL, a log, an error report or a browser.
- [ ] **Logs carry identifiers, not tokens.** Your order id and the transaction hash are useful;
      intents, setup tokens, capabilities, approve/cancel/refund tokens are not, and must be redacted.
- [ ] **Every call goes over HTTPS**, to the API URL you configured. There is no API key to leak,
      because v1 has no API authentication.

## Configuration

- [ ] **The environment is stored per order.** Test tokens are refused by production and the
      reverse; a merchant who flips a setting still has old orders to serve.
      → [Getting started](getting-started.md#environments)
- [ ] **Timeouts are set deliberately.** The default is 60 s because a charge waits for
      confirmation. A host framework's default (WordPress: 5 s) abandons most charges.
- [ ] **`capabilities()` is checked before offering an optional feature**, such as letting a buyer
      pay the network fee in USDC. It is read at start-up, not per checkout.
      → [Paying the network fee in USDC](network-fee-in-usdc.md)

## Failure paths

- [ ] **Errors are classified on `action`, not on `status`**, so an unfamiliar code still lands in
      the right branch. → [Errors and retries](errors.md)
- [ ] **An unreachable API is "unknown", never "declined".** Retry; do not cancel a subscription that
      may have just paid.
- [ ] **A recovery sweep exists.** A cron over orders pending too long, calling `recoverPayment()`,
      and `recoverCharge()` for periods that answered `ALREADY_CHARGED`. → [Recovery](recovery.md)
- [ ] **Retries are bounded and honour `retry_after`** on `RATE_LIMITED` and `CONCURRENCY_LIMIT`.

## Before launch

- [ ] The whole flow was run against `https://api-test.p2flux.com` on Base Sepolia.
- [ ] Your verify endpoint was called twice with the same payload, and fulfilled once.
- [ ] Your renewal job was run twice in the same period, and charged once.
- [ ] → [Testing](testing.md) covers all three offline.
