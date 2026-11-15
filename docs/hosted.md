# Hosted mode

Hosted mode turns a Tocsin server into a public service, like ntfy.sh: anyone signs up, creates topics and publishes **without running a server**, within a daily quota, with paid plans to go further. This is how Telkine runs `push.telkine.net`.

Self-hosted instances never need it: without it, Tocsin has no quota and no paid plan.

## Enabling it

1. Start the instance with `TOCSIN_HOSTED=true` (Coolify → Environment Variables), with a mail server configured (**Settings → Email**, Resend or SMTP): new accounts confirm their email address before they can publish.
2. **Settings → General → Open registration**.
3. **Settings → Plans** (named **Relay** when the relay operator mode is on too):
   - the free plan: messages per day and topics per account;
   - the price and daily limit of the **Pro** and **Business** plans (a price of 0 hides a plan);
   - the payment providers: **CinetPay** (mobile money) and/or **Stripe** (card), with the notification URLs shown on the page.

The same plans apply to relay instances (where the limit counts notifications) and to hosted accounts (where it counts published messages).

## How quotas work

- A message counts against the account that **owns the topic**, whoever publishes it (token, webhook, anonymous publishing on a public topic).
- Repeated alerts **grouped** into an existing message (`X-Collapse`, identical messages) do not count: they send no notification.
- Over the limit, publishing is refused until midnight: `429` with `{"error": "quota_exceeded", "upgrade_url": "…"}` on the API, and ntfy's error `42908` on the ntfy-compatible endpoints. The account owner gets an email once a day at 80 %.
- Free accounts can own a limited number of topics (10 by default); paid accounts have no limit.
- Administrators are never limited.

## Paying

Each account sees its usage and the plans in **My plan**. A plan is bought for **30 days**, by mobile money or card, and is active as soon as the payment provider confirms it. Plans do not renew automatically: at the end, the account goes back to the free plan, and the owner can extend it at any time (the new period starts at the end of the current one).

Administrators see the plan and today's usage of every account in **Users**, and can change an account's plan, give it a custom daily limit or an end date by hand (e.g. after a bank transfer).
