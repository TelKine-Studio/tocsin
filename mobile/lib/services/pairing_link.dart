/// Link of the "Add on mobile" QR code of a topic page:
/// `https://<server>/m/<topic>?code=<one-time code>`, or the same URL
/// wrapped as `tocsin://pair?url=…` by the server's landing page.
class PairingLink {
  const PairingLink({required this.serverUrl, required this.topic, this.code});

  final String serverUrl;
  final String topic;

  /// One-time code signing the app in (private topics only).
  final String? code;

  static PairingLink? parse(String raw) {
    var uri = Uri.tryParse(raw.trim());
    if (uri == null) return null;

    if (uri.scheme == 'tocsin') {
      final inner = uri.queryParameters['url'];
      uri = inner == null ? null : Uri.tryParse(inner);
      if (uri == null) return null;
    }
    if (uri.scheme != 'https' && uri.scheme != 'http') return null;

    // The server may live under a path prefix: <prefix>/m/<topic>.
    final segments = uri.pathSegments.where((s) => s.isNotEmpty).toList();
    if (segments.length < 2 || segments[segments.length - 2] != 'm') {
      return null;
    }
    final prefix = segments.sublist(0, segments.length - 2).join('/');
    final code = uri.queryParameters['code'];

    return PairingLink(
      serverUrl:
          '${uri.scheme}://${uri.authority}${prefix.isEmpty ? '' : '/$prefix'}',
      topic: segments.last.toLowerCase(),
      code: code == null || code.isEmpty ? null : code,
    );
  }
}
