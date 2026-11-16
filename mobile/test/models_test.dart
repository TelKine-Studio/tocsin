import 'package:flutter_test/flutter_test.dart';
import 'package:tocsin/models/models.dart';

void main() {
  group('TopicMessage', () {
    test('parses an API payload', () {
      final message = TopicMessage.fromJson({
        'id': 12,
        'topic': 'backups',
        'title': 'Done',
        'message': 'Backup completed',
        'priority': 4,
        'tags': ['backup', 'prod'],
        'click': 'https://example.com',
        'time': 1700000000,
      });

      expect(message.id, 12);
      expect(message.title, 'Done');
      expect(message.priority, 4);
      expect(message.tags, ['backup', 'prod']);
      expect(
        message.time,
        DateTime.fromMillisecondsSinceEpoch(1700000000 * 1000),
      );
    });

    test('survives a JSON round trip', () {
      final message = TopicMessage.fromJson({
        'id': 1,
        'topic': 't',
        'message': 'hi',
        'time': 1700000000,
      });
      final copy = TopicMessage.fromJson(message.toJson());

      expect(copy.id, message.id);
      expect(copy.message, 'hi');
      expect(copy.priority, 3);
      expect(copy.tags, isEmpty);
    });
  });

  group('Subscription', () {
    Subscription subscription() => Subscription(
      serverUrl: 'https://notify.example.com',
      topic: 'backups',
      displayName: 'Backups',
      isPublic: false,
      channel: 'private-topic.backups',
    );

    test('absorbs newer messages only', () {
      final sub = subscription();
      final m1 = TopicMessage(
        id: 5,
        topic: 'backups',
        message: 'one',
        priority: 3,
        tags: const [],
        time: DateTime(2026),
      );
      final old = TopicMessage(
        id: 3,
        topic: 'backups',
        message: 'old',
        priority: 3,
        tags: const [],
        time: DateTime(2025),
      );

      sub.absorb(m1, countAsUnread: true);
      sub.absorb(old, countAsUnread: true);

      expect(sub.lastMessageId, 5);
      expect(sub.lastPreview, 'one');
      expect(sub.unread, 1);
    });

    test('serializes to JSON', () {
      final sub = subscription()..muted = true;
      final copy = Subscription.fromJson(sub.toJson());

      expect(copy.id, 'https://notify.example.com|backups');
      expect(copy.channel, 'private-topic.backups');
      expect(copy.muted, isTrue);
    });
  });

  test('TopicInfo reads visibility and permissions', () {
    final info = TopicInfo.fromJson({
      'name': 'alerts',
      'display_name': 'Alerts',
      'visibility': 'public',
      'channel': 'topic.alerts',
      'permissions': {'read': true, 'write': true},
    });

    expect(info.isPublic, isTrue);
    expect(info.canWrite, isTrue);
  });

  test('parses actions and attachments', () {
    final message = TopicMessage.fromJson({
      'id': 3,
      'topic': 't',
      'message': 'Graph',
      'time': 1700000000,
      'actions': [
        {'action': 'view', 'label': 'Open', 'url': 'https://example.com'},
        {
          'action': 'http',
          'label': 'Ack',
          'url': 'https://api.example.com/ack',
          'method': 'put',
          'headers': {'X-Key': 'a'},
        },
      ],
      'attachment': {
        'name': 'cpu.png',
        'url': 'https://n.test/attachments/3/cpu.png?signature=x',
        'type': 'image/png',
        'size': 1200,
      },
    });

    expect(message.actions, hasLength(2));
    expect(message.actions[1].isHttp, isTrue);
    expect(message.actions[1].method, 'PUT');
    expect(message.actions[1].headers, {'X-Key': 'a'});
    expect(message.attachment?.isImage, isTrue);

    final copy = TopicMessage.fromJson(message.toJson());
    expect(copy.actions.first.label, 'Open');
    expect(copy.attachment?.size, 1200);
  });

  test('parses grouping and acknowledgement', () {
    final message = TopicMessage.fromJson({
      'id': 9,
      'topic': 'alerts',
      'message': 'Server down',
      'priority': 5,
      'time': 1700000000,
      'occurrences': 4,
      'last_seen': 1700000300,
      'actions': [
        {
          'action': 'http',
          'label': 'Acknowledge',
          'url': 'https://n.test/v1/messages/9/ack?signature=x',
          'method': 'POST',
          'headers': [],
          'ack': true,
        },
        {'action': 'view', 'label': 'Grafana', 'url': 'https://grafana.test'},
      ],
      'ack': {
        'acknowledged': false,
        'url': 'https://n.test/v1/messages/9/ack?signature=x',
        'reminders': 2,
      },
    });

    expect(message.occurrences, 4);
    expect(message.awaitsAck, isTrue);
    expect(message.ack?.reminders, 2);
    expect(message.regularActions.map((a) => a.label), ['Grafana']);

    final copy = TopicMessage.fromJson(message.toJson());
    expect(copy.occurrences, 4);
    expect(copy.actions.first.ack, isTrue);
    expect(copy.ack?.url, message.ack?.url);

    final done = TopicMessage.fromJson({
      ...message.toJson(),
      'ack': {
        'acknowledged': true,
        'by': 'Awa',
        'label': 'Acknowledged by Awa',
        'at': 1700000400,
      },
    });
    expect(done.awaitsAck, isFalse);
    expect(done.ack?.label, 'Acknowledged by Awa');
  });

  test('builds the message carried by a tapped notification', () {
    final message = TopicMessage.fromNotification({
      'server': 'https://push.telkine.net',
      'topic': 'alerts',
      'id': '42',
      'priority': 5,
      'click': 'https://grafana.test',
      'time': 1700000000,
      'message': {'title': 'Disk', 'body': 'Almost full'},
      'actions': [
        {
          'action': 'http',
          'label': 'Acknowledge',
          'url': 'https://a.test',
          'ack': true,
        },
        {'action': 'view', 'label': 'Open', 'url': 'https://b.test'},
      ],
    });

    expect(message?.id, 42);
    expect(message?.title, 'Disk');
    expect(message?.message, 'Almost full');
    expect(message?.priority, 5);
    expect(message?.actions.map((a) => a.label), ['Open']);
    expect(message?.time, DateTime.fromMillisecondsSinceEpoch(1700000000000));

    // No title, and nothing to show without the message.
    expect(
      TopicMessage.fromNotification({
        'id': 1,
        'message': {'title': '', 'body': 'hi'},
      })?.title,
      isNull,
    );
    expect(TopicMessage.fromNotification({'id': 1}), isNull);
  });

  test('a notice about a message is not taken for the message', () {
    // "Acknowledged by Awa": opening it must not store this text.
    expect(
      TopicMessage.fromNotification({
        'topic': 'alerts',
        'id': 42,
        'notice': 'acknowledged',
        'message': {
          'title': 'Disk',
          'body': '✓ Acknowledged by Awa\nAlmost full',
        },
      }),
      isNull,
    );
  });
}
