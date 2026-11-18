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
  }) async => (await send(action, headers: headers)).error;

  /// Runs [action] and tells a refusal (worth showing) from a failure that
  /// may pass later (no network, server unavailable).
  Future<ActionResult> send(
    MessageAction action, {
    Map<String, String> headers = const {},
  }) async {
    final uri = Uri.tryParse(action.url);
    if (uri == null || !uri.hasScheme) {
      return const ActionResult.refused('Invalid URL');
    }

    if (!action.isHttp) {
      final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
      return opened
          ? const ActionResult.ok()
          : ActionResult.refused('Unable to open ${uri.host}');
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
        final status = response.statusCode;
        final location = response.headers['location'];
        if ((status == 307 || status == 308) && location != null) {
          target = target.resolve(location);
          continue;
        }
        if (status < 300) return const ActionResult.ok();
        // Busy or down: worth trying again later.
        if (status == 408 || status == 429 || status >= 500) {
          return ActionResult.unavailable('HTTP $status');
        }
        return ActionResult.refused('HTTP $status');
      }
      return const ActionResult.refused('Too many redirects');
    } catch (e) {
      // No network, timeout, TLS: the request may not have left.
      return ActionResult.unavailable(e.toString());
    }
  }
}

/// Outcome of an action: done, refused for good, or to try again later.
class ActionResult {
  const ActionResult.ok() : error = null, retry = false;
  const ActionResult.refused(String this.error) : retry = false;
  const ActionResult.unavailable(String this.error) : retry = true;

  /// Null when the action succeeded.
  final String? error;

  /// The failure may pass: no network, timeout, server unavailable.
  final bool retry;

  bool get ok => error == null;
}
