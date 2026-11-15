import 'dart:async';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:flutter/foundation.dart';

import '../models/models.dart';

typedef MessageHandler = void Function(
  Subscription subscription,
  TopicMessage message,
);
typedef TokenLookup = Future<String?> Function(String serverUrl);

/// Keeps one WebSocket connection per server (Pusher protocol, served by
/// Laravel Reverb) and one channel per subscription.
class RealtimeService {
  RealtimeService({
    required this.onMessage,
    required this.onUpdate,
    required this.tokenFor,
  });

  final MessageHandler onMessage;

  /// A message changed (grouping counter, acknowledgement, reminder).
  final MessageHandler onUpdate;
  final TokenLookup tokenFor;

  final Map<String, _ServerConnection> _connections = {};

  /// Servers currently connected, by URL.
  final ValueNotifier<Set<String>> connected = ValueNotifier(<String>{});

  /// Reconciles open connections and channels with the given state.
  Future<void> sync(
    List<Server> servers,
    List<Subscription> subscriptions,
  ) async {
    final byServer = <String, List<Subscription>>{};
    for (final subscription in subscriptions) {
      byServer.putIfAbsent(subscription.serverUrl, () => []).add(subscription);
    }

    // Close connections that are no longer needed.
    for (final url in _connections.keys.toList()) {
      if (!byServer.containsKey(url)) {
        _connections.remove(url)!.dispose();
        _setConnected(url, false);
      }
    }

    for (final server in servers) {
      final subs = byServer[server.url];
      if (subs == null || server.realtime.key.isEmpty) continue;

      final token = await tokenFor(server.url);
      var connection = _connections[server.url];

      // Recreate the connection when the realtime config or the token changed.
      if (connection != null &&
          (connection.signature != _signature(server, token))) {
        connection.dispose();
        connection = null;
      }

      connection ??= _connections[server.url] = _ServerConnection(
        server: server,
        token: token,
        signature: _signature(server, token),
        onMessage: onMessage,
        onUpdate: onUpdate,
        onState: (isConnected) => _setConnected(server.url, isConnected),
      );

      connection.update(subs);
    }
  }

  /// Forces a reconnection, e.g. when the app comes back to the foreground.
  void reconnectAll() {
    for (final connection in _connections.values) {
      connection.reconnect();
    }
  }

  void dispose() {
    for (final connection in _connections.values) {
      connection.dispose();
    }
    _connections.clear();
  }

  String _signature(Server server, String? token) =>
      '${server.realtime.scheme}://${server.realtime.host}:${server.realtime.port}${server.realtime.path}/${server.realtime.key}|${token ?? ''}';

  void _setConnected(String url, bool value) {
    final next = {...connected.value};
    value ? next.add(url) : next.remove(url);
    if (!setEquals(next, connected.value)) connected.value = next;
  }
}

class _ServerConnection {
  _ServerConnection({
    required this.server,
    required this.token,
    required this.signature,
    required this.onMessage,
    required this.onUpdate,
    required this.onState,
  }) {
    final realtime = server.realtime;
    final options = PusherChannelsOptions.custom(
      uriResolver: (metadata) => Uri(
        scheme: realtime.isSecure ? 'wss' : 'ws',
        host: realtime.host,
        port: realtime.port,
        path: '${realtime.path}/app/${realtime.key}',
        queryParameters: metadata.getQueryParameters(),
      ),
      metadata: PusherChannelsOptionsMetadata.byDefault(),
    );

    _client = PusherChannelsClient.websocket(
      options: options,
      minimumReconnectDelayDuration: const Duration(seconds: 2),
      connectionErrorHandler: (exception, trace, refresh) {
        debugPrint('[realtime] ${server.url}: $exception');
        refresh();
      },
    );

    _lifecycle = _client.lifecycleStream.listen((state) {
      _isConnected =
          state == PusherChannelsClientLifeCycleState.establishedConnection;
      onState(_isConnected);
    });

    _established = _client.onConnectionEstablished.listen((_) {
      for (final channel in _channels.values) {
        channel.subscribeIfNotUnsubscribed();
      }
    });

    unawaited(_client.connect());
  }

  final Server server;
  final String? token;
  final String signature;
  final MessageHandler onMessage;
  final MessageHandler onUpdate;
  final void Function(bool) onState;

  late final PusherChannelsClient _client;
  late final StreamSubscription<PusherChannelsClientLifeCycleState> _lifecycle;
  late final StreamSubscription<void> _established;

  bool _isConnected = false;

  final Map<String, Channel> _channels = {};
  final Map<String, StreamSubscription<ChannelReadEvent>> _bindings = {};
  final Map<String, StreamSubscription<ChannelReadEvent>> _updateBindings = {};
  final Map<String, Subscription> _subscriptions = {};

  void update(List<Subscription> subscriptions) {
    final wanted = {for (final s in subscriptions) s.channel: s};

    for (final name in _channels.keys.toList()) {
      if (!wanted.containsKey(name)) {
        _bindings.remove(name)?.cancel();
        _updateBindings.remove(name)?.cancel();
        _channels.remove(name)?.unsubscribe();
        _subscriptions.remove(name);
      }
    }

    for (final entry in wanted.entries) {
      _subscriptions[entry.key] = entry.value;
      if (_channels.containsKey(entry.key)) continue;

      final Channel channel = entry.key.startsWith('private-')
          ? _client.privateChannel(
              entry.key,
              authorizationDelegate:
                  EndpointAuthorizableChannelTokenAuthorizationDelegate.forPrivateChannel(
                    authorizationEndpoint: Uri.parse(
                      '${server.url}/api/v1/broadcasting/auth',
                    ),
                    headers: {
                      'Accept': 'application/json',
                      if (token != null) 'Authorization': 'Bearer $token',
                    },
                  ),
            )
          : _client.publicChannel(entry.key) as Channel;

      _channels[entry.key] = channel;
      void Function(ChannelReadEvent) handle(MessageHandler handler) =>
          (event) {
            final data = event.tryGetDataAsMap();
            final subscription = _subscriptions[entry.key];
            if (data == null || subscription == null) return;
            try {
              handler(subscription, TopicMessage.fromJson(data));
            } catch (e) {
              debugPrint('[realtime] invalid message: $e');
            }
          };

      _bindings[entry.key] = channel.bind('message').listen(handle(onMessage));
      _updateBindings[entry.key] = channel
          .bind('message.updated')
          .listen(handle(onUpdate));

      if (_isConnected) {
        channel.subscribe();
      }
    }
  }

  void reconnect() {
    if (!_isConnected && !_client.isDisposed) {
      unawaited(_client.reconnect());
    }
  }

  void dispose() {
    for (final binding in [..._bindings.values, ..._updateBindings.values]) {
      binding.cancel();
    }
    _lifecycle.cancel();
    _established.cancel();
    _client.dispose();
  }
}
