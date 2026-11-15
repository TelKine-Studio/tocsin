import 'dart:async';

import 'package:flutter/foundation.dart';

import '../models/models.dart';
import '../services/api_client.dart';
import '../services/push_service.dart';
import '../services/action_runner.dart';
import '../services/realtime_service.dart';
import '../services/storage.dart';
import '../services/sync_service.dart';

/// Raised when subscribing to a private topic without being signed in.
class LoginRequiredException implements Exception {
  const LoginRequiredException(this.serverUrl);

  final String serverUrl;
}

enum SubscribeError { alreadySubscribed, forbidden, notFound }

class SubscribeException implements Exception {
  const SubscribeException(this.error);

  final SubscribeError error;
}

/// Application state: servers, accounts, subscriptions and messages.
///
/// Notifications are delivered by Apple Push Notification service: the app
/// registers its device token with every server it is subscribed on, along
/// with the list of topics. While the app is open, a WebSocket connection
/// keeps the message lists up to date in real time.
class AppState extends ChangeNotifier {
  AppState({Storage? storage, PushService? push})
    : storage = storage ?? Storage(),
      push = push ?? PushService.instance {
    _sync = SyncService(this.storage);
    realtime = RealtimeService(
      onMessage: _onRealtimeMessage,
      onUpdate: _onRealtimeUpdate,
      tokenFor: this.storage.token,
    );
  }

  static const appVersion = '0.1.0';

  final Storage storage;
  final PushService push;
  late final SyncService _sync;
  late final RealtimeService realtime;

  List<Server> servers = [];
  List<Subscription> subscriptions = [];
  final Map<String, List<TopicMessage>> _messages = {};
  final List<StreamSubscription<dynamic>> _listeners = [];

  bool loading = true;
  PushPermission pushPermission = PushPermission.notDetermined;

  /// Subscription currently displayed: its messages are marked as read.
  String? openSubscriptionId;

  Future<void> init() async {
    servers = await storage.loadServers();
    subscriptions = await storage.loadSubscriptions();
    loading = false;

    // Keep the tokens shared with the notification extension up to date.
    for (final server in servers.where((s) => s.account != null)) {
      unawaited(
        storage
            .token(server.url)
            .then((token) => push.saveSharedToken(server.url, token)),
      );
    }
    notifyListeners();

    _listeners
      ..add(push.tokens.listen((_) => unawaited(registerDevice())))
      ..add(push.messages.listen(_onPushReceived));

    await realtime.sync(servers, subscriptions);
    unawaited(refreshAll());

    pushPermission = await push.permission();
    if (subscriptions.isNotEmpty && pushPermission != PushPermission.denied) {
      // iOS hands out the token again on every launch; it may have changed.
      unawaited(enablePush());
    }
    notifyListeners();
  }

  Server? serverFor(String url) =>
      servers.where((s) => s.url == url).firstOrNull;

  Subscription? subscription(String id) =>
      subscriptions.where((s) => s.id == id).firstOrNull;

  Subscription? subscriptionFor(String serverUrl, String topic) =>
      subscription('${serverUrl.replaceAll(RegExp(r'/+$'), '')}|$topic');

  int get totalUnread => subscriptions.fold(0, (sum, s) => sum + s.unread);

  /// Messages of a subscription, newest first (loaded lazily).
  List<TopicMessage> messages(String subscriptionId) =>
      _messages[subscriptionId] ?? const [];

  Future<void> loadMessages(String subscriptionId) async {
    _messages[subscriptionId] = await storage.loadMessages(subscriptionId);
    notifyListeners();
  }

  /// Asks for the notification permission and registers with APNs.
  Future<bool> enablePush() async {
    final granted = await push.register();
    pushPermission = granted ? PushPermission.granted : await push.permission();
    notifyListeners();
    return granted;
  }

  /// Sends the device token and the subscribed topics to every server.
  Future<void> registerDevice({Iterable<String>? only}) async {
    final token = push.token;
    if (token == null) return;

    final name = await push.deviceName();
    final urls = only ?? servers.map((s) => s.url);

    for (final url in urls) {
      final server = serverFor(url);
      if (server == null) continue;

      // Muted topics are left out: the server simply stops sending them.
      final topics = subscriptions
          .where((s) => s.serverUrl == url && !s.muted)
          .map((s) => s.topic)
          .toList();
      final api = ApiClient(url, token: await storage.token(url));

      try {
        final registration = await api.registerDevice(
          pushToken: token.token,
          environment: token.environment,
          platform: token.platform,
          topics: topics,
          name: name,
          appVersion: appVersion,
        );
        server.pushEnabled = registration.pushEnabled;
        for (final subscription in subscriptions.where(
          (s) => s.serverUrl == url,
        )) {
          subscription.pushActive =
              registration.pushEnabled &&
              registration.topics.contains(subscription.topic);
        }
      } on ApiException catch (e) {
        debugPrint('[push] registration on $url failed: $e');
      }
    }

    await storage.saveServers(servers);
    await storage.saveSubscriptions(subscriptions);
    notifyListeners();
  }

