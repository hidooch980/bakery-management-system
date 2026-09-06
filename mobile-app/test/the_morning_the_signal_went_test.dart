import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:bakery_app/models/user.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/services/cache_warmer.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The whole morning, in order — not the pieces of it.
///
/// Every part of working without signal has had its own test for a while:
/// the queue, the cache, the warmer, the database. All of them were green
/// on 4.88, and on a real handset the app did not work at all. The pieces
/// were right and the sequence was never run.
///
/// So this is the sequence, and it is the one the owner is asked to walk
/// by hand each release: sign in with signal, let the cache fill, lose the
/// radio, read a screen, record a cost, get the radio back, and see the
/// cost reach the server. If this passes, that test is a formality; if it
/// fails, no amount of green elsewhere means the shop can work.
class _Wire implements HttpClientAdapter {
  final asked = <String>[];
  final posted = <({String path, String? key, Map<String, dynamic> body})>[];

  bool down = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (down) {
      // What a phone with no data actually produces, rather than a tidy
      // refusal: on Android a DNS failure arrives as an unknown error
      // wrapping a SocketException.
      throw const SocketException('No address associated with hostname');
    }

    asked.add(options.path);

    if (options.method == 'POST' && options.path != '/login') {
      posted.add((
        path: options.path,
        key: options.headers['Idempotency-Key'] as String?,
        body: (options.data as Map).cast<String, dynamic>(),
      ));
    }

    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': _dataFor(options.path)}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  Object _dataFor(String path) => switch (path) {
        '/login' => {
            'token': 'a-token',
            'user': {'id': 1, 'name': 'عبدالناصر', 'roles': ['admin']},
          },
        '/expenses/categories' ||
        '/incomes/categories' ||
        '/inventory' =>
          [
            {'key': 'diesel', 'label': 'گازوئیل'},
          ],
        _ => {
            'tone': 'sound',
            'system': 'مغازه امروز سالم است.',
            'yours': 'هیچ چیز کار شما نیست.',
            'cycles': 8,
            'sound': true,
            'failures': <String>[],
            'warnings': <String>[],
            'needs': <String>[],
            'figures': <String>[],
            'outlook': <String>[],
          },
      };

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Wire wire;
  late ApiClient client;
  late BakeryApi api;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    wire = _Wire();
    client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);
    api = BakeryApi(client);
  });

  test('the morning the signal went, from sign-in to the sale arriving',
      () async {
    // ---- with signal: sign in, and let the cache fill behind him
    final session = await api.login('naser', 'secret');
    expect(session.user.role, UserRole.admin);

    await CacheWarmer(api).warm(UserRole.admin);

    // ---- the radio goes
    wire.down = true;
    wire.asked.clear();

    // ---- the screen he opens first still opens
    final answer = await api.today();

    expect(answer.system, 'مغازه امروز سالم است.');
    expect(
      api.todayCheckedAt(),
      isNotNull,
      reason: 'a saved answer must say when it was taken, not «همین حالا»',
    );

    // ---- and the cost he records is held rather than lost
    final categories = await api.expenseCategories();
    expect(categories, isNotEmpty, reason: 'the form will not save without one');

    final queued = await api.recordExpense(
      category: 'diesel',
      title: 'گازوئیل',
      amount: 4000000,
    );

    expect(queued, isTrue, reason: 'he must be told it was saved, not sent');
    expect(await client.queue.count(), 1);
    expect(wire.posted, isEmpty, reason: 'nothing can have reached the server');

    // ---- the radio comes back
    wire.down = false;

    final result = await client.syncQueue();

    expect(result.sent, 1);
    expect(result.remaining, 0);
    expect(await client.queue.count(), 0);

    // The cost arrived once, whole, and under a name — so a retry the
    // shop cannot see the answer to is recognised rather than recorded
    // twice.
    final sent = wire.posted.single;

    expect(sent.path, '/expenses');
    expect(sent.body['amount'], 4000000);
    expect(sent.key, isNotEmpty);
  });

  /// The half of it that costs money if it is wrong. A receive timeout
  /// looks identical to a lost request from the handset, and guessing
  /// wrong records the shop's diesel twice.
  test('a write replayed after a timeout carries the name of the first try',
      () async {
    await api.login('naser', 'secret');

    wire.down = true;
    await api.recordExpense(category: 'diesel', title: 'گازوئیل', amount: 500);

    final firstName = (await client.queue.all()).single.id;

    wire.down = false;
    await client.syncQueue();

    expect(wire.posted.single.key, firstName);
  });
}
