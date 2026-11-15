# Push relay

Apple only accepts notifications for an app from the developer account that published it. The official Tocsin app is published by **Telkine**, so self-hosted instances deliver their notifications to it through the **Telkine push relay**:

```
your Tocsin instance ──HTTPS──► Telkine relay ──HTTP/2──► Apple Push Notification service ──► iPhone
```

Instances that build and sign their own version of the app can skip the relay entirely and use their own APNs key (**Settings → Push notifications → Your own Apple Push key**), with no limit.

## Plans

| Plan | Notifications per day, per instance | Price |
| --- | --- | --- |
| **Free** | 1,000 | free, no account, no card |
| **Pro** | 50,000 | paid, 30 days |
| **Business** | 500,000, with SLA | paid, 30 days |

A burst limit (600 notifications per minute by default) protects the service, whatever the plan. Counters reset every day at midnight UTC. When the quota is reached, notifications are still stored and visible in the app history; only the push alert is skipped until the next day.

## Connecting an instance

In **Administration → Settings → Push notifications**, keep **Telkine relay** selected and click **Connect**. The instance is registered on the free plan and receives its relay key automatically. The relay receives the instance name, its URL and the administrator's email address (to get in touch about usage); it never receives user accounts.

**Upgrading**: on the same page, choose a plan and pay by **mobile money or card** (CinetPay — Orange Money, MTN, Moov, Wave…) or **international card** (Stripe). The plan is activated as soon as the payment is confirmed, for 30 days, and renewed by paying again before it expires (the remaining days are kept). Keys provided by Telkine for custom contracts can be entered with **I already have a relay key**.

## Privacy

The relay forwards each notification to Apple / Google and keeps only daily counters per instance; message content is never stored. With **confidential mode** (Settings → Push notifications), notifications only carry the topic and a message identifier: the app's notification extension downloads the content directly from your server, so neither the relay nor Apple or Google ever see it.

## Operating a relay

Any Tocsin server can act as a relay (this is what Telkine runs):

1. Configure the server's **own Apple Push key** (the key of the developer account that publishes the app).
2. Start the instance with `TOCSIN_RELAY_OPERATOR=true`: the relay screens (plans, payments, client instances) and the relay API only exist in this mode, so self-hosted instances never see them. Then, in **Settings → Relay**, enable **Act as a push relay**, choose whether instances can register by themselves, and set the free plan limit and the burst limit.
3. Set the price and the daily limit of the **Pro** and **Business** plans, and the payment providers: **CinetPay** (site ID + API key; notification URL shown in the page) and/or **Stripe** (secret key + webhook secret; webhook on `checkout.session.completed`).
4. Manage instances in **Administration → Relay**: change their plan and daily limit, disable them or rotate their key. Expired plans go back to the free plan automatically.

### API

| Endpoint | Auth | Description |
| --- | --- | --- |
| `POST /api/relay/v1/instances` | — | Register an instance (`name`, `url`, `email`). Returns its key, plan and limit. Rate limited to 5 per hour per IP. |
| `GET /api/relay/v1/usage` | `Bearer <relay key>` | Plan, daily limit and usage of the day. |
| `POST /api/relay/v1/checkout` | `Bearer <relay key>` | Start the payment of a plan (`plan`, `provider`, `return_url`); returns the payment page URL. |
| `POST /api/relay/v1/push` | `Bearer <relay key>` | Up to 100 notifications: `ref`, `token`, `environment`, `payload` (`aps` + `tocsin` only), `headers` (`apns-priority`, `apns-expiration`, `apns-collapse-id`). Returns one result per `ref` with Apple's status and reason. |

Errors: `401 invalid_key`, `403 disabled`, `429 quota_exceeded` / `rate_limited`, `503 unavailable` (relay without APNs key).
