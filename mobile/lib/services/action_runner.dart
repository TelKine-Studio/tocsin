import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import '../models/models.dart';

/// Runs the buttons attached to a message.
class ActionRunner {
  ActionRunner({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  /// Returns null on success, or an error message. [headers] are added to
  /// HTTP actions (e.g. the account token for "Acknowledge").
  Future<String?> run(
    MessageAction action, {
    Map<String, String> headers = const {},
  }) async {
    final uri = Uri.tryParse(action.url);
    if (uri == null || !uri.hasScheme) return 'Invalid URL';

    if (!action.isHttp) {
      final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
      return opened ? null : 'Unable to open ${uri.host}';
    }

    try {
      // A POST is not followed through redirects by the HTTP client: a 307
      // or 308 (http -> https, for instance) is sent again to its target,
      // any other redirect is a failure rather than a silent success.
      var target = uri;
      for (var hop = 0; hop < 3; hop++) {
        final request = http.Request(action.method, target)
          ..followRedirects = false
          ..headers.addAll(action.headers)
          ..headers.addAll(headers);
        if (action.body != null && action.body!.isNotEmpty) {
          request.body = action.body!;
        }
        final response = await _client
            .send(request)
            .timeout(const Duration(seconds: 15));
        final location = response.headers['location'];
        if ((response.statusCode == 307 || response.statusCode == 308) &&
            location != null) {
          target = target.resolve(location);
          continue;
        }
        return response.statusCode < 300 ? null : 'HTTP ${response.statusCode}';
      }
      return 'Too many redirects';
    } catch (e) {
      return e.toString();
    }
  }
}
