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
      final request = http.Request(action.method, uri)
        ..headers.addAll(action.headers)
        ..headers.addAll(headers);
      if (action.body != null && action.body!.isNotEmpty) {
        request.body = action.body!;
      }
      final response = await _client
          .send(request)
          .timeout(const Duration(seconds: 15));
      return response.statusCode < 400 ? null : 'HTTP ${response.statusCode}';
    } catch (e) {
      return e.toString();
    }
  }
}
