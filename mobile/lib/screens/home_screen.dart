import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../l10n/strings.dart';
import '../models/models.dart';
import '../services/push_service.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import 'settings_screen.dart';
import 'subscribe_screen.dart';
import 'topic_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final state = context.watch<AppState>();
    final subscriptions = [...state.subscriptions]
      ..sort(
        (a, b) => (b.lastMessageAt ?? DateTime(0)).compareTo(
          a.lastMessageAt ?? DateTime(0),
        ),
      );
    final unread = state.totalUnread;

    return Scaffold(
      floatingActionButton: subscriptions.isEmpty
          ? null
          : FloatingActionButton(
              tooltip: s.t('subscribe'),
              onPressed: () => _openSubscribe(context),
              backgroundColor: theme.colorScheme.primary,
              foregroundColor: theme.colorScheme.onPrimary,
              elevation: 2,
              shape: const CircleBorder(),
              child: const Icon(Icons.add_rounded, size: 30),
            ),
      body: state.loading
          ? const Center(child: CircularProgressIndicator())
          : ValueListenableBuilder<Set<String>>(
              valueListenable: state.realtime.connected,
              builder: (context, connected, _) => RefreshIndicator(
                onRefresh: state.refreshAll,
                edgeOffset: 120,
                child: CustomScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  slivers: [
                    SliverAppBar.large(
                      title: Text(
                        s.t('subscriptions'),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      actions: [
                        IconButton(
                          tooltip: s.t('settings'),
                          icon: const Icon(Icons.tune_rounded),
                          onPressed: () => Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => const SettingsScreen(),
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                      ],
                    ),
                    if (subscriptions.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: EmptyState(
                          icon: Icons.notifications_active_outlined,
                          title: s.t('no_subscriptions'),
                          message: s.t('no_subscriptions_hint'),
                          action: FilledButton.icon(
                            onPressed: () => _openSubscribe(context),
                            icon: const Icon(Icons.add_rounded),
                            label: Text(s.t('subscribe_to_topic')),
                          ),
                        ),
                      )
                    else ...[
                      SliverToBoxAdapter(child: _PushBanner(state: state)),
                      SliverToBoxAdapter(
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(20, 8, 20, 2),
                          child: Text(
                            switch (unread) {
                              0 => s.t('topics_label'),
                              1 => s.t('unread_one'),
                              _ => s.t('unread_count', {'count': '$unread'}),
                            },
                            style: theme.textTheme.labelLarge?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ),
                      SliverPadding(
                        padding: const EdgeInsets.fromLTRB(0, 0, 0, 112),
                        sliver: SliverToBoxAdapter(
                          child: _SubscriptionList(
                            children: [
                              for (final subscription in subscriptions)
                                _SubscriptionRow(
                                  subscription: subscription,
                                  connected: connected.contains(
                                    subscription.serverUrl,
                                  ),
                                  serverLabel:
                                      state
                                          .serverFor(subscription.serverUrl)
                                          ?.host ??
                                      '',
                                ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
    );
  }

  void _openSubscribe(BuildContext context) {
    Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => const SubscribeScreen()));
  }
}

/// Warns when notifications cannot be delivered to this device.
class _PushBanner extends StatelessWidget {
  const _PushBanner({required this.state});

  final AppState state;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final denied = state.pushPermission == PushPermission.denied;
    final serversWithoutPush = state.servers
        .where(
          (server) =>
              !server.pushEnabled &&
              state.subscriptions.any((sub) => sub.serverUrl == server.url),
        )
        .map((server) => server.host)
        .toList();

    if (!denied && serversWithoutPush.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final color = dark ? const Color(0xFFFBBF24) : const Color(0xFFB45309);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Material(
        color: const Color(0xFFF59E0B).withValues(alpha: dark ? 0.14 : 0.1),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: denied ? state.push.openSettings : null,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(Icons.notifications_off_outlined, size: 20, color: color),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        denied
                            ? s.t('push_denied')
                            : s.t('server_push_disabled'),
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                          color: color,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        denied
                            ? s.t('push_denied_hint')
                            : s.t('server_push_disabled_hint', {
                                'servers': serversWithoutPush.join(', '),
                              }),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: theme.colorScheme.onSurfaceVariant,
                          height: 1.35,
                        ),
                      ),
                    ],
                  ),
                ),
                if (denied)
                  Icon(
                    Icons.chevron_right_rounded,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Rows directly on the background, separated by thin lines (no frame).
class _SubscriptionList extends StatelessWidget {
  const _SubscriptionList({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final line = theme.brightness == Brightness.dark
        ? const Color(0xFF27272A)
        : const Color(0xFFE7E5E4);

    return Column(
      children: [
        for (var i = 0; i < children.length; i++) ...[
          if (i > 0)
            Divider(height: 1, thickness: 0.6, indent: 82, color: line),
          children[i],
        ],
      ],
    );
  }
}

class _SubscriptionRow extends StatelessWidget {
  const _SubscriptionRow({
    required this.subscription,
    required this.connected,
    required this.serverLabel,
  });

  final Subscription subscription;
  final bool connected;
  final String serverLabel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final unread = subscription.unread;
    final muted = theme.colorScheme.onSurfaceVariant;

    return InkWell(
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => TopicScreen(subscriptionId: subscription.id),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 14),
        child: Row(
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                TopicAvatar(
                  name: subscription.topic,
                  label: subscription.displayName,
                  isPublic: subscription.isPublic,
                  iconUrl: subscription.iconUrl,
                  size: 50,
                ),
                Positioned(
                  right: -2,
                  bottom: -2,
                  child: LiveIndicator(connected: connected, size: 13),
                ),
              ],
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          subscription.displayName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontSize: 16,
                            fontWeight: unread > 0
                                ? FontWeight.w700
                                : FontWeight.w600,
                          ),
                        ),
                      ),
                      if (subscription.muted)
                        Padding(
                          padding: const EdgeInsets.only(left: 4),
                          child: Icon(
                            Icons.notifications_off_outlined,
                            size: 15,
                            color: muted,
                          ),
                        ),
                      if (subscription.lastMessageAt != null)
                        Padding(
                          padding: const EdgeInsets.only(left: 8),
                          child: Text(
                            formatRelative(
                              context,
                              subscription.lastMessageAt!,
                            ),
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: unread > 0
                                  ? theme.colorScheme.primary
                                  : muted,
                              fontWeight: unread > 0
                                  ? FontWeight.w600
                                  : FontWeight.w400,
                            ),
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          subscription.lastPreview ??
                              '#${subscription.topic} · $serverLabel',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: unread > 0
                                ? theme.colorScheme.onSurface
                                : muted,
                          ),
                        ),
                      ),
                      if (unread > 0)
                        Container(
                          margin: const EdgeInsets.only(left: 8),
                          constraints: const BoxConstraints(minWidth: 22),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 7,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: theme.colorScheme.primary,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Text(
                            unread > 99 ? '99+' : '$unread',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: theme.colorScheme.onPrimary,
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
