import 'package:flutter/foundation.dart';

import '../models/models.dart';
import 'api_client.dart';
import 'storage.dart';

/// Fetches the messages published since the last known one, so that the
/// history shown in the app is complete. Notifications themselves are
/// delivered by Apple Push Notification service.
class SyncService {
  SyncService(this.storage);

  final Storage storage;

  /// Returns the number of new messages.
  Future<int> syncAll({bool countAsUnread = true}) async {
    final subscriptions = await storage.loadSubscriptions();
    var total = 0;

    for (final subscription in subscriptions) {
      try {
        total += (await syncOne(
          subscription,
          countAsUnread: countAsUnread,
        )).length;
      } on ApiException catch (e) {
        debugPrint('[sync] ${subscription.id}: $e');
      }
    }

    await storage.saveSubscriptions(subscriptions);
    return total;
  }

  /// Fetches new messages of one subscription, stores them and updates the
  /// subscription in place. Returns the new messages, newest first.
  ///
  /// With [recent], the latest messages are fetched again too, to update
  /// their grouping counter and acknowledgement.
  Future<List<TopicMessage>> syncOne(
    Subscription subscription, {
    bool countAsUnread = true,
    bool recent = false,
  }) async {
    final api = ApiClient(
      subscription.serverUrl,
      token: await storage.token(subscription.serverUrl),
    );
    final messages = await api.messages(
      subscription.topic,
      since: recent ? null : subscription.lastMessageId,
      limit: recent ? 50 : 100,
    );
    final fresh = await storage.addMessages(subscription.id, messages);

    for (final message in fresh.reversed) {
      subscription.absorb(message, countAsUnread: countAsUnread);
    }

    return fresh;
  }
}
