import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/offline_queue.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late OfflineQueue queue;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    queue = OfflineQueue();
  });

  QueuedRequest sample({String id = '1'}) => QueuedRequest(
        id: id,
        path: '/dough-entries',
        body: const {'bag_count': 10},
        label: 'خمیر — 10 کیسه',
        createdAt: DateTime(2026, 5, 4, 6, 30),
      );

  group('OfflineQueue', () {
    test('starts empty', () async {
      expect(await queue.all(), isEmpty);
      expect(await queue.count(), 0);
    });

    test('keeps an entry that was enqueued', () async {
      await queue.enqueue(sample());

      final items = await queue.all();

      expect(items, hasLength(1));
      expect(items.first.path, '/dough-entries');
      expect(items.first.body, {'bag_count': 10});
      expect(items.first.label, 'خمیر — 10 کیسه');
    });

    test('preserves the order entries were recorded in', () async {
      await queue.enqueue(sample(id: '1'));
      await queue.enqueue(sample(id: '2'));
      await queue.enqueue(sample(id: '3'));

      final ids = (await queue.all()).map((r) => r.id).toList();

      expect(ids, ['1', '2', '3']);
    });

    test('removing one entry leaves the others untouched', () async {
      await queue.enqueue(sample(id: '1'));
      await queue.enqueue(sample(id: '2'));

      await queue.remove('1');

      final ids = (await queue.all()).map((r) => r.id).toList();
      expect(ids, ['2']);
    });

    test('removing an id that is not queued is a no-op', () async {
      await queue.enqueue(sample(id: '1'));

      await queue.remove('does-not-exist');

      expect(await queue.count(), 1);
    });

    test('a queue built fresh after enqueueing still sees the entry', () async {
      // Simulates the app being closed and reopened: nothing but
      // SharedPreferences survives, so a brand new OfflineQueue instance
      // must still find what was there before.
      await queue.enqueue(sample());

      final reopened = OfflineQueue();
      expect(await reopened.count(), 1);
    });

    test('round-trips a body with nested and null values', () async {
      await queue.enqueue(QueuedRequest(
        id: '1',
        path: '/sales',
        body: const {
          'chane_entry_id': 5,
          'customer_id': null,
          'amount': 12000.5,
        },
        label: 'فروش',
        createdAt: DateTime(2026, 5, 4),
      ));

      final body = (await queue.all()).first.body;

      expect(body['chane_entry_id'], 5);
      expect(body['customer_id'], isNull);
      expect(body['amount'], 12000.5);
    });
  });

  group('a write the server refused', () {
    test('is kept rather than deleted, with the reason', () async {
      await queue.enqueue(sample());

      await queue.reject(sample(), 'موجودی آرد کافی نیست.');

      // Out of the queue, so it is never retried — the server would only
      // refuse it again — but not gone. What a person typed and the shop
      // lost used to leave nothing behind but a counter nothing showed.
      expect(await queue.count(), 0);

      final refused = await queue.rejected();
      expect(refused, hasLength(1));
      expect(refused.single.reason, 'موجودی آرد کافی نیست.');
      expect(refused.single.request.label, 'خمیر — 10 کیسه');
    });

    test('survives a queue built fresh, like the rest of the queue does',
        () async {
      await queue.enqueue(sample());
      await queue.reject(sample(), 'دلیل');

      // Refused at closing time, read the next morning on a phone that
      // has been restarted since.
      expect(await OfflineQueue().rejectedCount(), 1);
    });

    test('is dismissed only by name, leaving the others', () async {
      await queue.reject(sample(id: 'a'), 'یک');
      await queue.reject(sample(id: 'b'), 'دو');

      await queue.dismissRejected('a');

      final left = await queue.rejected();
      expect(left, hasLength(1));
      expect(left.single.request.id, 'b');
    });

    test('dismissing something that is not there changes nothing', () async {
      await queue.reject(sample(id: 'a'), 'یک');

      await queue.dismissRejected('nope');

      expect(await queue.rejectedCount(), 1);
    });

    test('the refused list and the queue do not read each other', () async {
      await queue.enqueue(sample(id: 'waiting'));
      await queue.reject(sample(id: 'refused'), 'دلیل');

      // Two different questions — «what is still going to be sent» and
      // «what will never be sent» — and a card that mixed them would tell
      // the shop to wait for something that is not coming.
      expect((await queue.all()).single.id, 'waiting');
      expect((await queue.rejected()).single.request.id, 'refused');
    });
  });
}
