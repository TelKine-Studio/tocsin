/// Data models shared by the app, the background sync and the API client.
library;

/// Connection details of the realtime (Pusher protocol) server, as returned
/// by `GET /api/v1/info`.
class RealtimeConfig {
  const RealtimeConfig({
    required this.key,
    required this.host,
    required this.port,
    required this.scheme,
    this.path = '',
  });

  final String key;
  final String host;
  final int port;
  final String scheme;
  final String path;

  bool get isSecure => scheme == 'https';

  factory RealtimeConfig.fromJson(Map<String, dynamic> json) => RealtimeConfig(
    key: json['key'] as String? ?? '',
    host: json['host'] as String? ?? '',
    port: (json['port'] as num?)?.toInt() ?? 443,
    scheme: json['scheme'] as String? ?? 'https',
    path: json['path'] as String? ?? '',
  );

  Map<String, dynamic> toJson() => {
    'key': key,
    'host': host,
    'port': port,
    'scheme': scheme,
    'path': path,
  };
}

class ServerInfo {
  const ServerInfo({
    required this.name,
    required this.version,
    required this.realtime,
    required this.registrationEnabled,
    required this.pushEnabled,
  });

  final String name;
  final String version;
  final RealtimeConfig realtime;
  final bool registrationEnabled;

  /// Whether the server has Apple Push Notification service credentials.
  final bool pushEnabled;

  factory ServerInfo.fromJson(Map<String, dynamic> json) => ServerInfo(
    name: json['name'] as String? ?? 'Tocsin',
    version: json['version'] as String? ?? '',
    registrationEnabled: json['registration_enabled'] as bool? ?? false,
    pushEnabled: (json['push'] as Map?)?['apns'] as bool? ?? false,
    realtime: RealtimeConfig.fromJson(
      (json['realtime'] as Map?)?.cast<String, dynamic>() ?? const {},
    ),
  );
}

/// The account a user is signed in with on a server.
class Account {
  const Account({required this.id, required this.name, required this.email});

  final int id;
  final String name;
  final String email;

  factory Account.fromJson(Map<String, dynamic> json) => Account(
    id: (json['id'] as num).toInt(),
    name: json['name'] as String? ?? '',
    email: json['email'] as String? ?? '',
  );

  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'email': email};
}

/// A Tocsin server the app knows about.
class Server {
  Server({
    required this.url,
    required this.name,
    required this.realtime,
    this.account,
    this.pushEnabled = false,
  });

  /// Normalized base URL, without trailing slash (e.g. https://notify.example.com).
  final String url;
  String name;
  RealtimeConfig realtime;

  /// The signed-in account, if any. Its token lives in secure storage.
  Account? account;

  /// Whether the server can send push notifications (APNs configured).
  bool pushEnabled;

  String get host => Uri.tryParse(url)?.host ?? url;

  factory Server.fromJson(Map<String, dynamic> json) => Server(
    url: json['url'] as String,
    name: json['name'] as String? ?? '',
    realtime: RealtimeConfig.fromJson(
      (json['realtime'] as Map).cast<String, dynamic>(),
    ),
    account: json['account'] == null
        ? null
        : Account.fromJson((json['account'] as Map).cast<String, dynamic>()),
    pushEnabled: json['push_enabled'] as bool? ?? false,
  );

  Map<String, dynamic> toJson() => {
    'url': url,
    'name': name,
    'realtime': realtime.toJson(),
    'account': account?.toJson(),
    'push_enabled': pushEnabled,
  };
}

/// Description of a topic, as returned by `GET /api/v1/topics/{name}`.
class TopicInfo {
  const TopicInfo({
    required this.name,
    required this.displayName,
    required this.description,
    required this.isPublic,
    required this.channel,
    required this.canWrite,
    this.iconUrl,
  });

  final String name;
  final String displayName;
  final String? description;
  final bool isPublic;
  final String channel;
  final bool canWrite;

  /// Logo of the topic, if the server has one.
  final String? iconUrl;

  factory TopicInfo.fromJson(Map<String, dynamic> json) {
    final permissions =
        (json['permissions'] as Map?)?.cast<String, dynamic>() ?? const {};

    return TopicInfo(
      name: json['name'] as String,
      displayName: json['display_name'] as String? ?? json['name'] as String,
      description: json['description'] as String?,
      isPublic: json['visibility'] == 'public',
      channel: json['channel'] as String,
      canWrite: permissions['write'] as bool? ?? false,
      iconUrl: json['icon'] as String?,
    );
  }
}

