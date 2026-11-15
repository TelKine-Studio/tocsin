import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// APNs registration of this device.
class PushToken {
  const PushToken(this.token, this.environment, {this.platform = 'ios'});

  final String token;

  /// `sandbox` for iOS development builds, `production` otherwise.
  final String environment;

  /// `ios` (APNs) or `android` (Firebase Cloud Messaging).
  final String platform;
}

enum PushPermission { granted, denied, notDetermined, unsupported }

/// Apple Push Notification service, through the native "tocsin/push"
/// channel implemented in `ios/Runner/AppDelegate.swift`.
///
/// Notifications are displayed by iOS itself; this service only provides the
/// device token to the servers and reports notifications received while the
/// app is open or tapped by the user.
class PushService {
  PushService._() {
    _channel.setMethodCallHandler(_onCall);
  }

  static final PushService instance = PushService._();

  static const _channel = MethodChannel('tocsin/push');

  bool get isSupported =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.iOS ||
          defaultTargetPlatform == TargetPlatform.android);

  final _tokens = StreamController<PushToken>.broadcast();
  final _messages = StreamController<Map<String, dynamic>>.broadcast();
  final _taps = StreamController<Map<String, dynamic>>.broadcast();

  PushToken? token;
  String? lastError;

  /// Emits the device token every time iOS provides one.
  Stream<PushToken> get tokens => _tokens.stream;

  /// Notifications received while the app is in the foreground.
  Stream<Map<String, dynamic>> get messages => _messages.stream;

  /// Notifications tapped by the user.
  Stream<Map<String, dynamic>> get taps => _taps.stream;

  /// Asks for permission and registers for remote notifications.
  Future<bool> register() async {
    if (!isSupported) return false;
    try {
      return await _channel.invokeMethod<bool>('register') ?? false;
    } on PlatformException catch (e) {
      lastError = e.message;
      return false;
    }
  }

  Future<PushPermission> permission() async {
    if (!isSupported) return PushPermission.unsupported;
    final status = await _channel.invokeMethod<String>('permissionStatus');
    return switch (status) {
      'granted' => PushPermission.granted,
      'denied' => PushPermission.denied,
      'unsupported' => PushPermission.unsupported,
      _ => PushPermission.notDetermined,
    };
  }

  /// Payload of the notification that opened the app, if any.
  Future<Map<String, dynamic>?> initialNotification() async {
    if (!isSupported) return null;
    final value = await _channel.invokeMethod<Map>('initialNotification');
    return value?.cast<String, dynamic>();
  }

  Future<String> deviceName() async {
    if (!isSupported) return 'Device';
    return await _channel.invokeMethod<String>('deviceName') ?? 'iPhone';
  }

  /// Removes the notifications of a topic from the notification center.
  Future<void> removeDelivered(String topic) async {
    if (!isSupported) return;
    await _channel.invokeMethod('removeDelivered', {'thread': topic});
  }

  /// Shares the access token of a server with the notification extension,
  /// which uses it to download messages in confidential mode.
  Future<void> saveSharedToken(String server, String? token) async {
    if (!isSupported) return;
    await _channel.invokeMethod('saveSharedToken', {
      'server': server,
      'token': token,
    });
  }

  Future<void> openSettings() async {
    if (!isSupported) return;
    await _channel.invokeMethod('openSettings');
  }

  Future<dynamic> _onCall(MethodCall call) async {
    switch (call.method) {
      case 'onToken':
        final args = (call.arguments as Map).cast<String, dynamic>();
        token = PushToken(
          args['token'] as String,
          args['environment'] as String,
          platform: args['platform'] as String? ?? 'ios',
        );
        lastError = null;
        _tokens.add(token!);
      case 'onTokenError':
        lastError = call.arguments?.toString();
      case 'onMessage':
        _messages.add((call.arguments as Map).cast<String, dynamic>());
      case 'onTap':
        _taps.add((call.arguments as Map).cast<String, dynamic>());
    }
  }
}
