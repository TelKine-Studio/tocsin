# Integrations (webhooks)

Each topic can have **incoming webhooks**: secret URLs that other tools call to publish a message. Create them in the topic page → **Integration** → **Webhooks**, then paste the URL in the tool's webhook / notification settings.

```
https://tocsin.example.com/hooks/<secret>
```

Keep the URL secret: anyone who knows it can publish to the topic. Delete the webhook to revoke it.

## Supported sources

With **Automatic detection** (default), Tocsin recognizes the format from the request. You can also force a source.

| Source | How it is formatted |
| --- | --- |
| **Prometheus Alertmanager**, **Grafana** alerting | 🔥 / ✅ + alert name as title, one line per alert (`[instance] summary`), priority from the `severity` label (`critical` → urgent), link to Alertmanager / Grafana. Repeated notifications of a firing alert are grouped; the resolved notification acknowledges the firing alert. |
| **GitHub** (`X-GitHub-Event`) | push (commits), pull requests, issues, releases, workflow runs (failures in high priority), ping. |
| **GitLab** (`X-Gitlab-Event`) | push, merge requests, pipelines. |
| **Uptime Kuma** | 🔴 / ✅ + monitor name, down = urgent priority. "Up" acknowledges the "down" alert. |
| **Slack-compatible** | `text`, `blocks` and `attachments` of a Slack incoming webhook — works with every tool that can notify Slack, e.g. **Coolify**, Portainer, Sentry… |
| **Discord-compatible** | `content` and the first `embed`. |
| **Generic** | JSON with `message`/`body`/`text`, `title`, `priority`, `tags`, `url`; or a plain text body. |

## Examples

**Coolify**: Notifications → Slack → paste the webhook URL. Deployment results and server alerts arrive on your phone.

**Grafana**: Alerting → Contact points → *Webhook* → URL.

**Alertmanager**:

```yaml
receivers:
  - name: tocsin
    webhook_configs:
      - url: https://tocsin.example.com/hooks/<secret>
```

**GitHub**: repository → Settings → Webhooks → Payload URL, content type `application/json`.

For scripts, prefer the [publishing API](api.md) or the [ntfy-compatible API](ntfy.md) with an access token.
