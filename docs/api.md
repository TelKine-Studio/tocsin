# API reference

Base URL: `https://<your-server>/api/v1`. All responses are JSON.

## Authentication

Endpoints that read or publish accept, in order of preference:

| Method | Header |
| --- | --- |
| Access token | `Authorization: Bearer <token>` |
| Access token as Basic password | `curl -u :<token>` |
| Email and password (Basic) | `curl -u email@example.com:password` — not for accounts with two-factor authentication (use a token); 5 wrong passwords per minute block the account for a minute |

Tokens are created in **Profile → Access tokens** or with `POST /auth/token`. Requests without credentials are treated as anonymous: they can read **public** topics and publish to public topics that allow anonymous publishing.

Invalid credentials return `401`. A private topic the user cannot read answers `404`, like a missing topic; a topic they can read but not publish to answers `403`.

## Instance

### `GET /info`

```json
{
  "name": "Tocsin",
  "version": "0.1.0",
  "registration_enabled": false,
  "push": { "apns": true, "mode": "relay", "bundle_id": "ci.telkine.tocsin" },
  "realtime": { "protocol": "pusher", "key": "…", "host": "notify.example.com", "port": 443, "scheme": "https", "path": "" }
}
```

## Accounts

### `POST /auth/token`

```json
{ "email": "jane@example.com", "password": "…", "device_name": "Pixel 9" }
```

`201` → `{ "token": "1|abc…", "user": { "id": 1, "name": "Jane", "email": "jane@example.com", "role": "user" } }`

### `DELETE /auth/token`

Revokes the token used by the request. `204`.

### `GET /me`

The authenticated user.

## Topics

### `GET /topics`

Topics the caller can subscribe to.

### `GET /topics/{topic}`

```json
{
  "data": {
    "name": "backups",
    "display_name": "Server backups",
    "description": null,
    "visibility": "private",
    "anonymous_publish": false,
    "channel": "private-topic.backups",
    "permissions": { "read": true, "write": true, "manage": false },
    "last_message_at": "2026-11-14T10:39:48+00:00"
  }
}
```

## Messages

### `GET /topics/{topic}/messages`

| Query | Description |
| --- | --- |
| `since` | Only messages with an id greater than this one. |
| `limit` | 1–200, default 50. |

Messages are returned newest first:

```json
{
  "data": [
    {
      "id": 42,
      "topic": "backups",
      "title": "Backup done",
      "message": "Nightly backup completed in 42s",
      "priority": 4,
      "tags": ["backup", "prod"],
      "click": null,
      "actions": [{ "action": "view", "label": "Open", "url": "https://…", "clear": false }],
      "icon": null,
      "attachment": { "name": "graph.png", "url": "https://…/attachments/42/graph.png?signature=…", "type": "image/png", "size": 48213 },
      "delivery": { "status": "sent", "devices": 3, "failed": 0 },
      "occurrences": 1,
      "last_seen": 1794652788,
      "ack": null,
      "time": 1794652788,
      "created_at": "2026-11-14T10:39:48+00:00"
    }
  ]
}
```

### `POST /topics/{topic}/messages`

Publish a message. The body can be:

- **plain text** — the message itself, with optional headers `X-Title`, `X-Priority`, `X-Tags` (comma separated) and `X-Click` (URL opened when tapping the notification);
- **JSON or form data** — `message` (or `body`), `title`, `priority`, `tags` (array or comma separated string), `click`, `collapse` (grouping key, header `X-Collapse`), `ack` (`true` / `false`, header `X-Ack`), `ack_group` (secret key of 16 to 200 characters shared by the messages of one event published in several topics, header `X-Ack-Group`: acknowledging one acknowledges them all).

When the message is grouped with a recent one, the existing message is returned with its `occurrences` counter. Messages that must be acknowledged carry an `ack` object — `acknowledged`, `by`, `label`, `at`, `url` (signed, while not acknowledged), `reminders`, `next_reminder` — and an `Acknowledge` action (`"ack": true`) first in `actions`. See [alerting](alerting.md).

Actions, attachments, icons and delays are available through the [ntfy-compatible API](ntfy.md). Delivery `status`: `pending`, `sent`, `partial`, `failed`, `quota` (relay quota reached), `no_devices`, `disabled` (push not configured).

Priority is `1`–`5` or one of `min`, `low`, `default`, `high`, `urgent` (`max`).

Returns `201` with the created message. Validation errors return `422`.

Rate limit: 60 messages per minute per user (or IP for anonymous requests).

## Push notifications (devices)

### `PUT /devices`

Registers the APNs token of a device and the **complete** list of topics it wants notifications for. The call is idempotent: send it whenever the token or the subscriptions change. When authenticated, the device belongs to the user, which is required for private topics. Access is checked again at send time, so revoked members stop receiving notifications immediately.

```json
{
  "token": "a1b2…(APNs hex token or FCM registration token)",
  "platform": "ios",
  "environment": "production",
  "name": "iPhone de Jane",
  "app_version": "0.1.0",
  "topics": ["backups", "alerts"]
}
```

```json
{
  "data": {
    "push_enabled": true,
    "topics": ["backups"],
    "rejected": [{ "topic": "alerts", "reason": "unauthenticated" }]
  }
}
```

`platform` is `ios` or `android`. `environment` is `sandbox` for iOS development builds and `production` otherwise. Rejection reasons: `not_found`, `unauthenticated`, `forbidden`.

### `DELETE /devices`

`{ "token": "…" }` — forgets the device. `204`.

### Notification payload

```json
{
  "aps": {
    "alert": { "title": "Backup done", "subtitle": "Server backups", "body": "Nightly backup completed in 42s" },
    "sound": "default",
    "thread-id": "backups",
    "interruption-level": "active"
  },
  "tocsin": { "server": "https://notify.example.com", "topic": "backups", "id": 42, "priority": 4, "click": "https://…" }
}
```

Priority 5 uses the `time-sensitive` interruption level, priorities 1–2 are `passive` and delivered silently.

`tocsin.icon` is the logo of the message (`X-Icon`) or of its topic; topics expose it as `icon` (`GET /topics/{topic}`), served publicly at `/v1/topics/{topic}/icon`.

Reminders and acknowledgements reuse the `apns-collapse-id` of the message, so they replace its notification. `tocsin.ack` is `1` while the message waits for an acknowledgement. The "Acknowledged by …" notice carries `tocsin.notice: "acknowledged"` (and reminders `"reminder"`): its text is about the message, not the message itself, and tapping it opens message `tocsin.id`. It is not sent to whoever acknowledged.

## Acknowledgement

### `POST /v1/messages/{id}/ack?signature=…`

The signed URL of the `Acknowledge` action (outside `/api/v1`). Optional `Authorization` header to record the user, or `X-Name` for a display name. `X-Device-Token` (the push token of the calling device) keeps that device from receiving the notice. Messages of the same `ack_group` are acknowledged too. Returns `{"acknowledged": true, "already": false, "by": "Awa", "at": "…"}`.

## Realtime

Clients subscribe over WebSocket using the [Pusher Channels protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/) at
`wss://<host>/app/<key>` (values from `GET /info`).

- Public topics: channel `topic.<name>`, no authorization.
- Private topics: channel `private-topic.<name>`, authorized by `POST /broadcasting/auth` with the user's Bearer token (`socket_id`, `channel_name`).

Each message is broadcast as an event named `message` whose data is the message object above.

## Relay

Servers acting as push relay expose `/api/relay/v1`. See [relay.md](relay.md).