/// A subscription of this device to a topic on a server.
class Subscription {
  Subscription({
    required this.serverUrl,
    required this.topic,
    required this.displayName,
    required this.isPublic,
    required this.channel,
    this.lastMessageId = 0,
    this.unread = 0,
    this.lastMessageAt,
    this.lastPreview,
    this.muted = false,
    this.pushActive = false,
    this.iconUrl,
  });

  final String serverUrl;
  final String topic;
  String displayName;
  bool isPublic;
  String channel;
  int lastMessageId;
  int unread;
  DateTime? lastMessageAt;
  String? lastPreview;
  bool muted;

  /// The server accepted this device for push notifications on this topic.
  bool pushActive;

  /// Logo of the topic, if any.
  String? iconUrl;

  String get id => '$serverUrl|$topic';

  /// Stable positive id used for grouping notifications.
  int get notificationGroupId => id.hashCode & 0x7fffffff;

  factory Subscription.fromJson(Map<String, dynamic> json) => Subscription(
    serverUrl: json['server_url'] as String,
    topic: json['topic'] as String,
    displayName: json['display_name'] as String? ?? json['topic'] as String,
    isPublic: json['is_public'] as bool? ?? false,
    channel: json['channel'] as String,
    lastMessageId: (json['last_message_id'] as num?)?.toInt() ?? 0,
    unread: (json['unread'] as num?)?.toInt() ?? 0,
    lastMessageAt: json['last_message_at'] == null
        ? null
        : DateTime.tryParse(json['last_message_at'] as String),
    lastPreview: json['last_preview'] as String?,
    muted: json['muted'] as bool? ?? false,
    pushActive: json['push_active'] as bool? ?? false,
    iconUrl: json['icon_url'] as String?,
  );

  Map<String, dynamic> toJson() => {
    'server_url': serverUrl,
    'topic': topic,
    'display_name': displayName,
    'is_public': isPublic,
    'channel': channel,
    'last_message_id': lastMessageId,
    'unread': unread,
    'last_message_at': lastMessageAt?.toIso8601String(),
    'last_preview': lastPreview,
    'muted': muted,
    'push_active': pushActive,
    'icon_url': iconUrl,
  };

  /// Updates the summary fields after receiving [message].
  void absorb(TopicMessage message, {required bool countAsUnread}) {
    if (message.id <= lastMessageId) {
      return;
    }
    lastMessageId = message.id;
    lastMessageAt = message.time;
    lastPreview = message.title?.isNotEmpty == true
        ? message.title
        : message.message;
    if (countAsUnread) {
      unread++;
    }
  }
}

/// Button attached to a message ("view" opens a URL, "http" sends a request).
class MessageAction {
  const MessageAction({
    required this.action,
    required this.label,
    required this.url,
    this.method = 'POST',
    this.headers = const {},
    this.body,
    this.ack = false,
  });

  final String action;
  final String label;
  final String url;
  final String method;
  final Map<String, String> headers;
  final String? body;

  /// The "Acknowledge" button added by the server.
  final bool ack;

  bool get isHttp => action == 'http';

  factory MessageAction.fromJson(Map<String, dynamic> json) => MessageAction(
    action: json['action'] as String? ?? 'view',
    label: json['label'] as String? ?? '',
    url: json['url'] as String? ?? '',
    method: (json['method'] as String? ?? 'POST').toUpperCase(),
    // PHP encodes empty header lists as [] instead of {}.
    headers: json['headers'] is Map
        ? (json['headers'] as Map).map(
            (k, v) => MapEntry(k.toString(), v.toString()),
          )
        : const {},
    body: json['body'] as String?,
    ack: json['ack'] == true,
  );

  Map<String, dynamic> toJson() => {
    'action': action,
    'label': label,
    'url': url,
    'method': method,
    'headers': headers,
    'body': body,
    if (ack) 'ack': true,
  };
}

/// Acknowledgement state of a message that must be acknowledged.
class MessageAck {
  const MessageAck({
    required this.acknowledged,
    this.by,
    this.resolved = false,
    this.label,
    this.url,
    this.at,
    this.reminders = 0,
  });

  final bool acknowledged;
  final String? by;

  /// Closed automatically by the "resolved" notification of the alert.
  final bool resolved;

  /// "Acknowledged by Awa", "Resolved automatically" (translated by the server).
  final String? label;

  /// Signed URL to acknowledge, while it is not acknowledged.
  final String? url;
  final DateTime? at;
  final int reminders;

