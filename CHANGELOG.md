# Changelog

## Unreleased

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
