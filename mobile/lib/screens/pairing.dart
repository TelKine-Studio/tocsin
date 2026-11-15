import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../l10n/strings.dart';
import '../services/api_client.dart';
import '../services/pairing_link.dart';
import '../state/app_state.dart';
import 'topic_screen.dart';

/// Adds the topic of a QR code (signing in when it carries a code), then
/// opens it. Errors are shown in a snack bar.
Future<void> pairAndOpen(BuildContext context, PairingLink link) async {
  final state = context.read<AppState>();
  final s = S.of(context);
  final navigator = Navigator.of(context);
  final messenger = ScaffoldMessenger.maybeOf(context);

  void report(String message) =>
      messenger?.showSnackBar(SnackBar(content: Text(message)));

  showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (_) => _PairingProgress(
      host: Uri.tryParse(link.serverUrl)?.host ?? link.serverUrl,
    ),
  );

  try {
    final subscription = await state.pair(link);
    navigator.pop();
    report(s.t('subscribed', {'topic': subscription.displayName}));
    navigator.push(
      MaterialPageRoute(
        builder: (_) => TopicScreen(subscriptionId: subscription.id),
      ),
    );
  } on LoginRequiredException {
    navigator.pop();
    report(s.t('qr_login_required'));
  } on SubscribeException catch (e) {
    navigator.pop();
    report(switch (e.error) {
      SubscribeError.alreadySubscribed => s.t('already_subscribed'),
      SubscribeError.forbidden => s.t('forbidden'),
      SubscribeError.notFound => s.t('not_found'),
    });
  } on ApiException catch (e) {
    navigator.pop();
    report(e.message);
  } catch (_) {
    navigator.pop();
    report(s.t('qr_failed'));
  }
}

class _PairingProgress extends StatelessWidget {
  const _PairingProgress({required this.host});

  final String host;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);

    return PopScope(
      canPop: false,
      child: Center(
        child: Container(
          width: 240,
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
          decoration: BoxDecoration(
            color: theme.brightness == Brightness.dark
                ? const Color(0xFF1C1C1F)
                : Colors.white,
            borderRadius: BorderRadius.circular(26),
          ),
          child: Material(
            type: MaterialType.transparency,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const SizedBox(
                  width: 28,
                  height: 28,
                  child: CircularProgressIndicator(strokeWidth: 3),
                ),
                const SizedBox(height: 18),
                Text(
                  s.t('qr_adding'),
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  host,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
