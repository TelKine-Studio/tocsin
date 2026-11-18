import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../l10n/strings.dart';
import '../models/models.dart';
import '../services/action_runner.dart';
import '../services/api_client.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';

class TopicScreen extends StatefulWidget {
  const TopicScreen({super.key, required this.subscriptionId});

  final String subscriptionId;

  @override
  State<TopicScreen> createState() => _TopicScreenState();
}

class _TopicScreenState extends State<TopicScreen> {
  late final AppState _state;
  final _scroll = ScrollController();

  /// Message of a tapped notification, to bring into view once listed.
  int? _focusId;
  final _focusKey = GlobalKey();
  bool _focusScheduled = false;
  int _focusAttempts = 0;

  /// Message briefly tinted after being brought into view.
  int? _highlightId;
  Timer? _highlightTimer;

  @override
  void initState() {
    super.initState();
    _state = context.read<AppState>();
    _state.openSubscriptionId = widget.subscriptionId;
    _state.loadMessages(widget.subscriptionId);
    _state.markRead(widget.subscriptionId);
  }

  @override
  void dispose() {
    if (_state.openSubscriptionId == widget.subscriptionId) {
      _state.openSubscriptionId = null;
    }
    _highlightTimer?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  /// Scrolls to [_focusId] once it is in the list. The list is built
  /// lazily: far messages are first approached, then shown exactly.
  void _scheduleFocus(List<TopicMessage> messages) {
    final id = _focusId;
    if (id == null || _focusScheduled) return;
    final index = messages.indexWhere((m) => m.id == id);
    // Not synced yet: tried again when the list changes.
    if (index < 0) return;

    _focusScheduled = true;
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      _focusScheduled = false;
      if (!mounted || _focusId != id) return;

      final target = _focusKey.currentContext;
      if (target != null) {
        _focusId = null;
        await Scrollable.ensureVisible(
          target,
          alignment: 0.25,
          duration: const Duration(milliseconds: 350),
          curve: Curves.easeOutCubic,
        );
        if (!mounted) return;
        setState(() => _highlightId = id);
        _highlightTimer?.cancel();
        _highlightTimer = Timer(const Duration(milliseconds: 1600), () {
          if (mounted) setState(() => _highlightId = null);
        });
        return;
      }

      if (++_focusAttempts > 6 || !_scroll.hasClients) {
        _focusId = null;
        return;
      }
      // About the height of a message: close enough to get it built.
      final position = _scroll.position;
      _scroll.jumpTo((index * 96.0).clamp(0, position.maxScrollExtent));
      setState(() {});
    });
  }

  /// Each tap on the summary brings the next waiting alert into view.
  int _pendingCursor = 0;

  void _showNextPending(List<int> ids) {
    if (ids.isEmpty) return;
    final id = ids[_pendingCursor % ids.length];
    _pendingCursor++;
    setState(() {
      _focusId = id;
      _focusAttempts = 0;
    });
  }

  Future<void> _refresh() async {
    try {
      await _state.refresh(widget.subscriptionId);
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            S.of(context).t('refresh_failed', {'error': e.message}),
          ),
        ),
      );
    }
  }

  Future<void> _confirmUnsubscribe(Subscription subscription) async {
    final s = S.of(context);
    final confirmed = await showConfirmSheet(
      context,
      title: s.t('unsubscribe'),
      message: s.t('unsubscribe_confirm', {'topic': subscription.displayName}),
      confirmLabel: s.t('unsubscribe'),
    );

    if (confirmed && mounted) {
      Navigator.of(context).pop();
      await _state.unsubscribe(subscription.id);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final state = context.watch<AppState>();
    final subscription = state.subscription(widget.subscriptionId);

    if (subscription == null) return const Scaffold(body: SizedBox.shrink());

    final messages = state.messages(subscription.id);
    final server = state.serverFor(subscription.serverUrl);
    final focus = state.takeFocus(subscription.id);
    if (focus != null) {
      _focusId = focus;
      _focusAttempts = 0;
    }
    _scheduleFocus(messages);
    final pendingIds = [
      for (final m in messages)
        if (m.awaitsAck &&
            state.ackProgress(subscription.id, m.id) != AckProgress.done)
          m.id,
    ];
    final pending = pendingIds.length;
    final locale = Localizations.localeOf(context).toString();

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _refresh,
        edgeOffset: kToolbarHeight + MediaQuery.paddingOf(context).top,
        child: CustomScrollView(
          controller: _scroll,
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverAppBar(
              pinned: true,
              titleSpacing: 0,
              title: _Header(subscription: subscription, host: server?.host),
              actions: [
                if (subscription.muted)
                  Padding(
                    padding: const EdgeInsets.only(right: 2),
                    child: Icon(
                      Icons.notifications_off_outlined,
                      size: 20,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                _menu(context, subscription),
              ],
            ),
            if (pending > 0)
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 4, 20, 4),
                  child: _PendingSummary(
                    count: pending,
                    onTap: () => _showNextPending(pendingIds),
                  ),
                ),
              ),
            if (messages.isEmpty)
              SliverFillRemaining(
                hasScrollBody: false,
                child: EmptyState(
                  icon: Icons.inbox_outlined,
                  title: s.t('no_messages'),
                  message: s.t('no_messages_hint'),
                  action: _SendExample(subscription: subscription),
                ),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(8, 0, 8, 40),
                sliver: SliverList.builder(
                  itemCount: messages.length,
                  itemBuilder: (context, index) {
                    final message = messages[index];
                    final previous = index > 0 ? messages[index - 1] : null;
                    final newDay =
                        previous == null ||
                        !DateUtils.isSameDay(previous.time, message.time);

                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (newDay)
                          _DayHeader(label: _dayLabel(s, locale, message.time))
                        else
                          Divider(
                            height: 1,
                            thickness: 0.6,
                            indent: 58,
                            endIndent: 12,
                            color: theme.brightness == Brightness.dark
                                ? const Color(0xFF27272A)
                                : const Color(0xFFE7E5E4),
                          ),
                        // Swipe left to delete from this device.
                        Dismissible(
                          key: ValueKey('message-${message.id}'),
                          direction: DismissDirection.endToStart,
                          background: Container(
                            alignment: AlignmentDirectional.centerEnd,
                            padding: const EdgeInsetsDirectional.only(end: 24),
                            decoration: BoxDecoration(
                              color: theme.colorScheme.error,
                              borderRadius: BorderRadius.circular(16),
                            ),
                            child: const Icon(
                              Icons.delete_outline_rounded,
                              color: Colors.white,
                            ),
                          ),
                          onDismissed: (_) {
                            context.read<AppState>().deleteMessage(
                              widget.subscriptionId,
                              message.id,
                            );
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text(s.t('message_deleted'))),
                            );
                          },
                          // The message of a tapped notification is
                          // briefly tinted once brought into view.
                          child: AnimatedContainer(
                            key: message.id == (_focusId ?? _highlightId)
                                ? _focusKey
                                : null,
                            duration: const Duration(milliseconds: 400),
                            decoration: BoxDecoration(
                              color: message.id == _highlightId
                                  ? theme.colorScheme.primary.withValues(
                                      alpha: theme.brightness == Brightness.dark
                                          ? 0.16
                                          : 0.08,
                                    )
                                  : Colors.transparent,
                              borderRadius: BorderRadius.circular(16),
                            ),
                            child: _MessageCard(
                              message: message,
                              subscriptionId: widget.subscriptionId,
                            ),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }

  String _dayLabel(S s, String locale, DateTime time) {
    final today = DateUtils.dateOnly(DateTime.now());
    final day = DateUtils.dateOnly(time);
    final diff = today.difference(day).inDays;
    if (diff == 0) return s.t('today');
    if (diff == 1) return s.t('yesterday');
    final format = day.year == today.year
        ? DateFormat.MMMMEEEEd(locale)
        : DateFormat.yMMMMd(locale);
    final label = format.format(day);
    return label[0].toUpperCase() + label.substring(1);
  }

  Widget _menu(BuildContext context, Subscription subscription) {
    final s = S.of(context);

    return IconButton(
      icon: const Icon(Icons.more_horiz_rounded),
      onPressed: () async {
        final state = context.read<AppState>();
        final messenger = ScaffoldMessenger.of(context);
        final choice = await showActionSheet<String>(
          context,
          header: _SheetHeader(subscription: subscription),
          actions: [
            SheetAction(
              value: 'mute',
              label: subscription.muted ? s.t('unmute') : s.t('mute'),
              icon: subscription.muted
                  ? Icons.notifications_active_outlined
                  : Icons.notifications_off_outlined,
            ),
            SheetAction(
              value: 'copy',
              label: s.t('copy_topic'),
              icon: Icons.copy_rounded,
            ),
            if (state.messages(subscription.id).isNotEmpty)
              SheetAction(
                value: 'clear',
                label: s.t('clear_messages'),
                icon: Icons.delete_sweep_outlined,
                destructive: true,
              ),
            SheetAction(
              value: 'unsubscribe',
              label: s.t('unsubscribe'),
              icon: Icons.delete_outline_rounded,
              destructive: true,
            ),
          ],
        );

        switch (choice) {
          case 'mute':
            await state.toggleMute(subscription.id);
          case 'copy':
            await Clipboard.setData(ClipboardData(text: subscription.topic));
            messenger.showSnackBar(SnackBar(content: Text(s.t('copied'))));
          case 'clear':
            if (!context.mounted) return;
            final confirmed = await showConfirmSheet(
              context,
              title: s.t('clear_messages'),
              message: s.t('clear_messages_confirm', {
                'topic': subscription.displayName,
              }),
              confirmLabel: s.t('clear_messages'),
            );
            if (confirmed) {
              await state.clearMessages(subscription.id);
              messenger.showSnackBar(
                SnackBar(content: Text(s.t('messages_cleared'))),
              );
            }
          case 'unsubscribe':
            await _confirmUnsubscribe(subscription);
        }
      },
    );
  }
}

/// Topic shown at the top of its action sheet.
class _SheetHeader extends StatelessWidget {
  const _SheetHeader({required this.subscription});

  final Subscription subscription;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Row(
      children: [
        TopicAvatar(
          name: subscription.topic,
          label: subscription.displayName,
          isPublic: subscription.isPublic,
          iconUrl: subscription.iconUrl,
          size: 40,
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Flexible(
                    child: Text(
                      subscription.displayName,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  if (!subscription.isPublic) ...[
                    const SizedBox(width: 5),
                    const PrivateLock(size: 14),
                  ],
                ],
              ),
              Text(
                '#${subscription.topic}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Topic name, with "#topic · server" and the realtime state below.
class _Header extends StatelessWidget {
  const _Header({required this.subscription, required this.host});

  final Subscription subscription;
  final String? host;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final state = context.read<AppState>();

    return ValueListenableBuilder<Set<String>>(
      valueListenable: state.realtime.connected,
      builder: (context, connected, _) {
        final live = connected.contains(subscription.serverUrl);
        final details = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Flexible(
                  child: Text(
                    subscription.displayName,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      fontSize: 17,
                    ),
                  ),
                ),
                if (!subscription.isPublic) ...[
                  const SizedBox(width: 5),
                  const PrivateLock(size: 15),
                ],
              ],
            ),
            const SizedBox(height: 1),
            Row(
              children: [
                Container(
                  width: 7,
                  height: 7,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: live
                        ? const Color(0xFF10B981)
                        : theme.colorScheme.outline,
                  ),
                ),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    [
                      '#${subscription.topic}',
                      if (host != null && host!.isNotEmpty) host!,
                    ].join(' · '),
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                      fontWeight: FontWeight.w400,
                    ),
                  ),
                ),
              ],
            ),
          ],
        );

        return Row(
          children: [
            TopicAvatar(
              name: subscription.topic,
              label: subscription.displayName,
              isPublic: true,
              iconUrl: subscription.iconUrl,
              size: 36,
            ),
            const SizedBox(width: 10),
            Expanded(child: details),
          ],
        );
      },
    );
  }
}

