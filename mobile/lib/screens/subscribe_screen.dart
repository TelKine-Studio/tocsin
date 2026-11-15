import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../l10n/strings.dart';
import '../services/api_client.dart';
import '../state/app_state.dart';
import 'login_sheet.dart';

class SubscribeScreen extends StatefulWidget {
  const SubscribeScreen({super.key});

  @override
  State<SubscribeScreen> createState() => _SubscribeScreenState();
}

class _SubscribeScreenState extends State<SubscribeScreen> {
  static final _topicPattern = RegExp(r'^[a-z0-9][a-z0-9_-]{0,63}$');

  final _form = GlobalKey<FormState>();
  final _server = TextEditingController();
  final _topic = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    context.read<AppState>().storage.lastServerUrl().then((url) {
      if (url != null && _server.text.isEmpty && mounted) _server.text = url;
    });
  }

  @override
  void dispose() {
    _server.dispose();
    _topic.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    final s = S.of(context);
    final state = context.read<AppState>();

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await _subscribe(state);
    } on LoginRequiredException catch (e) {
      if (!mounted) return;
      final signedIn = await showLoginSheet(
        context,
        e.serverUrl,
        reason: s.t('login_required'),
      );
      if (signedIn && mounted) {
        try {
          await _subscribe(state);
        } on SubscribeException catch (e) {
          _showError(e);
        } on ApiException catch (e) {
          setState(() => _error = e.message);
        }
      }
    } on SubscribeException catch (e) {
      _showError(e);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _subscribe(AppState state) async {
    final subscription = await state.subscribe(_server.text, _topic.text);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          S.of(context).t('subscribed', {'topic': subscription.displayName}),
        ),
      ),
    );
    Navigator.of(context).pop();
  }

  void _showError(SubscribeException e) {
    final s = S.of(context);
    setState(
      () => _error = switch (e.error) {
        SubscribeError.alreadySubscribed => s.t('already_subscribed'),
        SubscribeError.forbidden => s.t('forbidden'),
        SubscribeError.notFound => s.t('not_found'),
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final known = context.watch<AppState>().servers;

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: Form(
          key: _form,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
            children: [
              // ListView stretches its children: Align keeps the logo square.
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    // The app icon's gradient.
                    gradient: const LinearGradient(
                      colors: [
                        Color(0xFFFF9A4D),
                        Color(0xFFF05A1A),
                        Color(0xFFB8300C),
                      ],
                      stops: [0, 0.55, 1],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFFF05A1A).withValues(alpha: 0.3),
                        blurRadius: 20,
                        offset: const Offset(0, 8),
                      ),
                    ],
                  ),
                  child: const Icon(
                    Icons.notifications_active_rounded,
                    color: Colors.white,
                    size: 32,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                s.t('subscribe_to_topic'),
                style: theme.textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                s.t('subscribe_hint'),
                style: theme.textTheme.bodyLarge?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 28),
              TextFormField(
                controller: _server,
                keyboardType: TextInputType.url,
                autocorrect: false,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(
                  labelText: s.t('server_url'),
                  hintText: s.t('server_url_hint'),
                  prefixIcon: const Icon(Icons.dns_outlined),
                ),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? s.t('required') : null,
              ),
              if (known.length > 1) ...[
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final server in known)
                      ActionChip(
                        label: Text(server.host),
                        onPressed: () =>
                            setState(() => _server.text = server.url),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 14),
              TextFormField(
                controller: _topic,
                autocorrect: false,
                autofocus: true,
                textInputAction: TextInputAction.done,
                onFieldSubmitted: (_) => _submit(),
                decoration: InputDecoration(
                  labelText: s.t('topic'),
                  hintText: s.t('topic_hint'),
                  prefixIcon: const Icon(Icons.tag_rounded),
                ),
                validator: (v) {
                  final value = v?.trim().toLowerCase() ?? '';
                  if (value.isEmpty) return s.t('required');
                  if (!_topicPattern.hasMatch(value)) {
                    return s.t('invalid_topic');
                  }
                  return null;
                },
              ),
              if (_error != null) ...[
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.errorContainer,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Text(
                    _error!,
                    style: TextStyle(color: theme.colorScheme.onErrorContainer),
                  ),
                ),
              ],
              const SizedBox(height: 28),
              FilledButton.icon(
                onPressed: _busy ? null : _submit,
                icon: _busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.notifications_active_outlined),
                label: Text(s.t('subscribe')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
