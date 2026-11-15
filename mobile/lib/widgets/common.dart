import 'package:flutter/material.dart';

import '../l10n/strings.dart';
import '../services/icon_cache.dart';
import '../theme.dart';

/// Logo from [IconCache]: painted on the first frame once cached, the
/// initial is shown only the first time it is downloaded.
class _TopicLogo extends StatefulWidget {
  const _TopicLogo({
    required this.url,
    required this.size,
    required this.fallback,
  });

  final String url;
  final double size;
  final Widget fallback;

  @override
  State<_TopicLogo> createState() => _TopicLogoState();
}

class _TopicLogoState extends State<_TopicLogo> {
  ImageProvider? _image;

  @override
  void initState() {
    super.initState();
    _resolve();
  }

  @override
  void didUpdateWidget(_TopicLogo old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) _resolve();
  }

  void _resolve() {
    final url = widget.url;
    _image = IconCache.ready(url);
    if (_image != null) return;
    IconCache.load(url).then((image) {
      if (mounted && image != null && widget.url == url) {
        setState(() => _image = image);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final image = _image;
    if (image == null) return widget.fallback;
    return Image(
      image: image,
      width: widget.size,
      height: widget.size,
      fit: BoxFit.cover,
      gaplessPlayback: true,
      errorBuilder: (_, _, _) => widget.fallback,
    );
  }
}

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
                : _TopicLogo(url: iconUrl!, size: size, fallback: initial),
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
    final theme = Theme.of(context);
    // Two circles (ring + dot centered inside) rather than one circle with a
    // border: at this size, a border made the dot look off-center.
    final ring = (size * 0.2).clamp(2.0, 3.0);
    return Tooltip(
      message: connected ? s.t('connected') : s.t('offline'),
      child: Container(
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: theme.scaffoldBackgroundColor,
        ),
        child: Container(
          width: size - ring * 2,
          height: size - ring * 2,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: connected
                ? const Color(0xFF10B981)
                : theme.colorScheme.outlineVariant,
          ),
        ),
      ),
    );
  }
}

/// iOS style grouped section.
/// Grouped section in the iOS style: a grey title, rows on a rounded
/// card without border, and an optional explanation below.
class SettingsSection extends StatelessWidget {
  const SettingsSection({
    super.key,
    required this.title,
    required this.children,
    this.footer,
  });

