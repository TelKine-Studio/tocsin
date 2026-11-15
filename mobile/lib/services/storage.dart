import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models/models.dart';

/// Local persistence: servers, subscriptions and cached messages live in
/// shared preferences, access tokens in the platform secure storage
/// (Keychain / Android Keystore).
class Storage {
  Storage({FlutterSecureStorage? secure})
    : _secure = secure ?? const FlutterSecureStorage();

  final FlutterSecureStorage _secure;

  /// Maximum number of messages kept per subscription.
  static const maxMessagesPerTopic = 200;

  static const _serversKey = 'servers';
  static const _subscriptionsKey = 'subscriptions';
  static const _lastServerKey = 'last_server_url';

  Future<SharedPreferences> _prefs() async {
    final prefs = await SharedPreferences.getInstance();
    // The background isolate and the UI may both write: always read fresh.
    await prefs.reload();
    return prefs;
  }

  Future<List<Server>> loadServers() async {
    final raw = (await _prefs()).getString(_serversKey);
    if (raw == null) return [];
    return (jsonDecode(raw) as List)
        .map((e) => Server.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<void> saveServers(List<Server> servers) async {
    await (await _prefs()).setString(
      _serversKey,
      jsonEncode(servers.map((s) => s.toJson()).toList()),
    );
  }

  Future<List<Subscription>> loadSubscriptions() async {
    final raw = (await _prefs()).getString(_subscriptionsKey);
    if (raw == null) return [];
    return (jsonDecode(raw) as List)
        .map((e) => Subscription.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<void> saveSubscriptions(List<Subscription> subscriptions) async {
    await (await _prefs()).setString(
      _subscriptionsKey,
      jsonEncode(subscriptions.map((s) => s.toJson()).toList()),
    );
  }

  /// Cached messages of a subscription, newest first.
  Future<List<TopicMessage>> loadMessages(String subscriptionId) async {
    final raw = (await _prefs()).getString(_messagesKey(subscriptionId));
    if (raw == null) return [];
    return (jsonDecode(raw) as List)
        .map((e) => TopicMessage.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  /// Merges [incoming] into the cache and returns the messages that were new.
  /// Known messages are replaced by their incoming version (grouping
  /// counter, acknowledgement).
  Future<List<TopicMessage>> addMessages(
    String subscriptionId,
    List<TopicMessage> incoming,
  ) async {
    // Messages deleted on this device are not brought back by a sync.
    final hidden = await _hidden(subscriptionId);
    incoming = incoming
        .where((m) => m.id > hidden.upTo && !hidden.ids.contains(m.id))
        .toList();

    final existing = await loadMessages(subscriptionId);
    final known = existing.map((m) => m.id).toSet();
    final fresh = incoming.where((m) => !known.contains(m.id)).toList();
    final updates = {
      for (final m in incoming)
        if (known.contains(m.id)) m.id: m,
    };

    if (fresh.isNotEmpty || updates.isNotEmpty) {
      final merged = [...fresh, for (final m in existing) updates[m.id] ?? m]
        ..sort((a, b) => b.id.compareTo(a.id));
      await (await _prefs()).setString(
        _messagesKey(subscriptionId),
        jsonEncode(
          merged.take(maxMessagesPerTopic).map((m) => m.toJson()).toList(),
        ),
      );
    }

    return fresh;
  }

  /// Adds [incoming] messages that are not cached yet, without touching
  /// the known ones (a notification carries less than the server).
  Future<List<TopicMessage>> addMissingMessages(
    String subscriptionId,
    List<TopicMessage> incoming,
  ) async {
    final known = (await loadMessages(subscriptionId)).map((m) => m.id).toSet();
    final fresh = incoming.where((m) => !known.contains(m.id)).toList();
    return fresh.isEmpty ? fresh : addMessages(subscriptionId, fresh);
  }

  Future<void> replaceMessages(
    String subscriptionId,
    List<TopicMessage> messages,
  ) async {
    await (await _prefs()).setString(
      _messagesKey(subscriptionId),
      jsonEncode(
        messages.take(maxMessagesPerTopic).map((m) => m.toJson()).toList(),
      ),
    );
  }

  Future<void> deleteMessages(String subscriptionId) async {
    final prefs = await _prefs();
    await prefs.remove(_messagesKey(subscriptionId));
    await prefs.remove(_hiddenKey(subscriptionId));
  }

  /// Hides messages on this device: every message up to [upTo] (clearing
  /// the topic) and the given [ids] (deleted one by one).
  Future<void> hideMessages(
    String subscriptionId, {
    int? upTo,
    Iterable<int> ids = const [],
  }) async {
    final hidden = await _hidden(subscriptionId);
    final newUpTo = upTo != null && upTo > hidden.upTo ? upTo : hidden.upTo;
    final newIds = {...hidden.ids, ...ids}.where((id) => id > newUpTo).toList();
    await (await _prefs()).setString(
      _hiddenKey(subscriptionId),
      jsonEncode({'up_to': newUpTo, 'ids': newIds}),
    );
  }

  Future<({int upTo, Set<int> ids})> _hidden(String subscriptionId) async {
    final raw = (await _prefs()).getString(_hiddenKey(subscriptionId));
    if (raw == null) return (upTo: 0, ids: <int>{});
    final json = (jsonDecode(raw) as Map).cast<String, dynamic>();
    return (
      upTo: json['up_to'] as int? ?? 0,
      ids: {for (final id in json['ids'] as List? ?? const []) id as int},
    );
  }

  Future<String?> lastServerUrl() async =>
      (await _prefs()).getString(_lastServerKey);

  Future<void> setLastServerUrl(String url) async =>
      (await _prefs()).setString(_lastServerKey, url);

  Future<String?> token(String serverUrl) =>
      _secure.read(key: _tokenKey(serverUrl));

  Future<void> setToken(String serverUrl, String? token) async {
    if (token == null) {
      await _secure.delete(key: _tokenKey(serverUrl));
    } else {
      await _secure.write(key: _tokenKey(serverUrl), value: token);
    }
  }

  String _messagesKey(String subscriptionId) => 'messages:$subscriptionId';

  String _hiddenKey(String subscriptionId) => 'hidden:$subscriptionId';

  String _tokenKey(String serverUrl) => 'token:$serverUrl';
}