  /// Fetches messages missed while the app was closed.
  Future<void> refreshAll() async {
    await _sync.syncAll();
    subscriptions = await storage.loadSubscriptions();
    // New names or logos of the topics.
    await Future.wait(subscriptions.map(_refreshTopicInfo));
    await storage.saveSubscriptions(subscriptions);
    for (final id in _messages.keys.toList()) {
      _messages[id] = await storage.loadMessages(id);
    }
    if (openSubscriptionId != null) {
      await markRead(openSubscriptionId!);
    }
    notifyListeners();
  }

  Future<void> onResumed() async {
    realtime.reconnectAll();
    pushPermission = await push.permission();
    await refreshAll();
  }

  /// Validates a server URL and stores its description.
  Future<Server> addServer(String rawUrl) async {
    final url = ApiClient.normalizeUrl(rawUrl);
    final info = await ApiClient(url, token: await storage.token(url)).info();

    var server = serverFor(url);
    if (server == null) {
      server = Server(
        url: url,
        name: info.name,
        realtime: info.realtime,
        pushEnabled: info.pushEnabled,
      );
      servers.add(server);
    } else {
      server
        ..name = info.name
        ..realtime = info.realtime
        ..pushEnabled = info.pushEnabled;
    }

    await storage.saveServers(servers);
    await storage.setLastServerUrl(url);
    return server;
  }

  Future<void> login(
    String serverUrl,
    String email,
    String password, {
    String? code,
  }) async {
    final server = serverFor(serverUrl) ?? await addServer(serverUrl);
    final result = await ApiClient(server.url).login(
      email: email.trim(),
      password: password,
      code: code,
      deviceName: 'Tocsin · ${await push.deviceName()}',
    );

    await storage.setToken(server.url, result.token);
    await push.saveSharedToken(server.url, result.token);
    server.account = result.account;
    await storage.saveServers(servers);
    await realtime.sync(servers, subscriptions);
    // The device now belongs to the account: private topics become possible.
    await registerDevice(only: [server.url]);
    notifyListeners();
  }

  Future<void> logout(String serverUrl) async {
    final server = serverFor(serverUrl);
    if (server == null) return;

    // Private topics can no longer be received without an account.
    for (final subscription
        in subscriptions
            .where((s) => s.serverUrl == server.url && !s.isPublic)
            .toList()) {
      await _removeSubscription(subscription);
    }

    final token = await storage.token(server.url);
    final api = ApiClient(server.url, token: token);
    try {
      if (push.token != null) await api.unregisterDevice(push.token!.token);
      if (token != null) await api.logout();
    } on ApiException {
      // The token may already be revoked: forget it locally anyway.
    }

    await storage.setToken(server.url, null);
    await push.saveSharedToken(server.url, null);
    server.account = null;
    await storage.saveServers(servers);
    await realtime.sync(servers, subscriptions);
    // Register again anonymously for the remaining public topics.
    await registerDevice(only: [server.url]);
    notifyListeners();
  }

  Future<void> removeServer(String serverUrl) async {
    await logout(serverUrl);
    for (final subscription
        in subscriptions.where((s) => s.serverUrl == serverUrl).toList()) {
      await _removeSubscription(subscription);
    }
    if (push.token != null) {
      try {
        await ApiClient(serverUrl).unregisterDevice(push.token!.token);
      } on ApiException {
        // Ignore: the server may be gone.
      }
    }
    servers.removeWhere((s) => s.url == serverUrl);
    await storage.saveServers(servers);
    await realtime.sync(servers, subscriptions);
    notifyListeners();
  }

  /// Subscribes to [topic]. Throws [LoginRequiredException] for private
  /// topics when not signed in, [SubscribeException] or [ApiException].
  Future<Subscription> subscribe(String serverUrl, String topic) async {
    final server = await addServer(serverUrl);
    final name = topic.trim().toLowerCase();

    if (subscriptions.any(
      (s) => s.serverUrl == server.url && s.topic == name,
    )) {
      throw const SubscribeException(SubscribeError.alreadySubscribed);
    }

    final api = ApiClient(server.url, token: await storage.token(server.url));
    final TopicInfo info;
    try {
      info = await api.topic(name);
    } on ApiException catch (e) {
      if (e.isUnauthorized) throw LoginRequiredException(server.url);
      if (e.isForbidden) {
        throw const SubscribeException(SubscribeError.forbidden);
      }
      if (e.isNotFound) throw const SubscribeException(SubscribeError.notFound);
      rethrow;
    }

    final subscription = Subscription(
      serverUrl: server.url,
      topic: info.name,
      displayName: info.displayName,
      isPublic: info.isPublic,
      channel: info.channel,
      iconUrl: info.iconUrl,
    );

    // Import the latest messages so that the history is not empty.
    final latest = await api.messages(info.name, limit: 50);
    await storage.replaceMessages(subscription.id, latest);
    if (latest.isNotEmpty) {
      subscription.absorb(latest.first, countAsUnread: false);
    }

    subscriptions.add(subscription);
    await storage.saveSubscriptions(subscriptions);
    await realtime.sync(servers, subscriptions);
    notifyListeners();

    if (push.token == null) {
      await enablePush(); // registerDevice() runs when the token arrives
    } else {
      await registerDevice(only: [server.url]);
    }

    return subscription;
  }

