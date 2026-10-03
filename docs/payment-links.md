# Payment links

A payment link is a standing offer you send as a URL - by e-mail, in a chat, as a QR code - with no
server of your own. Nothing is stored to create one: the link is signed terms, like an intent.

| Kind | What the buyer gets | Valid for |
|---|---|---|
| `once` | An invoice. It can be paid once: every open mints the same reference, and the contract refuses a second payment with it. | up to 30 days (default 7) |
| `reusable` | A fixed price, payable any number of times. | up to a year |
| `subscription` | A plan: the buyer signs once and pays the first period at once; P2Flux collects every later period. At least 1 USDC a period, period at least one day. | up to a year (for new signups) |

```php
$plan = $p2flux->createPaymentLink([
    'kind' => 'subscription',
    'recipient' => getenv('P2FLUX_RECIPIENT'),
    'amount' => '9.00',
    'period' => 30 * 86400,
    'periods' => 12, // omit for until cancelled
    'label' => 'Monthly support plan',
]);

$p2flux->checkoutLink('link', $plan['link']); // send this to buyers
$p2flux->checkoutLink('links', $plan['manage']); // your private overview - keep it like a password
```

## What you see

```php
$status = $p2flux->paymentLinkStatus(['manage' => $plan['manage']]);
$status['subscribers']; // who subscribed, their state, the last period paid, the next attempt
```

- `once`: `paid` and the `payment` (with the payer when you ask with `manage`).
- `reusable`: every `payments` entry read from the chain. Reading is incremental and remembered; when
  `complete` is `false`, ask again for the rest.
- `subscription`: `subscribers`. Each due period is collected automatically; when the buyer cannot pay,
  P2Flux retries after 1 hour, 6 hours and then daily, only in the first quarter of the period (at most
  3 days), and then skips that period - the contract has no catch-up. Three periods in a row that the
  buyer could not pay end the subscription; a period missed because of trouble on P2Flux's side is
  skipped too but never counts against the buyer. A subscriber with no successful payment for 7 days (the link's
  `suspend_after_days`, 1 to 90, set when you create it), for any reason, is paused - `suspended`,
  not ended: its place is freed and a successful `collectPaymentLink` restarts it.

`collectPaymentLink($manage, $subscriptionId)` collects the current period now; `stopPaymentLink()`
stops automatic collection for one subscriber (a successful collect restarts it; a subscriber stopped for three
periods ends). An ended subscription is never collected again. Only the buyer's wallet
can revoke the permission on chain - they do it by opening the link again and choosing "Manage your
subscription".

## Signing up is paying

The buyer's checkout opens the link (a setup token), the buyer signs, and the checkout joins the link
with the signed capability (`/v1/links/subscribe`, `subscribePaymentLink` in the SDKs): the first
period is charged at once, and only when that charge has landed (or is confirming) is the subscription kept. A signup
that does not pay keeps nothing.

## The description

The `label` is shown to the buyer as "Note from the link's creator, not verified by P2Flux": up to 60
characters - letters, ASCII digits, currency signs, spaces and `. , : ; ' ( ) # & + _ ! ? % - /`; nothing
that reads as a web or e-mail address (so no letter right after a full stop) and no invisible or
look-alike characters, and no mention of P2Flux.

## Errors

`INVALID_LINK`, `LINK_EXPIRED`, `LINK_UNAVAILABLE`, `ALREADY_SUBSCRIBED` - see [Errors](errors.md).
