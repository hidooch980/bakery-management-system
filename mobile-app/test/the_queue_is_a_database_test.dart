import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:bakery_app/services/offline_queue.dart';

/// The offline queue, moved off a JSON string and onto rows.
///
/// The old shape kept the whole queue as one string in secure storage:
/// every enqueue rewrote all of it, and «how many are waiting» — which the
/// home screen asks on every build — re-parsed all of it. That is fine at
/// three entries and the wrong shape at three hundred, which is what a day
/// off the network looks like.
///
/// The half worth testing hardest is the upgrade. A handset that installs
/// this version while holding unsent sales must arrive with them. Dropping
/// them would be exactly the failure the queue exists to prevent, and it
/// would be silent: the queue would simply be empty, and the sales were
/// never written down anywhere else.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late OfflineQueue queue;

  /// The database comes from `test/flutter_test_config.dart`: one
  /// in-memory database, shared by every instance within a test the way
  /// the file is on a handset, and dropped between tests. Unencrypted,
  /// deliberately — what these prove is the SQL, and the encryption is a
  /// property of the file rather than of the statements run against it.
  OfflineQueue queueOn() => OfflineQueue();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    queue = queueOn();
  });

  QueuedRequest entry(String id, {String label = 'ثبت فروش', int minute = 0}) =>
      QueuedRequest(
        id: id,
        path: '/sales',
        body: {'amount': 50000, 'n': id},
        label: label,
        createdAt: DateTime(2026, 8, 12, 9, minute),
      );

  group('holding what could not be sent', () {
    test('an entry survives being written and read back whole', () async {
      await queue.enqueue(entry('a', label: 'ثبت فروش — نان بربری'));

      final held = await queue.all();

      expect(held, hasLength(1));
      expect(held.single.id, 'a');
      expect(held.single.path, '/sales');
      expect(held.single.label, 'ثبت فروش — نان بربری');
      expect(held.single.body['amount'], 50000);
      expect(held.single.createdAt, DateTime(2026, 8, 12, 9, 0));
    });

    test('they come back in the order they happened', () async {
      // Sending order is not a nicety: a settlement recorded after a sale
      // has to reach the server after it.
      await queue.enqueue(entry('third', minute: 30));
      await queue.enqueue(entry('first', minute: 10));
      await queue.enqueue(entry('second', minute: 20));

      expect(
        (await queue.all()).map((r) => r.id),
        ['first', 'second', 'third'],
      );
    });

    test('the same write twice is one entry', () async {
      // The id is the Idempotency-Key. Two rows under one name would be
      // one sale sent twice.
      await queue.enqueue(entry('a'));
      await queue.enqueue(entry('a'));

      expect(await queue.count(), 1);
    });

    test('counting does not depend on reading everything', () async {
      for (var i = 0; i < 50; i++) {
        await queue.enqueue(entry('id-$i', minute: i));
      }

      expect(await queue.count(), 50);
    });

    test('sending one leaves the rest', () async {
      await queue.enqueue(entry('a', minute: 1));
      await queue.enqueue(entry('b', minute: 2));

      await queue.remove('a');

      expect((await queue.all()).map((r) => r.id), ['b']);
    });
  });

  group('what the server refused', () {
    test('it moves across in one piece, with the reason', () async {
      final refused = entry('a', label: 'ثبت فروش — مدرسه');
      await queue.enqueue(refused);

      await queue.reject(refused, 'این مشتری دیگر فعال نیست.');

      expect(await queue.count(), 0);
      expect(await queue.rejectedCount(), 1);

      final kept = (await queue.rejected()).single;

      // What the seller typed is not the server's to throw away, and the
      // reason is what lets them see why.
      expect(kept.reason, 'این مشتری دیگر فعال نیست.');
      expect(kept.request.label, 'ثبت فروش — مدرسه');
      expect(kept.request.body['amount'], 50000);
    });

    test('it leaves the queue and arrives, or neither', () async {
      final refused = entry('a');
      await queue.enqueue(refused);
      await queue.reject(refused, 'خطا');

      // These were two separate writes before. An entry that left the
      // queue without arriving in the refused list is a sale that vanished
      // with nothing said about it.
      expect(await queue.count(), 0);
      expect(await queue.rejectedCount(), 1);
    });

    test('dismissing one is the person having seen it', () async {
      final refused = entry('a');
      await queue.enqueue(refused);
      await queue.reject(refused, 'خطا');

      await queue.dismissRejected('a');

      expect(await queue.rejectedCount(), 0);
    });
  });

  group('upgrading a phone that still had sales on it', () {
    String legacy(List<Map<String, Object?>> rows) => jsonEncode(rows);

    test('unsent sales are carried over rather than lost', () async {
      FlutterSecureStorage.setMockInitialValues({
        'offline_queue_v2': legacy([
          {
            'id': 'old-1',
            'path': '/sales',
            'body': {'amount': 120000},
            'label': 'ثبت فروش — ۱۲۰۰۰ تومان',
            'created_at': '2026-08-12T09:00:00.000',
          },
        ]),
      });

      final held = await queueOn().all();

      expect(held, hasLength(1));
      expect(held.single.id, 'old-1');
      expect(held.single.body['amount'], 120000);
      // The name has to survive too: it is the Idempotency-Key, so a
      // carried-over write that reaches a server which already has it is
      // recognised rather than recorded a second time.
      expect(held.single.label, 'ثبت فروش — ۱۲۰۰۰ تومان');
    });

    test('refused entries are carried over with their reasons', () async {
      FlutterSecureStorage.setMockInitialValues({
        'offline_rejected_v2': legacy([
          {
            'request': {
              'id': 'old-2',
              'path': '/sales',
              'body': {'amount': 1},
              'label': 'ثبت فروش',
              'created_at': '2026-08-12T09:00:00.000',
            },
            'reason': 'مشتری پیدا نشد.',
          },
        ]),
      });

      final kept = (await queueOn().rejected()).single;

      expect(kept.request.id, 'old-2');
      expect(kept.reason, 'مشتری پیدا نشد.');
    });

    test('the old copy is dropped once the rows are committed', () async {
      FlutterSecureStorage.setMockInitialValues({
        'offline_queue_v2': legacy([
          {
            'id': 'old-1',
            'path': '/sales',
            'body': {'amount': 1},
            'label': '',
            'created_at': '2026-08-12T09:00:00.000',
          },
        ]),
      });

      final q = queueOn();
      await q.all();

      // Left behind, it would be re-imported by the next install and the
      // plaintext-adjacent copy would sit there for ever.
      expect(
        await const FlutterSecureStorage().read(key: 'offline_queue_v2'),
        isNull,
      );
    });

    test('an unreadable old queue does not stop the shop selling', () async {
      FlutterSecureStorage.setMockInitialValues({
        'offline_queue_v2': 'not json at all',
      });

      final q = queueOn();

      // The old queue tolerated this for the same reason: whatever is
      // wrong with yesterday must not block recording today.
      await q.enqueue(entry('new'));

      expect((await q.all()).map((r) => r.id), ['new']);

      // And it is kept rather than deleted, so somebody can look at it.
      expect(
        await const FlutterSecureStorage().read(key: 'offline_queue_v2'),
        'not json at all',
      );
    });

    test('a carried-over id already present is not duplicated', () async {
      FlutterSecureStorage.setMockInitialValues({
        'offline_queue_v2': legacy([
          {
            'id': 'a',
            'path': '/sales',
            'body': {'amount': 1},
            'label': '',
            'created_at': '2026-08-12T09:00:00.000',
          },
        ]),
      });

      final q = queueOn();
      await q.enqueue(entry('a'));

      expect(await q.count(), 1);
    });
  });
}