  Future<void> unsubscribe(String subscriptionId) async {
    final subscription = this.subscription(subscriptionId);
    if (subscription == null) return;

    await _removeSubscription(subscription);
    await realtime.sync(servers, subscriptions);
    await registerDevice(only: [subscription.serverUrl]);
    notifyListeners();
  }

  /// A muted topic is removed from the device registration: the server stops
  /// sending its notifications, the history keeps being updated.
  Future<void> toggleMute(String subscriptionId) async {
    final subscription = this.subscription(subscriptionId);
    if (subscription == null) return;
    subscription.muted = !subscription.muted;
    await storage.saveSubscriptions(subscriptions);
    await registerDevice(only: [subscription.serverUrl]);
    notifyListeners();
  }

  Future<void> markRead(String subscriptionId) async {
    final subscription = this.subscription(subscriptionId);
    if (subscription == null) return;

    unawaited(push.removeDelivered(subscription.topic));
    if (subscription.unread == 0) return;
    subscription.unread = 0;
    await storage.saveSubscriptions(subscriptions);
    notifyListeners();
  }

  Future<void> refresh(String subscriptionId) async {
    final subscription = this.subscription(subscriptionId);
    if (subscription == null) return;
    await _sync.syncOne(subscription, countAsUnread: false, recent: true);
    await _refreshTopicInfo(subscription);
    await storage.saveSubscriptions(subscriptions);
    await loadMessages(subscriptionId);
  }

  /// Picks up a new name or logo of the topic. Best effort.
  Future<void> _refreshTopicInfo(Subscription subscription) async {
    try {
      final info = await ApiClient(
        subscription.serverUrl,
        token: await storage.token(subscription.serverUrl),
      ).topic(subscription.topic);
      subscription
        ..displayName = info.displayName
        ..iconUrl = info.iconUrl;
    } on ApiException {
      // Keep the known values.
    }
  }

  Future<void> _removeSubscription(Subscription subscription) async {
    unawaited(push.removeDelivered(subscription.topic));
    subscriptions.removeWhere((s) => s.id == subscription.id);
    _messages.remove(subscription.id);
    await storage.deleteMessages(subscription.id);
    await storage.saveSubscriptions(subscriptions);
  }

  /// A push arrived while the app is open: fetch it into the history.
  void _onPushReceived(Map<String, dynamic> payload) {
    final server = payload['server'] as String?;
    final topic = payload['topic'] as String?;
    if (server == null || topic == null) return;

    final subscription = subscriptionFor(server, topic);
    if (subscription != null) {
      unawaited(refresh(subscription.id));
    }
  }

  Future<void> _onRealtimeMessage(
    Subscription subscription,
    TopicMessage message,
  ) async {
    final current = this.subscription(subscription.id);
    if (current == null) return;

    final fresh = await storage.addMessages(current.id, [message]);
    if (fresh.isEmpty) return;

    final isOpen = openSubscriptionId == current.id;
    current.absorb(message, countAsUnread: !isOpen);
    await storage.saveSubscriptions(subscriptions);

    if (_messages.containsKey(current.id)) {
      _messages[current.id] = [message, ..._messages[current.id]!];
    }

    notifyListeners();
  }

  /// A message changed (new occurrence, acknowledgement, reminder).
  Future<void> _onRealtimeUpdate(
    Subscription subscription,
    TopicMessage message,
  ) async {
    final current = this.subscription(subscription.id);
    if (current == null) return;

    final fresh = await storage.addMessages(current.id, [message]);
    if (fresh.isNotEmpty) {
      current.absorb(message, countAsUnread: openSubscriptionId != current.id);
      await storage.saveSubscriptions(subscriptions);
    }

    if (_messages.containsKey(current.id)) {
      _messages[current.id] = await storage.loadMessages(current.id);
    }

    notifyListeners();
  }

  /// Acknowledges [message] as the signed-in user (or as this device).
  /// Returns null on success, or an error message.
  Future<String?> acknowledge(
    String subscriptionId,
    MessageAction action,
  ) async {
    final subscription = this.subscription(subscriptionId);
    if (subscription == null) return null;
    final error = await ActionRunner().run(
      action,
      headers: await ackHeaders(subscription.serverUrl),
    );
    await refresh(subscriptionId);
    return error;
  }

  /// Identifies who acknowledges: the account token, or the device name.
  Future<Map<String, String>> ackHeaders(String serverUrl) async {
    final token = await storage.token(serverUrl);
    return {
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
      'X-Name': await push.deviceName(),
    };
  }

  @override
  void dispose() {
    for (final listener in _listeners) {
      listener.cancel();
    }
    realtime.dispose();
    super.dispose();
  }
}