  factory MessageAck.fromJson(Map<String, dynamic> json) => MessageAck(
    acknowledged: json['acknowledged'] == true,
    by: json['by'] as String?,
    resolved: json['resolved'] == true,
    label: json['label'] as String?,
    url: json['url'] as String?,
    at: json['at'] is num
        ? DateTime.fromMillisecondsSinceEpoch(
            (json['at'] as num).toInt() * 1000,
          )
        : null,
    reminders: (json['reminders'] as num?)?.toInt() ?? 0,
  );

  Map<String, dynamic> toJson() => {
    'acknowledged': acknowledged,
    'by': by,
    'resolved': resolved,
    'label': label,
    'url': url,
    'at': at == null ? null : at!.millisecondsSinceEpoch ~/ 1000,
    'reminders': reminders,
  };
}

class MessageAttachment {
  const MessageAttachment({required this.url, this.name, this.type, this.size});

  final String url;
  final String? name;
  final String? type;
  final int? size;

  bool get isImage =>
      (type ?? '').startsWith('image/') ||
      RegExp(
        r'\.(png|jpe?g|gif|webp|heic)$',
        caseSensitive: false,
      ).hasMatch(name ?? url);

  factory MessageAttachment.fromJson(Map<String, dynamic> json) =>
      MessageAttachment(
        url: json['url'] as String,
        name: json['name'] as String?,
        type: json['type'] as String?,
        size: (json['size'] as num?)?.toInt(),
      );

  Map<String, dynamic> toJson() => {
    'url': url,
    'name': name,
    'type': type,
    'size': size,
  };
}

/// A message published to a topic.
class TopicMessage {
  const TopicMessage({
    required this.id,
    required this.topic,
    required this.message,
    required this.priority,
    required this.tags,
    required this.time,
    this.title,
    this.click,
    this.actions = const [],
    this.attachment,
    this.occurrences = 1,
    this.lastSeen,
    this.ack,
    this.icon,
  });

  final int id;
  final String topic;
  final String? title;
  final String message;
  final int priority;
  final List<String> tags;
  final String? click;
  final DateTime time;
  final List<MessageAction> actions;
  final MessageAttachment? attachment;

  /// Number of times this (grouped) message was received.
  final int occurrences;
  final DateTime? lastSeen;
  final MessageAck? ack;

  /// Logo of the message (X-Icon) or of its topic.
  final String? icon;

  bool get awaitsAck => ack != null && !ack!.acknowledged;

  /// Buttons to show, without the "Acknowledge" one (displayed apart).
  List<MessageAction> get regularActions =>
      actions.where((a) => !a.ack).toList();

  factory TopicMessage.fromJson(Map<String, dynamic> json) => TopicMessage(
    id: (json['id'] as num).toInt(),
    topic: json['topic'] as String? ?? '',
    title: json['title'] as String?,
    message: json['message'] as String? ?? '',
    priority: (json['priority'] as num?)?.toInt() ?? 3,
    tags: ((json['tags'] as List?) ?? const [])
        .map((e) => e.toString())
        .toList(),
    click: json['click'] as String?,
    actions: ((json['actions'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => MessageAction.fromJson(e.cast<String, dynamic>()))
        .toList(),
    attachment: json['attachment'] is Map
        ? MessageAttachment.fromJson(
            (json['attachment'] as Map).cast<String, dynamic>(),
          )
        : null,
    occurrences: (json['occurrences'] as num?)?.toInt() ?? 1,
    icon: json['icon'] as String?,
    lastSeen: json['last_seen'] is num
        ? DateTime.fromMillisecondsSinceEpoch(
            (json['last_seen'] as num).toInt() * 1000,
          )
        : null,
    ack: json['ack'] is Map
        ? MessageAck.fromJson((json['ack'] as Map).cast<String, dynamic>())
        : null,
    time: json['time'] is num
        ? DateTime.fromMillisecondsSinceEpoch(
            (json['time'] as num).toInt() * 1000,
          )
        : DateTime.tryParse(json['created_at'] as String? ?? '') ??
              DateTime.now(),
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'topic': topic,
    'title': title,
    'message': message,
    'priority': priority,
    'tags': tags,
    'click': click,
    'actions': actions.map((a) => a.toJson()).toList(),
    'attachment': attachment?.toJson(),
    'occurrences': occurrences,
    'icon': icon,
    'last_seen': lastSeen == null
        ? null
        : lastSeen!.millisecondsSinceEpoch ~/ 1000,
    'ack': ack?.toJson(),
    'time': time.millisecondsSinceEpoch ~/ 1000,
  };
}
