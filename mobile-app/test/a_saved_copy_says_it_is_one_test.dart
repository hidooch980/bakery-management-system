import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:flutter/material.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/local_database.dart';
import 'package:bakery_app/services/response_cache.dart';
import 'package:bakery_app/utils/formatters.dart';
import 'package:bakery_app/widgets/saved_copy_banner.dart';

/// The read half of working without signal.
///
/// Writing has queued since the queue was written. Reading has been served
/// from a saved copy for just as long — and said nothing about it, because
/// `servedFrom` existed and no screen ever called it. A bank balance from
/// last night, shown at noon with no mark on it, is not the same fact as a
/// bank balance.
///
/// The other half is which failures reach the cache at all — a socket
/// error raised inside the adapter, rather than a tidy connection refusal,
/// is what a phone with no data actually produces.
class _Adapter implements HttpClientAdapter {
  _Adapter(this.body);

  final String body;

  /// What the wire does next: null answers normally, anything else is
  /// thrown.
  Object? failWith;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (failWith != null) throw failWith!;

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

  group('a lost connection, in the shape a real phone produces it', () {
    test('a raw socket failure still reaches the saved copy', () async {
      final wire = _Adapter('{"success":true,"data":{"balance":900}}');
      final client = _client(wire);

      await client.getCached('/bank-accounts');

      // Not a tidy `connectionError`: this is «No address associated with
      // hostname», which is what an Android phone with no data gives, and
      // which arrives classified as `unknown`. It has been mistaken for a
      // server refusal in this codebase before.
      wire.failWith = const SocketException('No address associated');

      final cached = await client.getCached('/bank-accounts');

      expect((cached['data'] as Map)['balance'], 900);
      expect(client.savedCopyAt.value, isNotNull);
    });

    test('with nothing saved it still fails rather than inventing one',
        () async {
      final wire = _Adapter('{"success":true,"data":{}}')
        ..failWith = const SocketException('No address associated');

      // An empty board presented as today's is the one answer worse than
      // an error.
      await expectLater(
        _client(wire).getCached('/chane-board'),
        throwsA(isA<ApiException>()
            .having((e) => e.isConnectivityError, 'is a connection failure',
                isTrue)),
      );
    });
  });

  group('saying that a copy is a copy', () {
    test('it is announced when served and withdrawn when live', () async {
      final wire = _Adapter('{"success":true,"data":{"bags":7}}');
      final client = _client(wire);

      await client.getCached('/dough-entries/pending');
      expect(client.savedCopyAt.value, isNull);

      wire.failWith = DioException.connectionError(
        requestOptions: RequestOptions(path: '/dough-entries/pending'),
        reason: 'no signal',
      );
      await client.getCached('/dough-entries/pending');
      expect(client.savedCopyAt.value, isNotNull);

      wire.failWith = null;
      await client.getCached('/dough-entries/pending');
      expect(client.savedCopyAt.value, isNull);
    });

    test('signing out withdraws it too', () async {
      final wire = _Adapter('{"success":true,"data":{"bags":7}}');
      final client = _client(wire);

      await client.getCached('/dough-entries/pending');
      wire.failWith = DioException.connectionError(
        requestOptions: RequestOptions(path: '/x'),
        reason: 'no signal',
      );
      await client.getCached('/dough-entries/pending');

      await client.clearCache();

      // The next person to hold this phone must not be told the figures
      // they cannot see are merely old.
      expect(client.savedCopyAt.value, isNull);
    });
  });

  group('two reads of the same path are two answers', () {
    test('a live month does not clear the mark from a saved one', () async {
      final wire = _Adapter('{"success":true,"data":{"total":10}}');
      final client = _client(wire);

      const mordad = {'month': '1405-05'};
      const shahrivar = {'month': '1405-06'};

      await client.getCached('/reports', query: mordad);
      await client.getCached('/reports', query: shahrivar);

      // Mordad goes stale while Shahrivar is still live.
      wire.failWith = DioException.connectionError(
        requestOptions: RequestOptions(path: '/reports'),
        reason: 'no signal',
      );
      await client.getCached('/reports', query: mordad);

      expect(client.servedFrom('/reports', query: mordad), isNotNull);

      wire.failWith = null;
      await client.getCached('/reports', query: shahrivar);

      // Keyed by path alone, this fetch wiped Mordad's mark — while
      // Mordad's saved figures were still on the screen.
      expect(client.servedFrom('/reports', query: mordad), isNotNull);
      expect(client.servedFrom('/reports', query: shahrivar), isNull);
    });
  });

