import 'package:flutter_test/flutter_test.dart';
import 'package:tocsin/services/pairing_link.dart';

void main() {
  test('reads the server, topic and code of a QR code', () {
    final link = PairingLink.parse(
      'https://push.telkine.net/m/backups?code=abc',
    )!;
    expect(link.serverUrl, 'https://push.telkine.net');
    expect(link.topic, 'backups');
    expect(link.code, 'abc');
  });

  test('accepts the app link of the landing page and path prefixes', () {
    final link = PairingLink.parse(
      'tocsin://pair?url=${Uri.encodeComponent('http://10.0.0.2:8000/tocsin/m/Ops')}',
    )!;
    expect(link.serverUrl, 'http://10.0.0.2:8000/tocsin');
    expect(link.topic, 'ops');
    expect(link.code, isNull);
  });

  test('rejects other QR codes', () {
    expect(PairingLink.parse('https://example.com/menu'), isNull);
    expect(PairingLink.parse('WIFI:S:home;T:WPA;P:secret;;'), isNull);
  });
}
