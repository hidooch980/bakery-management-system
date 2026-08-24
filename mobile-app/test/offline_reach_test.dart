import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/offline_queue.dart';

/// The queue is only as useful as the set of actions that reach it. Money
/// collected at a customer's door, a call recorded on the round and a
/// follow-up closed out all used to fail outright without signal — the
/// seller was told the request failed and had to remember it themselves,
/// which is exactly how a collection goes unrecorded.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late OfflineQueue queue;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    queue = OfflineQueue();
  });

  QueuedRequest entry(String id, String path, Map<String, dynamic> body, String label) =>
      QueuedRequest(
        id: id,
        path: path,
        body: body,
        label: label,
        createdAt: DateTime(2026, 8, 12, 9, 0),
      );

  group('what survives a shift with no signal', () {
    test('a collection taken at the door', () async {
      await queue.enqueue(entry(
        '1',
        '/my-collections/7/collect',
        {'amount': 500000, 'method': 'cash'},
        'وصولی از مشتری',
      ));

      final held = await queue.all();

      expect(held, hasLength(1));
      expect(held.first.path, '/my-collections/7/collect');
      expect(held.first.body['amount'], 500000);
    });

    test('a call recorded on the round', () async {
      await queue.enqueue(entry(
        '2',
        '/customers/3/interactions',
        {'type': 'call', 'summary': 'قرار شد جمعه تسویه کند'},
        'ثبت تماس با مشتری',
      ));

      expect((await queue.all()).first.label, 'ثبت تماس با مشتری');
    });

    test('a follow-up closed out', () async {
      await queue.enqueue(entry('3', '/interactions/9/complete', const {}, 'تکمیل پیگیری'));

      expect(await queue.count(), 1);
    });

    test('a whole round of work keeps its order', () async {
      // Order matters: a collection recorded before a follow-up was closed
      // has to reach the server that way round, or the follow-up closes
      // against a debt the server does not know was paid.
      await queue.enqueue(entry('1', '/my-collections/7/collect', {'amount': 100}, 'وصولی'));
      await queue.enqueue(entry('2', '/customers/3/interactions', {'type': 'call'}, 'تماس'));
      await queue.enqueue(entry('3', '/interactions/9/complete', const {}, 'پیگیری'));

      final held = await queue.all();

      expect(held.map((e) => e.id).toList(), ['1', '2', '3']);
      expect(await queue.count(), 3);
    });

    test('sending one leaves the rest waiting', () async {
      await queue.enqueue(entry('1', '/my-collections/7/collect', {'amount': 100}, 'وصولی'));
      await queue.enqueue(entry('2', '/interactions/9/complete', const {}, 'پیگیری'));

      await queue.remove('1');

      final held = await queue.all();
      expect(held, hasLength(1));
      expect(held.first.id, '2');
    });
  });
}
