import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tocsin/models/models.dart';
import 'package:tocsin/services/storage.dart';

TopicMessage message(int id) => TopicMessage(
  id: id,
  topic: 'alerts',
  message: 'message $id',
  priority: 3,
  tags: const [],
  time: DateTime(2026, 11, 15, 12, id),
);

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('deleted and cleared messages do not come back on sync', () async {
    final storage = Storage();
    await storage.addMessages('s1', [message(1), message(2), message(3)]);

    // Deleted one by one, then the whole topic cleared up to 2.
    await storage.hideMessages('s1', ids: [3]);
    await storage.hideMessages('s1', upTo: 2);
    await storage.replaceMessages('s1', const []);

    // A sync brings the latest messages again, plus a new one.
    final fresh = await storage.addMessages('s1', [
      message(1),
      message(2),
      message(3),
      message(4),
    ]);
    expect(fresh.map((m) => m.id), [4]);
    expect((await storage.loadMessages('s1')).map((m) => m.id), [4]);

    // Unsubscribing forgets it all.
    await storage.deleteMessages('s1');
    expect((await storage.addMessages('s1', [message(1)])).map((m) => m.id), [
      1,
    ]);
  });
}