class _PendingSummary extends StatelessWidget {
  const _PendingSummary({required this.count, required this.onTap});

  final int count;

  /// Brings the next alert waiting for an acknowledgement into view.
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;

    final accent = dark ? const Color(0xFFFBBF24) : const Color(0xFFB45309);

    return Semantics(
      button: true,
      hint: S.of(context).t('pending_show'),
      child: Material(
        color: const Color(0xFFF59E0B).withValues(alpha: dark ? 0.16 : 0.12),
        borderRadius: BorderRadius.circular(14),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () {
            HapticFeedback.selectionClick();
            onTap();
          },
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Row(
              children: [
                Icon(
                  Icons.notifications_active_rounded,
                  size: 18,
                  color: accent,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    count == 1
                        ? S.of(context).t('pending_ack_one')
                        : S.of(context).t('pending_acks', {'count': '$count'}),
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: dark
                          ? const Color(0xFFFDE68A)
                          : const Color(0xFF92400E),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Icon(Icons.arrow_downward_rounded, size: 18, color: accent),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 20, 12, 4),
      child: Text(
        label,
        style: theme.textTheme.labelLarge?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

/// Colors and icon of a priority.
({Color color, IconData icon}) _priorityStyle(
  int priority,
  ColorScheme scheme,
) => switch (priority) {
  >= 5 => (color: const Color(0xFFDC2626), icon: Icons.priority_high_rounded),
  4 => (color: const Color(0xFFD97706), icon: Icons.keyboard_arrow_up_rounded),
  <= 2 => (color: scheme.outline, icon: Icons.keyboard_arrow_down_rounded),
  _ => (color: scheme.primary, icon: Icons.notifications_none_rounded),
};

class _MessageCard extends StatelessWidget {
  const _MessageCard({required this.message, required this.subscriptionId});

  final TopicMessage message;
  final String subscriptionId;

  void _copy(BuildContext context) {
    final title = message.title?.isNotEmpty == true ? '${message.title}\n' : '';
    Clipboard.setData(ClipboardData(text: '$title${message.message}'));
    HapticFeedback.selectionClick();
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(S.of(context).t('message_copied')),
          duration: const Duration(seconds: 2),
        ),
      );
  }

  Future<void> _runAction(BuildContext context, MessageAction action) async {
    final messenger = ScaffoldMessenger.of(context);
    final s = S.of(context);
    final error = await ActionRunner().run(action);
    if (action.isHttp) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            error == null
                ? s.t('action_done', {'label': action.label})
                : s.t('action_failed', {'error': error}),
          ),
        ),
      );
    }
  }

