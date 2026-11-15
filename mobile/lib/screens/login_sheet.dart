import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../l10n/strings.dart';
import '../services/api_client.dart';
import '../state/app_state.dart';

/// Bottom sheet asking for the credentials of an account on [serverUrl]
/// (and the two-factor code when the account requires one).
Future<bool> showLoginSheet(
  BuildContext context,
  String serverUrl, {
  String? reason,
}) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    useSafeArea: true,
    builder: (_) => _LoginSheet(serverUrl: serverUrl, reason: reason),
  );
  return result ?? false;
}

class _LoginSheet extends StatefulWidget {
  const _LoginSheet({required this.serverUrl, this.reason});

  final String serverUrl;
  final String? reason;

  @override
  State<_LoginSheet> createState() => _LoginSheetState();
}

class _LoginSheetState extends State<_LoginSheet> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _busy = false;
  bool _obscure = true;
  bool _needsCode = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await context.read<AppState>().login(
        widget.serverUrl,
        _email.text,
        _password.text,
        code: _needsCode ? _code.text : null,
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() {
        if (e.needsTwoFactorCode && !_needsCode) {
          _needsCode = true;
          _error = null;
        } else {
          _error = e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final host = Uri.tryParse(widget.serverUrl)?.host ?? widget.serverUrl;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        24,
        0,
        24,
        MediaQuery.viewInsetsOf(context).bottom + 24,
      ),
      child: Form(
        key: _form,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              _needsCode
                  ? s.t('two_factor_title')
                  : (widget.reason ?? s.t('sign_in')),
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              _needsCode
                  ? s.t('two_factor_hint')
                  : s.t('login_required_hint', {'server': host}),
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 24),
            if (!_needsCode) ...[
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [
                  AutofillHints.email,
                  AutofillHints.username,
                ],
                textInputAction: TextInputAction.next,
                autofocus: true,
                decoration: InputDecoration(
                  labelText: s.t('email'),
                  prefixIcon: const Icon(Icons.mail_outline_rounded),
                ),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? s.t('required') : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _password,
                obscureText: _obscure,
                autofillHints: const [AutofillHints.password],
                onFieldSubmitted: (_) => _submit(),
                decoration: InputDecoration(
                  labelText: s.t('password'),
                  prefixIcon: const Icon(Icons.lock_outline_rounded),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscure
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                    onPressed: () => setState(() => _obscure = !_obscure),
                  ),
                ),
                validator: (v) =>
                    (v == null || v.isEmpty) ? s.t('required') : null,
              ),
            ] else
              TextFormField(
                controller: _code,
                autofocus: true,
                keyboardType: TextInputType.number,
                autofillHints: const [AutofillHints.oneTimeCode],
                textAlign: TextAlign.center,
                style: theme.textTheme.headlineSmall?.copyWith(
                  letterSpacing: 8,
                  fontWeight: FontWeight.w600,
                ),
                onFieldSubmitted: (_) => _submit(),
                decoration: const InputDecoration(hintText: '123456'),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? s.t('required') : null,
              ),
            if (_error != null) ...[
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: theme.colorScheme.errorContainer,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  _error!,
                  style: TextStyle(color: theme.colorScheme.onErrorContainer),
                ),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.5),
                    )
                  : Text(_needsCode ? s.t('verify') : s.t('sign_in')),
            ),
          ],
        ),
      ),
    );
  }
}
