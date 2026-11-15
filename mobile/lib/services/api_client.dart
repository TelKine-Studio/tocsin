import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/models.dart';

class ApiException implements Exception {
  const ApiException(this.message, {this.statusCode, this.fields = const {}});

  final String message;
  final int? statusCode;

  /// Validation errors by field (e.g. "code" when two-factor is required).
  final Map<String, String> fields;

  bool get needsTwoFactorCode => fields.containsKey('code');

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isNotFound => statusCode == 404;

  @override
  String toString() => message;
}

/// Result of `PUT /api/v1/devices`.
class DeviceRegistration {
  const DeviceRegistration({required this.pushEnabled, required this.topics});

  /// Whether the server can send push notifications.
  final bool pushEnabled;

  /// Topics this device will receive notifications for.
  final List<String> topics;
}

class LoginResult {
  const LoginResult(this.token, this.account);

  final String token;
  final Account account;
}

/// Thin client for the Tocsin HTTP API (`/api/v1`).
class ApiClient {
  ApiClient(this.baseUrl, {this.token, http.Client? client})
    : _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final http.Client _client;

  static const _timeout = Duration(seconds: 15);

  /// Normalizes a server address typed by the user:
  /// adds https:// when missing and removes trailing slashes.
  static String normalizeUrl(String input) {
    var url = input.trim();
    if (!url.contains('://')) {
      url = 'https://$url';
    }
    while (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    return url;
  }

  Uri _uri(String path, [Map<String, String>? query]) =>
      Uri.parse('$baseUrl/api/v1/$path').replace(queryParameters: query);

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  Future<ServerInfo> info() async {
    final json = await _send(
      () => _client.get(_uri('info'), headers: _headers),
    );
    if (json['realtime'] == null) {
      throw const ApiException(
        'This server does not look like a Tocsin server.',
      );
    }
    return ServerInfo.fromJson(json);
  }

  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
    String? code,
  }) async {
    final json = await _send(
      () => _client.post(
        _uri('auth/token'),
        headers: {..._headers, 'Content-Type': 'application/json'},
        body: jsonEncode({
          'email': email,
          'password': password,
          'device_name': deviceName,
          if (code != null && code.isNotEmpty) 'code': code,
        }),
      ),
    );

    return LoginResult(
      json['token'] as String,
      Account.fromJson((json['user'] as Map).cast<String, dynamic>()),
    );
  }

  Future<void> logout() async {
    await _send(() => _client.delete(_uri('auth/token'), headers: _headers));
  }

  Future<TopicInfo> topic(String name) async {
    final json = await _send(
      () => _client.get(
        _uri('topics/${Uri.encodeComponent(name)}'),
        headers: _headers,
      ),
    );
    return TopicInfo.fromJson((json['data'] as Map).cast<String, dynamic>());
  }

  Future<List<TopicInfo>> topics() async {
    final json = await _send(
      () => _client.get(_uri('topics'), headers: _headers),
    );
    return (json['data'] as List)
        .map((e) => TopicInfo.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  /// Registers (or updates) this device for push notifications on the
  /// given topics. The call is idempotent: [topics] is the full list.
  Future<DeviceRegistration> registerDevice({
    required String pushToken,
    required String environment,
    required List<String> topics,
    String platform = 'ios',
    String? name,
    String? appVersion,
  }) async {
    final json = await _send(
      () => _client.put(
        _uri('devices'),
        headers: {..._headers, 'Content-Type': 'application/json'},
        body: jsonEncode({
          'token': pushToken,
          'platform': platform,
          'environment': environment,
          'name': name,
          'app_version': appVersion,
          'topics': topics,
        }),
      ),
    );
    final data = (json['data'] as Map).cast<String, dynamic>();

    return DeviceRegistration(
      pushEnabled: data['push_enabled'] as bool? ?? false,
      topics: ((data['topics'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList(),
    );
  }

  Future<void> unregisterDevice(String pushToken) async {
    await _send(
      () => _client.delete(
        _uri('devices'),
        headers: {..._headers, 'Content-Type': 'application/json'},
        body: jsonEncode({'token': pushToken}),
      ),
    );
  }

  /// Messages of [topic], newest first.
  Future<List<TopicMessage>> messages(
    String topic, {
    int? since,
    int limit = 50,
  }) async {
    final json = await _send(
      () => _client.get(
        _uri('topics/${Uri.encodeComponent(topic)}/messages', {
          if (since != null && since > 0) 'since': '$since',
          'limit': '$limit',
        }),
        headers: _headers,
      ),
    );
    return (json['data'] as List)
        .map((e) => TopicMessage.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<Map<String, dynamic>> _send(
    Future<http.Response> Function() request,
  ) async {
    final http.Response response;
    try {
      response = await request().timeout(_timeout);
    } on Exception catch (e) {
      throw ApiException('Unable to reach the server ($e).');
    }

    Map<String, dynamic> body = const {};
    if (response.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map) {
          body = decoded.cast<String, dynamic>();
        }
      } on FormatException {
        if (response.statusCode < 400) {
          throw const ApiException('Unexpected answer from the server.');
        }
      }
    }

    if (response.statusCode >= 400) {
      final errors = body['errors'];
      final fields = <String, String>{};
      if (errors is Map) {
        errors.forEach((key, value) {
          fields[key.toString()] = value is List && value.isNotEmpty
              ? value.first.toString()
              : value.toString();
        });
      }
      throw ApiException(
        fields.values.firstOrNull ??
            body['message'] as String? ??
            'Request failed (HTTP ${response.statusCode}).',
        statusCode: response.statusCode,
        fields: fields,
      );
    }

    return body;
  }
}