  Future<void> _acknowledge(BuildContext context) async {
    final ack =
        message.actions.where((a) => a.ack).firstOrNull ??
        (message.ack?.url == null
            ? null
            : MessageAction(
                action: 'http',
                label: 'ack',
                url: message.ack!.url!,
                ack: true,
              ));
    if (ack == null) return;

    final messenger = ScaffoldMessenger.of(context);
    final s = S.of(context);
    HapticFeedback.lightImpact();
    // The card shows the progress; only a refusal needs a message.
    final error = await context.read<AppState>().acknowledge(
      subscriptionId,
      ack,
      messageId: message.id,
    );
    if (error != null) {
      messenger.showSnackBar(
        SnackBar(content: Text(s.t('ack_refused', {'error': error}))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;
    final locale = Localizations.localeOf(context).toString();
    final time = DateFormat.Hm(locale).format(message.time);
    final priority = _priorityStyle(message.priority, scheme);
    final attachment = message.attachment;
    final hasTitle = message.title?.isNotEmpty == true;
    final progress = context.select<AppState, AckProgress?>(
      (state) => state.ackProgress(subscriptionId, message.id),
    );
    final awaiting = message.awaitsAck && progress != AckProgress.done;
    final muted = scheme.onSurfaceVariant;

    // No frame: only alerts waiting for an acknowledgement get a tint.
    final card = Material(
      color: awaiting
          ? const Color(0xFFF59E0B).withValues(alpha: dark ? 0.12 : 0.09)
          : Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        // A tap copies the message; its link has its own button.
        onTap: () => _copy(context),
        onLongPress: () => _copy(context),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 14, 12, 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Semantics(
                label: s.t('priority_${message.priority.clamp(1, 5)}'),
                child: Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    color: priority.color.withValues(alpha: dark ? 0.2 : 0.1),
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: Icon(priority.icon, size: 20, color: priority.color),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Text(
                            hasTitle ? message.title! : message.message,
                            maxLines: hasTitle ? 2 : 6,
                            overflow: TextOverflow.ellipsis,
                            style: hasTitle
                                ? theme.textTheme.titleSmall?.copyWith(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 15,
                                    height: 1.3,
                                  )
                                : theme.textTheme.bodyMedium?.copyWith(
                                    fontSize: 15,
                                    height: 1.4,
                                  ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            time,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: muted,
                              fontFeatures: const [
                                FontFeature.tabularFigures(),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                    if (hasTitle) ...[
                      const SizedBox(height: 3),
                      Text(
                        message.message,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          height: 1.4,
                          color: scheme.onSurface.withValues(alpha: 0.78),
                        ),
                      ),
                    ],
                    if (message.tags.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        message.tags.map((t) => '#$t').join('  '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: muted,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                    if (message.occurrences > 1) ...[
                      const SizedBox(height: 8),
                      _Repeats(message: message, locale: locale),
                    ],
                    if (attachment != null) ...[
                      const SizedBox(height: 10),
                      _Attachment(attachment: attachment),
                    ],
                    if (message.regularActions.isNotEmpty ||
                        message.click != null) ...[
                      const SizedBox(height: 10),
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          if (message.click != null)
                            _ActionChip(
                              action: MessageAction(
                                action: 'view',
                                label: s.t('open_link'),
                                url: message.click!,
                              ),
                              onTap: () => launchUrl(
                                Uri.parse(message.click!),
                                mode: LaunchMode.externalApplication,
                              ),
                            ),
                          for (final action in message.regularActions)
                            _ActionChip(
                              action: action,
                              onTap: () => _runAction(context, action),
                            ),
                        ],
                      ),
                    ],
                    if (message.ack != null) ...[
                      const SizedBox(height: 10),
                      AnimatedSwitcher(
                        duration: const Duration(milliseconds: 200),
                        child: awaiting
                            ? _AckRequest(
                                key: ValueKey(progress),
                                reminders: message.ack!.reminders,
                                progress: progress,
                                onAcknowledge: () => _acknowledge(context),
                                onRetry: () =>
                                    context.read<AppState>().retryAcks(),
                              )
                            : _AckDone(
                                ack: message.awaitsAck
                                    ? const MessageAck(acknowledged: true)
                                    : message.ack!,
                                locale: locale,
                              ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );

    return card;
  }
}

/// "Received N times" of a grouped message, unfolding the time (and the
/// content, when it changed) of each send.
class _Repeats extends StatefulWidget {
  const _Repeats({required this.message, required this.locale});

  final TopicMessage message;
  final String locale;

  @override
  State<_Repeats> createState() => _RepeatsState();
}

class _RepeatsState extends State<_Repeats> {
  bool _open = false;

  String _time(DateTime time) {
    final now = DateTime.now();
    final today = DateUtils.isSameDay(time, now);
    return today
        ? DateFormat.Hms(widget.locale).format(time)
        : DateFormat('d MMM, HH:mm:ss', widget.locale).format(time);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;
    final message = widget.message;
    final repeats = message.repeats;
    final canOpen = repeats.isNotEmpty;
    final tabular = const [FontFeature.tabularFigures()];

    final chip = Container(
      padding: const EdgeInsets.fromLTRB(8, 3, 6, 3),
      decoration: BoxDecoration(
        color: scheme.onSurface,
        borderRadius: BorderRadius.circular(99),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.layers_rounded, size: 13, color: scheme.surface),
          const SizedBox(width: 4),
          Text(
            s.t('received_times', {'count': '${message.occurrences}'}),
            style: theme.textTheme.labelSmall?.copyWith(
              color: scheme.surface,
              fontWeight: FontWeight.w700,
              fontFeatures: tabular,
            ),
          ),
          if (canOpen) ...[
            const SizedBox(width: 2),
            AnimatedRotation(
              turns: _open ? 0.5 : 0,
              duration: const Duration(milliseconds: 200),
              child: Icon(
                Icons.expand_more_rounded,
                size: 16,
                color: scheme.surface,
              ),
            ),
          ] else
            const SizedBox(width: 2),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        canOpen
            ? GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTap: () => setState(() => _open = !_open),
                child: chip,
              )
            : chip,
        AnimatedSize(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
          alignment: Alignment.topLeft,
          child: !_open
              ? const SizedBox(width: double.infinity)
              : Container(
                  width: double.infinity,
                  margin: const EdgeInsets.only(top: 8),
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                  decoration: BoxDecoration(
                    color: dark
                        ? Colors.white.withValues(alpha: 0.04)
                        : Colors.black.withValues(alpha: 0.035),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.t('repeats_between', {
                          'count': '${message.occurrences}',
                          'first': _time(repeats.first.time),
                          'last': _time(repeats.last.time),
                        }),
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      if (message.occurrences > repeats.length)
                        Text(
                          s.t('repeats_latest', {'count': '${repeats.length}'}),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                      const SizedBox(height: 6),
                      for (final repeat in repeats.reversed)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _time(repeat.time),
                                style: theme.textTheme.bodySmall?.copyWith(
                                  fontWeight: FontWeight.w600,
                                  fontFeatures: tabular,
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  repeat.title == message.title &&
                                          repeat.message == message.message
                                      ? s.t('same_content')
                                      : [
                                          // The title only when it changed.
                                          if (repeat.title?.isNotEmpty ==
                                                  true &&
                                              repeat.title != message.title)
                                            repeat.title!,
                                          repeat.message,
                                        ].join(' — '),
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: scheme.onSurfaceVariant,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
        ),
      ],
    );
  }
}

class _Attachment extends StatelessWidget {
  const _Attachment({required this.attachment});

  final MessageAttachment attachment;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    void open() => launchUrl(
      Uri.parse(attachment.url),
      mode: LaunchMode.externalApplication,
    );

    if (attachment.isImage) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: GestureDetector(
          onTap: open,
          child: Image.network(
            attachment.url,
            fit: BoxFit.cover,
            height: 180,
            width: double.infinity,
            loadingBuilder: (context, child, progress) => progress == null
                ? child
                : Container(
                    height: 180,
                    color: theme.colorScheme.surfaceContainerHighest,
                  ),
            errorBuilder: (_, _, _) => const SizedBox.shrink(),
          ),
        ),
      );
    }

    return InkWell(
      onTap: open,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest.withValues(
            alpha: 0.5,
          ),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            Icon(
              Icons.description_outlined,
              size: 20,
              color: theme.colorScheme.onSurfaceVariant,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                attachment.name ?? S.of(context).t('attachment'),
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
            Icon(
              Icons.download_rounded,
              size: 18,
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ],
        ),
      ),
    );
  }
}

class _ActionChip extends StatelessWidget {
  const _ActionChip({required this.action, required this.onTap});

  final MessageAction action;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.6),
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                action.isHttp ? Icons.bolt_rounded : Icons.north_east_rounded,
                size: 15,
                color: theme.colorScheme.onSurface,
              ),
              const SizedBox(width: 5),
              Text(
                action.label,
                style: theme.textTheme.labelLarge?.copyWith(
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AckRequest extends StatelessWidget {
  const _AckRequest({
    super.key,
    required this.reminders,
    required this.progress,
    required this.onAcknowledge,
    required this.onRetry,
  });

  final int reminders;

  /// Null until the button is pressed; then sending or queued.
  final AckProgress? progress;
  final VoidCallback onAcknowledge;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final color = dark ? const Color(0xFFFBBF24) : const Color(0xFFB45309);
    final muted = theme.colorScheme.onSurfaceVariant;
    final textStyle = theme.textTheme.bodySmall?.copyWith(
      color: color,
      fontWeight: FontWeight.w600,
    );

    if (progress == AckProgress.sending) {
      return SizedBox(
        height: 36,
        child: Row(
          children: [
            SizedBox.square(
              dimension: 14,
              child: CircularProgressIndicator(strokeWidth: 2, color: muted),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                s.t('ack_sending'),
                style: textStyle?.copyWith(color: muted),
              ),
            ),
          ],
        ),
      );
    }

    if (progress == AckProgress.queued) {
      return Row(
        children: [
          Icon(Icons.cloud_off_rounded, size: 16, color: color),
          const SizedBox(width: 6),
          Expanded(child: Text(s.t('ack_queued'), style: textStyle)),
          const SizedBox(width: 8),
          TextButton(
            style: TextButton.styleFrom(
              minimumSize: const Size(0, 36),
              padding: const EdgeInsets.symmetric(horizontal: 12),
              foregroundColor: color,
              textStyle: const TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w600,
              ),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(10),
              ),
            ),
            onPressed: onRetry,
            child: Text(s.t('ack_retry')),
          ),
        ],
      );
    }

    return Row(
      children: [
        Icon(Icons.schedule_rounded, size: 16, color: color),
        const SizedBox(width: 6),
        Expanded(
          child: Text(switch (reminders) {
            0 => s.t('awaiting_ack'),
            1 => s.t('reminder_sent_one'),
            _ => s.t('reminders_sent', {'count': '$reminders'}),
          }, style: textStyle),
        ),
        const SizedBox(width: 8),
        // The theme's full-width minimum size cannot be used in a Row.
        FilledButton.icon(
          style: FilledButton.styleFrom(
            minimumSize: const Size(0, 36),
            padding: const EdgeInsets.symmetric(horizontal: 14),
            textStyle: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
            ),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: onAcknowledge,
          icon: const Icon(Icons.check_rounded, size: 17),
          label: Text(s.t('acknowledge')),
        ),
      ],
    );
  }
}

class _AckDone extends StatelessWidget {
  const _AckDone({required this.ack, required this.locale});

  final MessageAck ack;
  final String locale;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final color = dark ? const Color(0xFF34D399) : const Color(0xFF047857);
    final label = ack.resolved
        ? s.t('resolved_auto')
        : ack.by != null
        ? s.t('acknowledged_by', {'name': ack.by!})
        : s.t('acknowledged');

    return Row(
      children: [
        Icon(Icons.check_circle_rounded, size: 16, color: color),
        const SizedBox(width: 6),
        Flexible(
          child: Text(
            ack.at == null
                ? label
                : '$label · ${DateFormat.Hm(locale).format(ack.at!)}',
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall?.copyWith(
              color: color,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}

/// How to publish a first message: a curl command for this topic, copied
/// on tap.
class _SendExample extends StatefulWidget {
  const _SendExample({required this.subscription});

  final Subscription subscription;

  @override
  State<_SendExample> createState() => _SendExampleState();
}

class _SendExampleState extends State<_SendExample> {
  bool _copied = false;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final subscription = widget.subscription;
    final url = '${subscription.serverUrl}/${subscription.topic}';
    final command = [
      'curl',
      if (!subscription.isPublic)
        '-H "Authorization: Bearer ${s.t('token_placeholder')}"',
      '-d "${s.t('example_message')}"',
      url,
    ].join(' ');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 8),
          child: Text(
            s.t('send_example'),
            style: theme.textTheme.labelLarge?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        Material(
          color: dark ? const Color(0xFF1C1C1F) : Colors.white,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: () async {
              await Clipboard.setData(ClipboardData(text: command));
              if (!mounted) return;
              setState(() => _copied = true);
              Future.delayed(const Duration(seconds: 2), () {
                if (mounted) setState(() => _copied = false);
              });
            },
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 12, 14),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      '\$ $command',
                      style: TextStyle(
                        fontFamily: 'Menlo',
                        fontFamilyFallback: const ['monospace', 'Courier'],
                        fontSize: 13,
                        height: 1.45,
                        color: theme.colorScheme.onSurface,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Icon(
                    _copied ? Icons.check_rounded : Icons.copy_rounded,
                    size: 18,
                    color: _copied
                        ? const Color(0xFF10B981)
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 10),
        Text(
          subscription.isPublic
              ? s.t('send_example_hint')
              : s.t('send_example_private_hint'),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
            height: 1.4,
          ),
        ),
      ],
    );
  }
}
