# Grouping, acknowledgement and escalation

Tocsin is made for alerts: a disk that stays full should not ring forty times, and an urgent alert should not go unnoticed. Both behaviours are set per topic in **Topic → Settings → Alerts**.

## Grouping repeated messages

When a message arrives that is **identical** (same title, text, priority and tags) to a message received within the topic's grouping window, Tocsin does not create a new message. It counts it on the existing one instead:

- the message shows a counter (`×12`) in the web interface and in the app, updated in real time;
- **devices are not notified again**, so repeated alerts do not use up the relay quota;
- if the new occurrence has a **higher priority**, the notification is sent again (it replaces the previous one on the phone);
- the window is sliding: it starts again with each occurrence.

To group messages whose text changes ("CPU at 91%", then "CPU at 93%"), give them the same **collapse key**. The latest text replaces the previous one:

```bash
curl -H "X-Collapse: cpu-web1" -H "X-Priority: high" -d "CPU at 93%" https://notify.example.com/alerts
```

`X-Collapse` (alias `Collapse`, `X-Group`) is also available as `collapse` in JSON and in query parameters.

The window can be set to never, 5, 10 (default), 30 minutes, 1, 4 or 24 hours. With **Never**, every message is kept and notified, even with a collapse key. Messages with an attachment or a delay are never grouped automatically.

Alertmanager / Grafana and Uptime Kuma webhooks set a collapse key automatically (the alert group, or the monitor), so a still-firing alert sent every few minutes stays a single message.

## Acknowledgement

With **Require an acknowledgement**, messages from a given priority (urgent only, high and urgent, default and above, or all) get an **Acknowledge** button:

- in the iOS and Android notification, in the app and in the web interface;
- pressing it records who acknowledged (the signed-in account, or the device name for anonymous subscribers of a public topic);
- the notification is then **replaced on every device** by "✓ Acknowledged by Awa", silently, and reminders stop.

A publisher can force or skip it for one message with `X-Ack: yes` or `X-Ack: no` (`ack` in JSON).

When a monitoring tool sends the **resolved** notification of an alert (Alertmanager, Grafana, Uptime Kuma back up), the firing alert is acknowledged automatically ("Resolved automatically").

The button calls a signed URL (`POST /v1/messages/{id}/ack?signature=…`) carried by the notification. An `Authorization` header (token or password) identifies the user; otherwise `X-Name` gives a display name.

## Escalation

When an acknowledgement is required, you can add up to 5 **reminders**, each after a number of minutes since the message was published:

1. After 5 minutes without acknowledgement: every subscriber is notified again ("Reminder: …").
2. After 15 minutes: reminder again, and an email to the people you chose (e.g. the on-call manager).

People emailed by a step must be able to read the topic: administrators can choose any user, topic owners choose among the topic members. Email requires a configured mail server (**Settings → Email**: Resend or SMTP).

Reminders run every minute from the scheduler included in the Docker image (`php artisan tocsin:escalate`).
