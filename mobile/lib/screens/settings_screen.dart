import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../l10n/strings.dart';
import '../models/models.dart';
import '../services/push_service.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import 'login_sheet.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final state = context.watch<AppState>();
    final theme = Theme.of(context);

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          // Title on the same line as the back button.
          SliverAppBar(
            pinned: true,
            title: Text(
              s.t('settings'),
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
          SliverList.list(
            children: [
              SettingsSection(
                title: s.t('notifications_title'),
                children: [_PushStatusTile(state: state)],
              ),
              SettingsSection(
                title: s.t('servers'),
                children: state.servers.isEmpty
                    ? [
                        ListTile(
                          title: Text(
                            s.t('no_servers'),
                            style: TextStyle(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      ]
                    : [
                        for (final server in state.servers)
                          _ServerTile(server: server),
                      ],
              ),
              SettingsSection(
                title: s.t('about'),
                children: [
                  ListTile(
                    leading: ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.asset(
                        'assets/icon.png',
                        width: 32,
                        height: 32,
                      ),
                    ),
                    title: Text(
                      s.t('app_name'),
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    subtitle: Text(s.t('about_text')),
                  ),
                  ListTile(
                    leading: const Icon(Icons.code_rounded),
                    title: Text(s.t('source_code')),
                    trailing: const Icon(Icons.open_in_new_rounded, size: 18),
                    onTap: () => launchUrl(
                      Uri.parse('https://github.com/TelKine-Studio/tocsin'),
                      mode: LaunchMode.externalApplication,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 40),
            ],
          ),
        ],
      ),
    );
  }
}

class _PushStatusTile extends StatelessWidget {
  const _PushStatusTile({required this.state});

  final AppState state;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final (icon, color, title, subtitle) = switch (state.pushPermission) {
      PushPermission.granted when state.push.token != null => (
        Icons.notifications_active,
        Colors.green,
        s.t('push_active'),
        s.t('push_active_hint'),
      ),
      PushPermission.granted => (
        Icons.hourglass_empty,
        Colors.orange,
        s.t('push_registering'),
        state.push.lastError ?? s.t('push_registering_hint'),
      ),
      PushPermission.denied => (
        Icons.notifications_off,
        Theme.of(context).colorScheme.error,
        s.t('push_denied'),
        s.t('push_denied_hint'),
      ),
      PushPermission.unsupported => (
        Icons.phone_iphone,
        Theme.of(context).colorScheme.outline,
        s.t('push_unsupported'),
        s.t('push_unsupported_hint'),
      ),
      PushPermission.notDetermined => (
        Icons.notifications_none,
        Theme.of(context).colorScheme.primary,
        s.t('notifications_permission'),
        s.t('notifications_permission_hint'),
      ),
    };

    return ListTile(
      leading: Icon(icon, color: color),
      title: Text(title),
      subtitle: Text(subtitle),
      onTap: switch (state.pushPermission) {
        PushPermission.denied => state.push.openSettings,
        PushPermission.notDetermined ||
        PushPermission.granted => state.enablePush,
        PushPermission.unsupported => null,
      },
    );
  }
}

class _ServerTile extends StatelessWidget {
  const _ServerTile({required this.server});

  final Server server;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final state = context.read<AppState>();
    final account = server.account;

    return ListTile(
      leading: const Icon(Icons.dns_outlined),
      title: Text(
        server.name.isEmpty ? server.host : '${server.name} · ${server.host}',
      ),
      subtitle: Text(
        account == null
            ? s.t('not_signed_in')
            : s.t('signed_in_as', {'name': account.email}),
      ),
      trailing: IconButton(
        icon: const Icon(Icons.more_horiz_rounded),
        onPressed: () async {
          final choice = await showActionSheet<String>(
            context,
            header: Text(
              server.host,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
            actions: [
              if (account == null)
                SheetAction(
                  value: 'login',
                  label: s.t('sign_in'),
                  icon: Icons.login_rounded,
                )
              else
                SheetAction(
                  value: 'logout',
                  label: s.t('sign_out'),
                  icon: Icons.logout_rounded,
                ),
              SheetAction(
                value: 'remove',
                label: s.t('remove_server'),
                icon: Icons.delete_outline_rounded,
                destructive: true,
              ),
            ],
          );
          if (!context.mounted) return;

          switch (choice) {
            case 'login':
              await showLoginSheet(context, server.url);
            case 'logout':
              await state.logout(server.url);
            case 'remove':
              final confirmed = await showConfirmSheet(
                context,
                title: s.t('remove_server'),
                message: s.t('remove_server_confirm', {'server': server.host}),
                confirmLabel: s.t('remove_server'),
              );
              if (confirmed) await state.removeServer(server.url);
          }
        },
      ),
    );
  }
}
