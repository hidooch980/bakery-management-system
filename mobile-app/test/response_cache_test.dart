import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/local_database.dart';
import 'package:bakery_app/services/response_cache.dart';

/// Serves one answer, then refuses to connect — the shape of walking out of
/// signal partway through a shift.
class _GoesOfflineAdapter implements HttpClientAdapter {
  _GoesOfflineAdapter(this.body);

  final String body;
  bool offline = false;
  int calls = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    calls++;

    if (offline) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'no signal',
      );
    }

    return ResponseBody.fromString(
      body,
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Answers every request with a server-side refusal.
class _ForbiddenAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      '{"success":false,"message":"دسترسی ندارید"}',
      403,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

ApiClient _client(HttpClientAdapter adapter) {
  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(adapter);
  return client;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  group('cached reads', () {
    test('serves the last good answer once the signal goes', () async {
      final adapter = _GoesOfflineAdapter('{"success":true,"data":{"bags":7}}');
      final client = _client(adapter);

      final live = await client.getCached('/dough-entries/pending');
      expect((live['data'] as Map)['bags'], 7);
      expect(client.servedFrom('/dough-entries/pending'), isNull);

      adapter.offline = true;
      final cached = await client.getCached('/dough-entries/pending');

      // Same figures, and the app knows they are not fresh.
      expect((cached['data'] as Map)['bags'], 7);
      expect(client.servedFrom('/dough-entries/pending'), isNotNull);
    });

    test('a fresh answer stops the copy being reported as stale', () async {
      final adapter = _GoesOfflineAdapter('{"success":true,"data":{"bags":7}}');
      final client = _client(adapter);

      await client.getCached('/chane-board');
      adapter.offline = true;
      await client.getCached('/chane-board');
      expect(client.servedFrom('/chane-board'), isNotNull);

      adapter.offline = false;
      await client.getCached('/chane-board');

      expect(client.servedFrom('/chane-board'), isNull);
    });

    test('offline with nothing cached still fails, rather than lying',
        () async {
      final adapter = _GoesOfflineAdapter('{"success":true,"data":{}}')..offline = true;

      await expectLater(
        _client(adapter).getCached('/inventory'),
        throwsA(isA<ApiException>()),
      );
    });

    test('a refusal from the server is never answered from the cache',
        () async {
      final adapter = _GoesOfflineAdapter('{"success":true,"data":{"bags":7}}');
      final client = _client(adapter);

      await client.getCached('/bakery');

      // The server is reachable and says no. Serving yesterday's copy would
      // hide a real permission problem behind stale figures.
      final forbidden = _client(_ForbiddenAdapter());

      await expectLater(
        forbidden.getCached('/bakery'),
        throwsA(isA<ApiException>()),
      );
    });

    test('different queries are cached apart', () async {
      final cache = ResponseCache();

      await cache.save('/report', {'from': 'a'}, {'x': 1});
      await cache.save('/report', {'from': 'b'}, {'x': 2});

      expect((await cache.read('/report', {'from': 'a'}))?.body['x'], 1);
      expect((await cache.read('/report', {'from': 'b'}))?.body['x'], 2);
    });

    test('the same query written in another order is one entry', () async {
      final cache = ResponseCache();

      await cache.save('/report', {'from': 'a', 'to': 'b'}, {'x': 1});

      expect((await cache.read('/report', {'to': 'b', 'from': 'a'}))?.body['x'], 1);
    });

    test('a stale entry is not served', () async {
      final cache = ResponseCache();

      await cache.save('/chane-board', null, {'x': 1});

      // Back-dated well beyond the keeping window, which is the only way
      // to have yesterday's copy without waiting until tomorrow. Seeding
      // the old secure-storage key would pass without proving anything —
      // the cache does not read from there any more, so an empty answer
      // would look like a refused one.
      final db = await LocalDatabase().database;

      await db.update(
        'cached_reads',
        {
          'saved_at': DateTime.now()
              .subtract(ResponseCache.maxAge * 2)
              .toIso8601String(),
        },
        where: 'cache_key = ?',
        whereArgs: [ResponseCache.keyFor('/chane-board', null)],
      );

      expect(await cache.read('/chane-board', null), isNull);

      // And it is there to be had, for a caller that asks.
      expect(
        (await cache.read('/chane-board', null, allowStale: true))?.body['x'],
        1,
      );
    });

    test('signing out forgets what was on screen', () async {
      final adapter = _GoesOfflineAdapter('{"success":true,"data":{"bags":7}}');
      final client = _client(adapter);

      await client.getCached('/sales/today');
      await client.clearCache();

      adapter.offline = true;

      // The next person to hold this phone is shown nothing of the last.
      await expectLater(
        client.getCached('/sales/today'),
        throwsA(isA<ApiException>()),
      );
    });
  });

  group('the cache has a ceiling', () {
    test('it keeps the newest and drops what falls off the end', () async {
      final cache = ResponseCache();

      // Nothing ever removed an entry before this. Every distinct report
      // anybody opened stayed on the handset for the life of the install,
      // and only signing out cleared any of it — a leak with a friendly
      // name, on a phone whose storage the shop does not manage.
      for (var i = 0; i < ResponseCache.maxEntries + 20; i++) {
        await cache.save('/reports', {'month': '$i'}, {'n': i});
      }

      // The oldest are gone.
      expect(await cache.read('/reports', {'month': '0'}), isNull);

      // The newest are not.
      final newest = ResponseCache.maxEntries + 19;
      expect(
        (await cache.read('/reports', {'month': '$newest'}))?.body['n'],
        newest,
      );
    });

    test('re-reading a path does not add a second entry', () async {
      final cache = ResponseCache();

      await cache.save('/chane-board', null, {'x': 1});
      await cache.save('/chane-board', null, {'x': 2});

      // One answer per read, replaced. Otherwise the ceiling would be
      // reached by one screen somebody left open.
      expect((await cache.read('/chane-board', null))?.body['x'], 2);
    });

    test('signing out empties it', () async {
      final cache = ResponseCache();

      await cache.save('/sales/today', null, {'x': 1});
      await cache.clear();

      expect(await cache.read('/sales/today', null), isNull);
    });
  });
}
