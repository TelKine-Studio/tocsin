import 'package:flutter/material.dart';

import '../l10n/strings.dart';
import '../theme.dart';

class TopicAvatar extends StatelessWidget {
  const TopicAvatar({
    super.key,
    required this.name,
    required this.label,
    required this.isPublic,
    this.size = 48,
    this.iconUrl,
  });

  final String name;
  final String label;
  final bool isPublic;
  final double size;

  /// Logo of the topic; the initial is shown without it (or while loading).
  final String? iconUrl;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = TocsinTheme.topicColors(
      name,
      Theme.of(context).brightness,
    );
    final initial = Text(
      label.isEmpty ? '#' : label.characters.first.toUpperCase(),
      style: TextStyle(
        color: foreground,
        fontWeight: FontWeight.w700,
        fontSize: size * 0.4,
      ),
    );

    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Container(
            width: size,
            height: size,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: background,
              borderRadius: BorderRadius.circular(size * 0.3),
            ),
            clipBehavior: Clip.antiAlias,
            child: iconUrl == null
                ? initial
                : Image.network(
                    iconUrl!,
                    width: size,
                    height: size,
                    fit: BoxFit.cover,
                    errorBuilder: (_, _, _) => initial,
                    frameBuilder: (context, child, frame, sync) =>
                        frame == null && !sync ? initial : child,
                  ),
          ),
          if (!isPublic)
            Positioned(
              right: -3,
              bottom: -3,
              child: Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  color: Theme.of(context).cardTheme.color,
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: Theme.of(context).dividerTheme.color ?? Colors.grey,
                  ),
                ),
                child: Icon(
                  Icons.lock,
                  size: size * 0.2,
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.message,
    this.action,
  });

  final IconData icon;
  final String title;
  final String? message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    theme.colorScheme.primary.withValues(alpha: 0.18),
                    Colors.transparent,
                  ],
                ),
              ),
              alignment: Alignment.center,
              child: Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  color: theme.cardTheme.color,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: theme.dividerTheme.color ?? Colors.grey,
                  ),
                ),
                child: Icon(icon, size: 30, color: theme.colorScheme.primary),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              title,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w600,
              ),
              textAlign: TextAlign.center,
            ),
            if (message != null) ...[
              const SizedBox(height: 8),
              Text(
                message!,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                  height: 1.45,
                ),
                textAlign: TextAlign.center,
              ),
            ],
            if (action != null) ...[const SizedBox(height: 28), action!],
          ],
        ),
      ),
    );
  }
}

class PriorityChip extends StatelessWidget {
  const PriorityChip({super.key, required this.priority});

  final int priority;

  @override
  Widget build(BuildContext context) {
    if (priority == 3) return const SizedBox.shrink();

    final dark = Theme.of(context).brightness == Brightness.dark;
    final (background, foreground) = switch (priority) {
      >= 5 => (const Color(0xFFFEE2E2), const Color(0xFFB91C1C)),
      4 => (const Color(0xFFFEF3C7), const Color(0xFFB45309)),
      _ => (const Color(0xFFF4F4F5), const Color(0xFF52525B)),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: dark ? foreground.withValues(alpha: 0.2) : background,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 6,
            height: 6,
            decoration: BoxDecoration(
              color: foreground,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 5),
          Text(
            S.of(context).t('priority_$priority'),
            style: TextStyle(
              color: dark ? background : foreground,
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class LiveIndicator extends StatelessWidget {
  const LiveIndicator({super.key, required this.connected, this.size = 9});

  final bool connected;
  final double size;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Tooltip(
      message: connected ? s.t('connected') : s.t('offline'),
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: connected
              ? const Color(0xFF10B981)
              : Theme.of(context).colorScheme.outlineVariant,
          border: Border.all(
            color: Theme.of(context).cardTheme.color ?? Colors.white,
            width: 2,
          ),
        ),
      ),
    );
  }
}

/// iOS style grouped section.
class SettingsSection extends StatelessWidget {
  const SettingsSection({
    super.key,
    required this.title,
    required this.children,
  });

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(left: 4, bottom: 8),
            child: Text(
              title.toUpperCase(),
              style: theme.textTheme.labelSmall?.copyWith(
                letterSpacing: 0.8,
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ),
          Card(
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                for (var i = 0; i < children.length; i++) ...[
                  if (i > 0) const Divider(indent: 56),
                  children[i],
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

String formatRelative(BuildContext context, DateTime time) {
  final diff = DateTime.now().difference(time);
  final fr = Localizations.localeOf(context).languageCode == 'fr';
  if (diff.inMinutes < 1) return fr ? 'à l’instant' : 'now';
  if (diff.inHours < 1) {
    return fr ? '${diff.inMinutes} min' : '${diff.inMinutes}m';
  }
  if (diff.inDays < 1) return fr ? '${diff.inHours} h' : '${diff.inHours}h';
  if (diff.inDays < 7) return fr ? '${diff.inDays} j' : '${diff.inDays}d';
  return MaterialLocalizations.of(context).formatShortDate(time);
}
