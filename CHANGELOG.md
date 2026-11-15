# Changelog

All notable changes to Tocsin are documented here. The format follows [Keep a Changelog](https://keepachangelog.com) and the project uses [Semantic Versioning](https://semver.org).

## [0.1.0] - 2026-11-14

First public release, published by [Telkine](https://github.com/TelKine-Studio).

### Server
- Topics (public / private), members with read or read & publish access, realtime history over WebSocket (Laravel Reverb).
- Publishing API and **ntfy-compatible API** (headers, JSON, polling, attachments, delays, `?auth=`).
- Rich messages: priorities, tags, links, action buttons, image and file attachments, scheduled delivery, delivery tracking.
- Topic logos, shown in the apps and in notifications (`X-Icon` per message still supported).
- Alerting: grouping of repeated messages (`X-Collapse`, automatic for identical messages), acknowledgement button, escalation with reminders and emails, automatic acknowledgement of resolved alerts.
- **Push notifications**: Apple (APNs), Android (FCM), browsers (Web Push); confidential mode.
- **Push relay** (operator mode, `TOCSIN_RELAY_OPERATOR`) with free (1,000/day), Pro and Business plans, payments by CinetPay (mobile money) and Stripe.
- Incoming **webhooks**: Alertmanager, Grafana, GitHub, GitLab, Uptime Kuma, Slack- and Discord-compatible senders.
- Users added by email with an activation link, roles, optional open registration.
- Outgoing email through Resend (API key) or any SMTP server, configured from the interface.
- Two-factor authentication, single sign-on (OpenID Connect), scoped and expiring access tokens, audit log.
- Configuration entirely from the web interface; first-run setup in the browser.
- Single Docker image (nginx, PHP-FPM, Reverb, queue, scheduler) ready for Coolify.
- English and French.

### Mobile app (Flutter)
- iOS and Android, multiple servers, public and private topics, two-factor login.
- Notification Service Extension on iOS: images, action buttons, confidential mode.
- Realtime history, priorities, tags, attachments, action buttons, mute per topic.
