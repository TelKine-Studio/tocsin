import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:tocsin/models/models.dart';
import 'package:tocsin/services/action_runner.dart';

void main() {
  const ack = MessageAction(
    action: 'http',
    label: 'ack',
    url: 'http://push.example.com/v1/messages/1/ack?signature=x',
    method: 'POST',
    ack: true,
  );

  ActionRunner runner(Future<http.Response> Function(http.Request) handler) =>
      ActionRunner(client: MockClient(handler));

  test('follows a 307 to https with the same method', () async {
    final seen = <String>[];
    final result = await runner((request) async {
      seen.add('${request.method} ${request.url.scheme}');
      return request.url.scheme == 'http'
          ? http.Response(
              '',
              307,
              headers: {
                'location': request.url.replace(scheme: 'https').toString(),
              },
            )
          : http.Response('{"acknowledged":true}', 200);
    }).send(ack);

    expect(result.ok, isTrue);
    expect(seen, ['POST http', 'POST https']);
  });

  test('another redirect is a failure, not a success', () async {
    final result = await runner(
      (_) async => http.Response('', 302, headers: {'location': '/login'}),
    ).send(ack);
    expect(result.ok, isFalse);
    expect(result.retry, isFalse);
  });

  test('no network and server errors are retried, refusals are not', () async {
    expect(
      (await runner((_) => throw const SocketException('offline')).send(ack))
          .retry,
      isTrue,
    );
    expect(
      (await runner((_) async => http.Response('', 503)).send(ack)).retry,
      isTrue,
    );
    final refused = await runner((_) async => http.Response('', 403)).send(ack);
    expect(refused.retry, isFalse);
    expect(refused.error, 'HTTP 403');
  });
}
