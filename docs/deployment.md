# Deployment

Tocsin ships as a **single Docker image** containing everything it needs:

```
            ┌──────────────────── container (port 80) ────────────────────┐
 HTTPS ───► │ nginx ─┬─ /app/*  ──► Laravel Reverb (WebSocket, :8080)       │
 (proxy)    │        └─ /*      ──► PHP-FPM (web UI + REST API)             │
            │ queue worker · scheduler (supervisord)                       │
            │ /data  ── SQLite database, app key, logs, sessions           │
            └──────────────────────────────────────────────────────────────┘
```

All persistent data lives in **`/data`**: mount a volume there and back it up.

## Coolify

### Option A — Docker Compose (recommended)

1. **+ New → Resource → Public / Private repository** and select this repository.
2. Build pack: **Docker Compose**, file: `/docker-compose.yml`.
3. In the `tocsin` service, set the **domain** (e.g. `https://notify.example.com`). Coolify routes it to port 80 of the container and issues the TLS certificate.
4. **Deploy.**

The named volume `tocsin-data` is created automatically by Coolify and kept between deployments.

### Option B — Dockerfile

1. Build pack: **Dockerfile**, base directory: `/server`.
2. **Ports exposes**: `80`.
3. **Persistent storage**: add a volume mounted on `/data`.
4. Set your domain and deploy.

### First launch

Open your domain: Tocsin redirects to the **setup page** where you choose the instance name, its public URL and create the administrator account. Everything else (push notifications, mail server, registration, retention…) is configured afterwards in **Administration → Settings**.

### Push notifications

By default, notifications go through the **Telkine relay** to the official Tocsin app: open **Administration → Settings → Push notifications** and click **Connect** (free up to 1,000 notifications a day — see [relay.md](relay.md)).

To deliver with **your own Apple Push key** instead (your own build of the app, no limit), select that mode. Notifications are then sent directly to Apple Push Notification service over HTTP/2, using token-based authentication:

1. In your [Apple Developer account](https://developer.apple.com/account/resources/authkeys/list), create a key with **Apple Push Notifications service (APNs)** enabled and download the `.p8` file.
2. In Tocsin, open **Administration → Settings → Push notifications** and fill in the **Team ID**, **Key ID**, the **bundle ID** of the app you build (default `ci.telkine.tocsin`) and upload the `.p8` key. It is stored encrypted with the application key.
3. Sign in to the iPhone app, then click **Send a test to my devices**.

The container only needs outbound HTTPS access to the relay, or to `api.push.apple.com` (and `api.sandbox.push.apple.com` for development builds) in direct mode. Each device tells the server which APNs environment it belongs to.

> WebSockets go through the same domain and port as the website (`wss://notify.example.com/app/...`), so no extra port or proxy rule is needed in Coolify.

## Plain Docker

```bash
docker run -d --name tocsin \
  -p 8000:80 \
  -v tocsin-data:/data \
  --restart unless-stopped \
  ghcr.io/telkine-studio/tocsin:latest
```

Put it behind a reverse proxy that terminates TLS (Caddy, Traefik, nginx…) and forwards WebSocket upgrades. Example with Caddy:

```
notify.example.com {
    reverse_proxy 127.0.0.1:8000
}
```

## Security

**Administration → Settings → Security**:

- **Two-factor authentication** — every user can enable it from their profile (TOTP + 8 recovery codes); you can make it mandatory for administrators.
- **Single sign-on** — any OpenID Connect provider (Google Workspace, Microsoft Entra ID, Keycloak, Authentik, Okta…): issuer URL, client ID and secret; optionally create accounts automatically for some email domains.

**Administration → Audit log** lists logins (and failures), permission changes, tokens, topics, webhooks and settings changes, with IP addresses, for one year.

## Android and browsers

- **Android**: in direct mode, upload the Firebase **service account** JSON of your Android build in Settings → Push notifications. With the relay, nothing to do.
- **Browsers**: users click **Notify this browser** on a topic page. VAPID keys are generated automatically; no account is needed.

## Optional environment variables

None are required. They exist for advanced setups only:

| Variable | Default | Description |
| --- | --- | --- |
| `APP_KEY` | generated in `/data/app.key` | Encryption key. Set it only if you manage secrets externally. |
| `DB_CONNECTION` | `sqlite` | `pgsql` or `mysql` to use an external database (then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). |
| `TOCSIN_WS_HOST` / `TOCSIN_WS_PORT` / `TOCSIN_WS_SCHEME` | derived from the public URL | Public WebSocket address given to clients, if the realtime server is exposed elsewhere. |
| `TOCSIN_RELAY_URL` | `https://push.telkine.net` | Default relay proposed in the interface (it can also be changed there). |
| `TOCSIN_RELAY_OPERATOR` | `false` | Only for an instance that sells the push relay to others (Telkine): shows the relay settings, plans, payments and client instances, and exposes the relay API. |
| `LOG_LEVEL` | `warning` | `debug`, `info`, `warning`, `error`. |

## Updating

Redeploy (Coolify) or pull the new image and recreate the container. Database migrations run automatically at startup.

## Backups

Back up the `/data` volume. With SQLite, the database is `/data/database.sqlite` (WAL mode: copy `database.sqlite*` together, or use `sqlite3 /data/database.sqlite ".backup backup.sqlite"`). Keep `/data/app.key`: without it, stored secrets (SMTP password, Resend key, push keys) and sessions cannot be decrypted.

## Troubleshooting

- **The mobile app shows "Offline"**: check that your proxy forwards WebSocket upgrades on `/app/` and that the public URL in **Settings → General** is correct (it is used to tell clients where to connect). The **Settings → Realtime** page shows the endpoint and tests the connection from your browser.
- **Invitation emails are not sent**: configure Resend or SMTP in **Settings → Email** (with Resend, the sender address must be on a domain verified in Resend) and use **Send a test email**. Invitation links are always displayed after creation, so you can share them manually.
- **Logs**: `docker logs tocsin` (or the Coolify logs tab).
