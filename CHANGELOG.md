# Changelog

## 0.5.0 - 2026-08-24

### Added

- **Complete public V1 parity — 15/15 operations.** `resolvePayment()` (authoritative display
  terms from an intent), `resolveSubscription()` (terms plus the exact EIP-712 `typed_data` the
  customer signs), `finalizeSubscription()` (the customer's signature → the `p2s2.` charge
  capability, for merchants running their own checkout instead of the hosted one) and
  `resolveRefund()` (what a refund token authorizes). The JS SDK reached the same 15 in its
  v0.4.0, so both official SDKs now cover the identical public merchant/server surface.
- **A parity guard in the test suite.** `tests/transport.php` carries the checked-in list of all
  15 public V1 merchant operations, calls every method against the stub transport, and fails if
  any operation stops being reachable — the JS SDK and P2Flux/core hold the same list, so a new
  public endpoint turns all three red until both SDKs support it.
- `ACTIONS` entries for the codes the API ships without an `action`: dead or mismatched tokens
  (`INVALID_INTENT`, `SETUP_TOKEN_EXPIRED`, `TERMS_MISMATCH`, …) map to `INVALID_REQUEST` instead
  of falling back to `RETRY_LATER` — retrying a dead token forever helps nobody —
  `TRANSACTION_NOT_FOUND` stays `RETRY_LATER` (the chain can still answer differently), and
  `SIGNATURE_VALIDATION_TOO_EXPENSIVE` is `CUSTOMER_ACTION_REQUIRED`, because only the customer
  can switch wallets.
- `examples/` — a one-time payment (`one-time.php`), a subscription from setup to cancellation
  (`subscription.php`), and a refund (`refund.php`), all runnable in-repo.

### Fixed

- **The README transport example never worked as written.** It passed the payload where
  `wp_remote_post` expects its options array (so nothing was sent) and returned the raw JSON
  string where the client expects a decoded array (so every call quietly became an empty body).
  The documented contract is now the real one — `fn(string $url, array $payload, int $timeout):
  [int, array]` — with a working WordPress sample; `docs/protocol-contract.md` matches.
- `charge()` silently discarded the caught transport exception, so the one place an operator most
  needs to know WHY the API was unreachable — the renewal job's log — never saw curl's detail.
  The exception's raw body now rides into `ChargeResult->raw`.
- `verifyRefund()` recognised `REFUND_CONFIRMING` only under the `error` key; it now also accepts
  `code`, exactly as `recoverPayment()` always has, so the answer survives either response shape.

### Changed

- curl now sets `CURLOPT_CONNECTTIMEOUT` (10 s, bounded by the `timeout` option): a black-holed
  host fails in seconds instead of holding a renewal worker for the full request timeout.

## 0.4.0 - 2026-08-21

### Added

- **`verifyPayment()` accepts an optional third argument, `$settlementReceipt`** - the sealed
  token a previous CONFIRMED verification returned (couriered from the buyer's checkout to your
  callback). Passing it lets the server answer without re-reading the chain; a missing, expired or
  mismatched receipt silently falls back to the full verification, so it is always safe to pass
  whatever the browser handed you. Omitting it keeps the exact previous behaviour, and old servers
  ignore nothing - the field is only sent when non-empty.

### Fixed

- **`verifyRefund()` threw on a refund that was merely still settling**, contradicting its own
  documentation. A caller forced to catch an exception to learn "wait a moment" is a caller that
  eventually sends a second refund. `REFUND_CONFIRMING` is now returned as a result, matching the JS
  SDK; every other failure still throws.

- **Six codes had no `ACTIONS` entry**, so the `?? 'RETRY_LATER'` fallback reported permanent
  failures as retryable — a merchant would retry a dead refund token forever. Added:
  `INVALID_REFUND_TOKEN` and `REFUND_TOKEN_EXPIRED` (`INVALID_REQUEST`), and `PAYMENT_NOT_FOUND`,
  `PAYMENT_RECOVERY_INCONSISTENT`, `RECOVERY_UNAVAILABLE` (`RETRY_LATER`).

### Changed

- **`REFUND_CONFIRMING` now arrives as HTTP 409 from the API** (previously 400). Handling is keyed on
  the error code, so both statuses behave identically and an older deployment keeps working.
