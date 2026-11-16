# ntfy compatibility

Tocsin speaks the [ntfy](https://ntfy.sh) publishing API. Scripts written for ntfy keep working: only change the server URL.

```bash
# before
curl -d "Backup done" ntfy.sh/backups
# after
curl -u :$TOCSIN_TOKEN -d "Backup done" https://tocsin.example.com/backups
```

## Publishing

| Request | Description |
| --- | --- |
| `PUT` / `POST /<topic>` | The body is the message. Binary bodies, bodies larger than the maximum message length, or requests with a `Filename` header become **file attachments**. |
| `GET /<topic>/publish`, `/send`, `/trigger` | Publish with query parameters (`?message=…&title=…`), handy for webhooks that can only do GET. |
| `POST /` | JSON body: `topic`, `message`, `title`, `tags`, `priority`, `click`, `actions`, `icon`, `attach`, `filename`, `delay`. |

Supported headers (and their query-parameter equivalents):

| Header | Aliases | Example |
| --- | --- | --- |
| `X-Title` | `Title`, `t` | `Backup` |
| `X-Priority` | `Priority`, `prio`, `p` | `1`–`5`, `min`, `low`, `default`, `high`, `urgent`, `max` |
| `X-Tags` | `Tags`, `Tag`, `ta` | `warning,skull` |
| `X-Click` | `Click` | `https://example.com` |
| `X-Actions` | `Actions`, `Action` | `view, Open portal, https://example.com; http, Close door, https://api/door, method=PUT` |
| `X-Attach` | `Attach`, `a` | URL of an external attachment |
| `X-Filename` | `Filename`, `file`, `f` | name of an uploaded attachment |
| `X-Icon` | `Icon` | URL of an icon |
| `X-Delay` | `Delay`, `X-At`, `At`, `X-In`, `In` | `30m`, `2h`, `1d`, Unix timestamp or date (up to 30 days) |
| `X-Message` | `Message`, `m` | message when the body is a file |
| `X-Collapse` | `Collapse`, `X-Group`, `Group` | *Tocsin extension.* Grouping key: messages with the same key within the topic's window are counted on one message (see [alerting](alerting.md)) |
| `X-Ack` | `Ack` | *Tocsin extension.* `yes` / `no`: force or skip the acknowledgement |
| `X-Ack-Group` | `Ack-Group` | *Tocsin extension.* Secret key (16–200 characters) shared by the messages of one event published in several topics: acknowledging one acknowledges them all (see [alerting](alerting.md#one-event-in-several-topics-acknowledgement-groups)) |

Non-ASCII headers can be sent RFC 2047 encoded, as with ntfy.

## Authentication

- `curl -u :<token>` or `Authorization: Bearer <token>` — access token from your profile;
- `curl -u email:password`;
- `?auth=<base64 of the Authorization header>` for clients that cannot send headers.

When **Create topics on first publish** is enabled (default), publishing to an unknown topic as an authenticated user creates it as a private topic owned by that user. Guests can only publish to existing public topics that allow anonymous publishing.

## Reading

`GET /<topic>/json`, `/<topic>/sse` and `/<topic>/raw` return cached messages in ntfy's formats, with `since=<id|timestamp|duration|all|latest>`. They always answer in **poll** mode (`poll=1`): real-time delivery happens through push notifications in the Tocsin app and over WebSocket. `GET /<topic>/auth` checks read access and `GET /v1/health` reports the server health.

## Differences

- Topics are managed: they have an owner, members and a visibility (public / private).
- Identical messages received within the topic's grouping window (10 minutes by default) are counted on a single message and do not notify again; the JSON answer then carries the existing `id` and `occurrences`. Set the window to **Never** in the topic settings to keep ntfy's behaviour.
- Streaming subscriptions (long-lived `json`/`sse`/`ws` connections) are not supported; use the Tocsin app or poll.
- E-mail notifications (`X-Email`), phone calls (`X-Call`) and Markdown rendering are not supported yet.
