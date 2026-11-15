import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tocsin/services/api_client.dart';

void main() {
  group('normalizeUrl', () {
    test('adds https and strips trailing slashes', () {
      expect(
        ApiClient.normalizeUrl(' notify.example.com/ '),
        'https://notify.example.com',
      );
      expect(
        ApiClient.normalizeUrl('http://192.168.1.10:8000//'),
        'http://192.168.1.10:8000',
      );
    });
  });

  test('sends the bearer token and parses messages', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(
        jsonEncode({
          'data': [
            {
              'id': 2,
              'topic': 'backups',
              'message': 'hello',
              'priority': 3,
              'tags': [],
              'time': 1700000000,
            },
          ],
        }),
        200,
      );
    });

    final api = ApiClient(
      'https://n.example.com',
      token: 'abc',
      client: client,
    );
    final messages = await api.messages('backups', since: 1);

    expect(
      captured.url.toString(),
      'https://n.example.com/api/v1/topics/backups/messages?since=1&limit=50',
    );
    expect(captured.headers['Authorization'], 'Bearer abc');
    expect(messages.single.message, 'hello');
  });

  test('maps HTTP errors to ApiException', () async {
    final client = MockClient(
      (_) async =>
          http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401),
    );
    final api = ApiClient('https://n.example.com', client: client);

    expect(
      () => api.topic('secret'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.isUnauthorized,
          'isUnauthorized',
          isTrue,
        ),
      ),
    );
  });

  test('surfaces validation errors', () async {
    final client = MockClient(
      (_) async => http.Response(
        jsonEncode({
          'message': 'The given data was invalid.',
          'errors': {
            'email': ['These credentials do not match our records.'],
          },
        }),
        422,
      ),
    );
    final api = ApiClient('https://n.example.com', client: client);

    expect(
      () => api.login(email: 'a@b.c', password: 'x', deviceName: 'test'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.message,
          'message',
          'These credentials do not match our records.',
        ),
      ),
    );
  });

  test('rejects servers that are not Tocsin', () async {
    final client = MockClient(
      (_) async => http.Response(jsonEncode({'hello': 'world'}), 200),
    );

    expect(
      () => ApiClient('https://n.example.com', client: client).info(),
      throwsA(isA<ApiException>()),
    );
  });

  test('registers the device for push notifications', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(
        jsonEncode({
          'data': {
            'push_enabled': true,
            'topics': ['alerts'],
            'rejected': [],
          },
        }),
        200,
      );
    });

    final registration =
        await ApiClient(
          'https://n.example.com',
          token: 't',
          client: client,
        ).registerDevice(
          pushToken: 'ab' * 32,
          environment: 'sandbox',
          topics: ['alerts'],
          name: 'iPhone',
        );

    expect(captured.method, 'PUT');
    expect(captured.url.path, '/api/v1/devices');
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['platform'], 'ios');
    expect(body['environment'], 'sandbox');
    expect(body['topics'], ['alerts']);
    expect(registration.pushEnabled, isTrue);
    expect(registration.topics, ['alerts']);
  });

  test('flags accounts that need a two-factor code', () async {
    final client = MockClient(
      (_) async => http.Response(
        jsonEncode({
          'message': 'Enter the code from your authenticator app.',
          'errors': {
            'code': ['Enter the code from your authenticator app.'],
          },
        }),
        422,
      ),
    );

    expect(
      () => ApiClient(
        'https://n.example.com',
        client: client,
      ).login(email: 'a@b.c', password: 'x', deviceName: 't'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.needsTwoFactorCode,
          'needsTwoFactorCode',
          isTrue,
        ),
      ),
    );
  });
}