  final String title;
  final List<Widget> children;
  final String? footer;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final dark = theme.brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(left: 18, bottom: 8),
            child: Text(
              title,
              style: theme.textTheme.titleSmall?.copyWith(
                fontSize: 15,
                fontWeight: FontWeight.w600,
                color: muted,
              ),
            ),
          ),
          Container(
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              color: dark ? const Color(0xFF1C1C1F) : Colors.white,
              borderRadius: BorderRadius.circular(26),
            ),
            child: Material(
              type: MaterialType.transparency,
              child: Column(
                children: [
                  for (var i = 0; i < children.length; i++) ...[
                    if (i > 0)
                      Divider(
                        height: 1,
                        thickness: 0.6,
                        indent: 56,
                        endIndent: 18,
                        color: dark
                            ? const Color(0xFF2C2C30)
                            : const Color(0xFFE5E5EA),
                      ),
                    children[i],
                  ],
                ],
              ),
            ),
          ),
          if (footer != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 8, 18, 0),
              child: Text(
                footer!,
                style: theme.textTheme.bodySmall?.copyWith(
                  fontSize: 13,
                  height: 1.35,
                  color: muted,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// iOS-style large title: big and bold at the top of the page, reduced
/// into the bar when scrolling.
class LargeTitleBar extends StatelessWidget {
  const LargeTitleBar({super.key, required this.title, this.actions});

  final String title;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    // SliverAppBar.large uses headlineMedium expanded, titleLarge collapsed.
    return Theme(
      data: theme.copyWith(
        textTheme: theme.textTheme.copyWith(
          headlineMedium: theme.textTheme.headlineMedium?.copyWith(
            fontSize: 34,
            height: 1.1,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.6,
          ),
          titleLarge: theme.textTheme.titleLarge?.copyWith(
            fontSize: 18,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      child: SliverAppBar.large(
        expandedHeight: 128,
        title: Text(title),
        actions: actions,
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

/// Small lock shown next to the name of a private topic.
class PrivateLock extends StatelessWidget {
  const PrivateLock({super.key, this.size = 15});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: S.of(context).t('private_topic'),
      child: Icon(
        Icons.lock_rounded,
        size: size,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
    );
  }
}

/// One choice of [showActionSheet].
class SheetAction<T> {
  const SheetAction({
    required this.value,
    required this.label,
    required this.icon,
    this.destructive = false,
    this.subtitle,
  });

  final T value;
  final String label;
  final IconData icon;
  final bool destructive;
  final String? subtitle;
}

/// Tocsin's own action sheet (instead of Material menus): a rounded card
/// of choices rising from the bottom, with a separate Cancel button.
Future<T?> showActionSheet<T>(
  BuildContext context, {
  Widget? header,
  required List<SheetAction<T>> actions,
}) {
  return showModalBottomSheet<T>(
    context: context,
    backgroundColor: Colors.transparent,
    elevation: 0,
    barrierColor: Colors.black.withValues(alpha: 0.35),
    builder: (context) {
      final theme = Theme.of(context);
      final dark = theme.brightness == Brightness.dark;
      final cardColor = dark ? const Color(0xFF1C1C1F) : Colors.white;
      final line = dark ? const Color(0xFF2C2C30) : const Color(0xFFEDEDEF);

      Widget card(Widget child) => Material(
        color: cardColor,
        borderRadius: BorderRadius.circular(20),
        clipBehavior: Clip.antiAlias,
        child: child,
      );

      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 0, 10, 8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              card(
                Column(
                  children: [
                    if (header != null) ...[
                      Padding(
                        padding: const EdgeInsets.fromLTRB(18, 16, 18, 14),
                        child: header,
                      ),
                      Divider(height: 1, thickness: 0.6, color: line),
                    ],
                    for (var i = 0; i < actions.length; i++) ...[
                      if (i > 0)
                        Divider(
                          height: 1,
                          thickness: 0.6,
                          indent: 56,
                          color: line,
                        ),
                      _SheetTile(action: actions[i]),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 8),
              card(
                InkWell(
                  onTap: () => Navigator.pop(context),
                  child: SizedBox(
                    height: 56,
                    child: Center(
                      child: Text(
                        S.of(context).t('cancel'),
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                          color: theme.colorScheme.primary,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
    },
  );
}

class _SheetTile<T> extends StatelessWidget {
  const _SheetTile({required this.action});

  final SheetAction<T> action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = action.destructive
        ? const Color(0xFFDC2626)
        : theme.colorScheme.onSurface;

    return InkWell(
      onTap: () => Navigator.pop(context, action.value),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
        child: Row(
          children: [
            Icon(action.icon, size: 22, color: color),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    action.label,
                    style: theme.textTheme.bodyLarge?.copyWith(
                      color: color,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  if (action.subtitle != null)
                    Text(
                      action.subtitle!,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurfaceVariant,
                      ),
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

/// Confirmation in the same style as [showActionSheet], for destructive
/// choices. Returns true when confirmed.
Future<bool> showConfirmSheet(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
  IconData icon = Icons.delete_outline_rounded,
  bool destructive = true,
}) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    backgroundColor: Colors.transparent,
    elevation: 0,
    barrierColor: Colors.black.withValues(alpha: 0.35),
    builder: (context) {
      final theme = Theme.of(context);
      final dark = theme.brightness == Brightness.dark;
      final accent = destructive
          ? const Color(0xFFDC2626)
          : theme.colorScheme.primary;

      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 0, 10, 8),
          child: Material(
            color: dark ? const Color(0xFF1C1C1F) : Colors.white,
            borderRadius: BorderRadius.circular(24),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(22, 26, 22, 18),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 52,
                    height: 52,
                    decoration: BoxDecoration(
                      color: accent.withValues(alpha: 0.12),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(icon, color: accent, size: 26),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    message,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 22),
                  FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: accent),
                    onPressed: () => Navigator.pop(context, true),
                    child: Text(confirmLabel),
                  ),
                  const SizedBox(height: 6),
                  TextButton(
                    style: TextButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                    onPressed: () => Navigator.pop(context, false),
                    child: Text(S.of(context).t('cancel')),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );

  return result ?? false;
}
