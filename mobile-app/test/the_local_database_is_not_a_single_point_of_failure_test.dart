import 'dart:io';

import 'package:bakery_app/services/local_database.dart';
import 'package:bakery_app/services/offline_queue.dart';
import 'package:bakery_app/services/response_cache.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// What the owner actually saw, and what it cost.
///
///   DatabaseException(open_failed /data/user/0/…/bakery_local.db)
///
/// over the whole of «امروز», with a full signal. Two mistakes, and one
/// of them hid the other:
///
///   - Android does not create the app's `databases/` directory, so on a
///     fresh install the database never opened at all. Every test ran
///     against an in-memory database, where there is no directory to be
///     missing, so nothing said so.
///   - Nothing was allowed to fail. `ResponseCache.read` had been guarded
///     since it was written and `save` had not, so a *successful* fetch
///     threw while filing its copy. And the queue — the one thing in this
///     app that must never lose what a seller typed — went straight to
///     the database on every call.
///
/// These tests hold both: the file opens in a directory that does not
/// exist yet, and everything above it keeps working when it cannot.
class _NoDatabase implements DatabaseFactory {
  /// The handset's own failure, in the shape it arrives in.
  @override
  Future<Database> openDatabase(String path, {OpenDatabaseOptions? options}) =>
      Future.error(StateError('open_failed $path'));

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();

  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  group('the file is made in a directory that is not there yet', () {
    late Directory root;

    setUp(() => root = Directory.systemTemp.createTempSync('bakery-db-test'));
    tearDown(() => root.deleteSync(recursive: true));

    test('a fresh install opens rather than failing with open_failed', () async {
      // The shape of the handset's own path: a `databases` folder that no
      // one has created yet, inside a directory that exists.
      final path = p.join(root.path, 'databases', 'bakery_local.db');

      expect(Directory(p.dirname(path)).existsSync(), isFalse);

      final database = LocalDatabase(
        factory: databaseFactoryFfiNoIsolate,
        path: path,
      );

      final db = await database.database;

      expect(File(path).existsSync(), isTrue);

      // Openable is not the point; usable is.
      await db.insert('queued_writes', {
        'id': 'a',
        'path': '/sales',
        'body': '{}',
        'created_at': DateTime.now().toIso8601String(),
      });

      expect(
        (await db.query('queued_writes')).single['id'],
        'a',
      );

      await database.close();
    });
  });

  group('when the database will not open at all', () {
    late LocalDatabase broken;

    setUp(() => broken = LocalDatabase(factory: _NoDatabase()));

    test('a sale is still held rather than lost', () async {
      final queue = OfflineQueue(database: broken);

      await queue.enqueue(QueuedRequest(
        id: 'sale-1',
        path: '/sales',
        body: {'amount': 500000},
        label: 'فروش — چانه #7',
        createdAt: DateTime.now(),
      ));

      expect(await queue.count(), 1);

      final held = await queue.all().then((q) => q.single);

      expect(held.body['amount'], 500000);
      expect(held.label, 'فروش — چانه #7');
    });

    test('the same write twice is still one entry', () async {
      final queue = OfflineQueue(database: broken);

      for (var i = 0; i < 2; i++) {
        await queue.enqueue(QueuedRequest(
          id: 'sale-1',
          path: '/sales',
          body: const {},
          label: '',
          createdAt: DateTime.now(),
        ));
      }

      expect(await queue.count(), 1);
    });

    test('a sent entry leaves, and a refused one is kept with its reason',
        () async {
      final queue = OfflineQueue(database: broken);

      QueuedRequest entry(String id) => QueuedRequest(
            id: id,
            path: '/sales',
            body: const {},
            label: '',
            createdAt: DateTime.now(),
          );

      await queue.enqueue(entry('sent'));
      await queue.enqueue(entry('refused'));

      await queue.remove('sent');
      await queue.reject(entry('refused'), 'چانه‌ای با این شماره نیست.');

      expect(await queue.count(), 0);
      expect(await queue.rejectedCount(), 1);
      expect(
        (await queue.rejected()).single.reason,
        'چانه‌ای با این شماره نیست.',
      );

      await queue.dismissRejected('refused');

      expect(await queue.rejectedCount(), 0);
    });

    test('what the fallback holds is picked up once the database opens',
        () async {
      // The fallback writes where the queue lived before this release, so
      // the carry-over that already runs on upgrade finds it — a handset
      // that recorded sales while broken does not keep them there.
      await OfflineQueue(database: broken).enqueue(QueuedRequest(
        id: 'sale-1',
        path: '/sales',
        body: const {'amount': 90},
        label: 'فروش',
        createdAt: DateTime.now(),
      ));

      final working = LocalDatabase(factory: databaseFactoryFfiNoIsolate);
      final queue = OfflineQueue(database: working);

      expect(await queue.count(), 1);
      expect((await queue.all()).single.body['amount'], 90);

      await working.close();
    });

    test('a read that succeeded is not failed by filing its copy', () async {
      final cache = ResponseCache(database: broken);

      // Both directions: neither may throw, and a cache that cannot be
      // written simply has nothing to read back.
      await cache.save('/today', null, {'system': 'مغازه امروز سالم است.'});

      expect(await cache.read('/today', null), isNull);

      await cache.clear();
    });
  });
}