  group('what the shop is actually shown', () {
    testWidgets('nothing at all while the figures are live', (tester) async {
      final client = _client(_Adapter('{"success":true,"data":{}}'));

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(body: SavedCopyBanner(client: client)),
      ));

      expect(find.byType(Card), findsNothing);
    });

    testWidgets('the time the copy was taken, once one is served',
        (tester) async {
      final client = _client(_Adapter('{"success":true,"data":{}}'));

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(body: SavedCopyBanner(client: client)),
      ));

      // Set rather than driven through a real fetch: what the tests above
      // prove is when the mark goes up, and this one is about what the
      // shop reads when it has.
      //
      // Relative to now, not a fixed date. A written-out «۱۴۰۵/۰۶/۱۲ —
      // ۲۱:۴۰» is inside the keeping window or past it depending on what
      // time the suite runs, so the banner said «ذخیره‌شده» here and
      // «کهنه» on CI four hours later. The test was reading the clock and
      // calling it a result.
      final takenAt = DateTime.now().subtract(const Duration(hours: 1));

      client.savedCopyAt.value = takenAt;
      await tester.pump();

      // The hour, not «قدیمی». Whether a figure from an hour ago is usable
      // depends on which figure it is, and the person reading it is the
      // one who knows.
      expect(find.textContaining('نسخهٔ ذخیره‌شده'), findsOneWidget);
      expect(find.textContaining(JalaliFormat.time(takenAt)), findsOneWidget);
    });
  });

  group('a copy older than the keeping window', () {
    /// Saves an answer and then back-dates it, which is the only way to
    /// have a copy from yesterday without waiting until tomorrow.
    Future<void> saveAsOldAsYesterday(String path) async {
      await ResponseCache().save(path, null, {
        'success': true,
        'data': {'bags': 7},
      });

      final db = await LocalDatabase().database;

      await db.update(
        'cached_reads',
        {
          'saved_at': DateTime.now()
              .subtract(ResponseCache.maxAge * 2)
              .toIso8601String(),
        },
        where: 'cache_key = ?',
        whereArgs: [ResponseCache.keyFor(path, null)],
      );
    }

    test('is served when the server cannot be reached', () async {
      await saveAsOldAsYesterday('/chane-board');

      final wire = _Adapter('{}')
        ..failWith = DioException.connectionError(
          requestOptions: RequestOptions(path: '/chane-board'),
          reason: 'no signal',
        );

      // The shop lost signal at closing and opened the app the next
      // morning. The figures were sitting in storage the whole time and
      // the screen showed an error, because the cache refused to hand over
      // anything past twelve hours.
      final body = await _client(wire).getCached('/chane-board');

      expect((body['data'] as Map)['bags'], 7);
    });

    test('is still refused to a caller that has not asked for it', () async {
      await saveAsOldAsYesterday('/chane-board');

      // The default stays «no». Only the offline arm asks for stale, where
      // the alternative is a blank screen rather than a fresh answer.
      expect(await ResponseCache().read('/chane-board', null), isNull);
    });

    testWidgets('is called old on the screen, not merely saved',
        (tester) async {
      final client = _client(_Adapter('{}'));

      await tester.pumpWidget(MaterialApp(
        home: Scaffold(body: SavedCopyBanner(client: client)),
      ));

      client.savedCopyAt.value =
          DateTime.now().subtract(ResponseCache.maxAge * 2);
      await tester.pump();

      // Figures from another day's trading and figures from an hour ago
      // are both «not live», and reading them as the same thing is what
      // the twelve-hour cut-off was trying to prevent. Saying which is
      // the cheaper way to prevent it.
      expect(find.textContaining('کهنه'), findsOneWidget);
    });
  });
}
