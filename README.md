<p align="center">
  <img src="docs/assets/logo.svg" width="72" alt="Tocsin logo">
</p>

<h1 align="center">Tocsin</h1>

<p align="center">
  <strong>Self-hosted push notifications. Publish with a simple HTTP request, get notified on your phone.</strong>
</p>

<p align="center">
  Built and maintained by <a href="https://github.com/TelKine-Studio"><strong>Telkine</strong></a>
</p>

<p align="center">
  <a href="../../actions/workflows/server.yml"><img src="../../actions/workflows/server.yml/badge.svg" alt="Server CI"></a>
  <a href="../../actions/workflows/mobile.yml"><img src="../../actions/workflows/mobile.yml/badge.svg" alt="Mobile CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="MIT License"></a>
</p>

---

Tocsin (*an alarm bell*) is a lightweight, self-hosted alternative to services like ntfy or Pushover.
Create **topics** on your server, publish messages to them from scripts, CI pipelines or monitoring tools,
and receive them as **real push notifications on your iPhone** — even when the app is closed.

```bash
curl -u :$TOCSIN_TOKEN -H "X-Title: Backup" -d "Nightly backup completed" https://notify.example.com/api/v1/topics/backups/messages
```

## Features

**Notifications**
- **Real push notifications** on iPhone (APNs) and Android (Firebase), even when the app is closed, plus **browser notifications** (Web Push) from the web interface.
- **Free and unlimited when self-hosted** — every feature is in this repository, with no paid edition. The only paid service is the optional Telkine relay below, for those who do not want to manage Apple and Firebase keys.
- **Telkine push relay** — connect in one click, free up to 1,000 notifications a day per instance (4× ntfy's free tier); Pro and Business plans paid by mobile money or card. Or use **your own keys** for unlimited delivery.
- **Confidential mode** — notifications only carry an identifier; the phone downloads the content from your server. Apple, Google and the relay never see it.
- **Rich messages** — priorities (1–5, time-sensitive on iOS), tags, links, **action buttons** (open a URL or send an HTTP request), **images and file attachments**, **scheduled delivery**.
- **Delivery tracking** — each message shows on how many devices it was delivered, or why it was not.
- **Topic logos** — each topic can have its own logo, shown in the apps and in notifications (large icon on Android and in browsers, sender photo on iOS). A message can also bring its own with `X-Icon`.
- **Made for alerts** — repeated alerts are **grouped** (`×12`, no new notification), urgent ones get an **Acknowledge** button, and unacknowledged ones are **escalated**: reminders to every subscriber, then email to the on-call people. A resolved alert closes itself. See [docs/alerting.md](docs/alerting.md).

**Publishing**
- **ntfy-compatible API** — `curl -d "…" tocsin.example.com/<topic>` just works: same headers, actions, attachments, delays, JSON publishing and polling. Switch from ntfy by changing the URL ([docs/ntfy.md](docs/ntfy.md)).
- **Webhooks** for Grafana, Prometheus Alertmanager, GitHub, GitLab, Uptime Kuma and any tool that can notify Slack or Discord (Coolify, …), with automatic formatting ([docs/integrations.md](docs/integrations.md)).
- **Access tokens** limited to some topics, with an expiration date.

**Administration**
- **Topics** public or private, members with read or read & publish access, realtime history.
- **Users** added by email: they receive a link to choose their password. Roles, deactivation, optional open registration.
- **Email** through [Resend](https://resend.com) or any SMTP server, set up from the interface with a test button.
- **Security** — two-factor authentication (TOTP + recovery codes, can be required for admins), **single sign-on** (OpenID Connect: Google Workspace, Microsoft Entra ID, Keycloak, Authentik…), **audit log**.
- **Zero configuration files** — everything is configured from the web interface; the first admin is created in the browser on first launch.
- **One container** — web UI, API, WebSocket server, queue and scheduler in a single Docker image with a single volume. Ready for [Coolify](https://coolify.io).
- **English & French**, light & dark themes, responsive.

## Repository layout

| Path | Description |
| --- | --- |
| [`server/`](server) | Laravel 13 application: web interface, REST API, realtime server. |
| [`mobile/`](mobile) | Flutter application for iOS and Android. |
| [`docs/`](docs) | Deployment guide and API reference. |

## Quick start

### With Coolify (recommended)

1. In Coolify, create a new resource → **Docker Compose** (or **Dockerfile** with base directory `/server`) from this repository.
2. Assign a domain to the `tocsin` service, e.g. `https://notify.example.com` (container port **80**).
3. Deploy, then open the domain: you are redirected to the setup page to create the administrator account.

No environment variable is required. See the [deployment guide](docs/deployment.md) for details.

### Enable push notifications

Open **Administration → Settings → Push notifications** and pick one mode:

| Mode | App | Limit | Setup |
| --- | --- | --- | --- |
| **Telkine relay** (default) | official Tocsin app (App Store) | 1,000 / day free, more with Pro & Business | one click on **Connect** |
| **Your own APNs key** | your own build of the app | unlimited | Team ID, Key ID, bundle ID and `.p8` key |

Then click **Send a test to my devices**. See [docs/relay.md](docs/relay.md) for how the relay works and its plans.

### With Docker

```bash
docker run -d --name tocsin -p 8000:80 -v tocsin-data:/data ghcr.io/telkine-studio/tocsin:latest
```

Open <http://localhost:8000> and follow the setup.

## Publishing messages

Create an access token in **Profile → Access tokens**, then:

```bash
# Plain text body, ntfy-style headers
curl -u :<token> \
  -H "X-Title: Disk almost full" \
  -H "X-Priority: high" \
  -H "X-Tags: disk,prod" \
  -d "/var is 92% full on web-1" \
  https://notify.example.com/api/v1/topics/alerts/messages

# JSON
curl -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"title": "Deploy", "message": "v1.4.2 is live", "priority": 3, "tags": ["deploy"]}' \
  https://notify.example.com/api/v1/topics/deploys/messages
```

Priorities go from `1` (min) to `5` (urgent). The full reference is in [docs/api.md](docs/api.md).

## Mobile app

1. Install the official app (App Store / Play Store, published by Telkine) or build your own from [`mobile/`](mobile).
2. Tap **Subscribe**, enter your server URL and a topic name, and allow notifications.
3. For private topics, the app asks for your credentials (and your two-factor code) and stores a revocable token in the Keychain / Keystore.

The app registers its device token with the server together with the list of topics. Every published message is then pushed by Apple or Google, whether the app is open, in the background or closed.

```
script ──POST──► your Tocsin server ──► (Telkine relay) ──HTTP/2──► APNs ──► iPhone
```

## Development

```bash
# Server (PHP 8.4+, Node 22+)
cd server
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
composer run dev           # web server, Vite, queue
php artisan reverb:start   # realtime server, in another terminal
php artisan test

# Mobile (Flutter stable, Dart 3.13+)
cd mobile
flutter pub get
flutter test
flutter run
```

## Roadmap

- Streaming subscriptions for the ntfy CLI (`json`/`sse` long-lived connections).
- E-mail and phone-call forwarding of urgent messages.
- Recurring billing (automatic renewal) for relay plans.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

Tocsin is open-source software by [Telkine](https://github.com/TelKine-Studio), licensed under the [MIT license](LICENSE).
