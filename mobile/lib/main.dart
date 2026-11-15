import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'l10n/strings.dart';
import 'models/models.dart';
import 'screens/home_screen.dart';
import 'services/action_runner.dart';
import 'screens/topic_screen.dart';
import 'services/push_service.dart';
import 'state/app_state.dart';
import 'theme.dart';

final navigatorKey = GlobalKey<NavigatorState>();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final state = AppState();
  await state.init();

  // Tapping a notification opens its topic.
  PushService.instance.taps.listen((payload) => _openFromPush(state, payload));

  runApp(TocsinApp(state: state));

  final initial = await PushService.instance.initialNotification();
  if (initial != null) {
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => _openFromPush(state, initial),
    );
  }
}

void _openFromPush(AppState state, Map<String, dynamic> payload) {
  final server = payload['server'] as String?;
  final topic = payload['topic'] as String?;
  if (server == null || topic == null) return;

  // A button of the notification was tapped: run its action.
  final index = payload['actionIndex'];
  final actions = payload['actions'];
  if (index is int &&
      actions is List &&
      index < actions.length &&
      actions[index] is Map) {
    final action = MessageAction.fromJson(
      (actions[index] as Map).cast<String, dynamic>(),
    );
    final subscription = state.subscriptionFor(server, topic);
    if (action.ack && subscription != null) {
      // Signed in: the server records who acknowledged.
      unawaited(state.acknowledge(subscription.id, action));
    } else {
      unawaited(ActionRunner().run(action));
    }
    if (!action.isHttp) return;
  }

  final subscription = state.subscriptionFor(server, topic);
  if (subscription == null || state.openSubscriptionId == subscription.id) {
    return;
  }

  navigatorKey.currentState?.push(
    MaterialPageRoute(
      builder: (_) => TopicScreen(subscriptionId: subscription.id),
    ),
  );
}

class TocsinApp extends StatefulWidget {
  const TocsinApp({super.key, required this.state});

  final AppState state;

  @override
  State<TocsinApp> createState() => _TocsinAppState();
}

class _TocsinAppState extends State<TocsinApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState lifecycle) {
    if (lifecycle == AppLifecycleState.resumed) {
      unawaited(widget.state.onResumed());
    }
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider.value(
      value: widget.state,
      child: MaterialApp(
        title: 'Tocsin',
        navigatorKey: navigatorKey,
        debugShowCheckedModeBanner: false,
        theme: TocsinTheme.build(Brightness.light),
        darkTheme: TocsinTheme.build(Brightness.dark),
        supportedLocales: S.supportedLocales,
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: const HomeScreen(),
      ),
    );
  }
}
