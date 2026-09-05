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

/// The owner put the phone in flight mode and every screen was empty.
///
/// Nothing was broken. The read cache had always been passive — it held
/// whatever somebody happened to open while online and nothing else — so
/// «works offline» quietly meant «works offline on the screens you
/// visited earlier today». He installed the release that finally made
/// the local database open, went straight to flight mode to try it, and
/// there was nothing in it to show, because the database had been broken
/// until minutes before.
///
/// The app had never been wrong. It had never been given anything to
/// remember.
class _Wire implements HttpClientAdapter {
  _Wire();

  final asked = <String>[];

  bool down = false;

  /// Paths this account is not allowed, answered 403 like the server.
  final forbidden = <String>{};

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (down) {
      throw const SocketException('No address associated with hostname');
    }

    asked.add(options.path);

    if (forbidden.contains(options.path)) {
      return ResponseBody.fromString(
        '{"success":false,"message":"اجازه ندارید"}',
        403,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );
    }

    // Some of these endpoints answer with a list and some with an object,
    // and the parsers are strict about which. A stub that returned one
    // shape for everything would fail reads that are fine in the shop.
    const listed = {
      '/expenses/categories',
      '/incomes/categories',
      '/inventory',
      '/chane-entries/pending',
      '/dough-entries/pending',
      '/chane-entries/my-history',
      '/dough-entries/my-history',
      '/sales/staff',
    };

    final data = listed.contains(options.path)
        ? '[]'
        : '{"tone":"sound","system":"مغازه امروز سالم است.","yours":"",'
            '"cycles":8,"sound":true,"failures":[],"warnings":[],'
            '"needs":[],"figures":[]}';

    return ResponseBody.fromString(
      '{"success":true,"data":$data}',
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Wire wire;
  late ApiClient client;
  late BakeryApi api;
  late CacheWarmer warmer;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    wire = _Wire();
    client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);
    api = BakeryApi(client);
    warmer = CacheWarmer(api);
  });

  test('the owner\'s first screen survives the signal going', () async {
    await warmer.warm(UserRole.admin);

    // The whole point: nothing has been opened by hand, and the radio is
    // now off.
    wire.down = true;

    final answer = await api.today();

    expect(answer.system, 'مغازه امروز سالم است.');
    expect(
      api.todayCheckedAt(),
      isNotNull,
      reason: 'a warmed answer is a saved copy and must say so',
    );
  });

  test('the expense form still has the categories it refuses to save without',
      () async {
    await warmer.warm(UserRole.admin);
    wire.down = true;

    await expectLater(api.expenseCategories(), completes);
  });

  test('a seller gets the seller\'s screens, not the owner\'s', () async {
    await warmer.warm(UserRole.seller);

    expect(wire.asked, contains('/chane-entries/pending'));
    expect(
      wire.asked,
      isNot(contains('/today')),
      // A 403 on every reconnection, on a shop's connection, for a screen
      // this person cannot open anyway.
      reason: 'no role should be warmed with another role\'s screens',
    );
  });

  test('every role warms something, including one we do not recognise', () {
    for (final role in UserRole.values) {
      expect(warmer.readCountFor(role), greaterThan(0), reason: '$role');
    }
  });

  group('warming must never be something the user notices', () {
    test('a permission this role turns out not to have is skipped', () async {
      wire.forbidden.add('/reports/dashboard');

      await expectLater(warmer.warm(UserRole.admin), completes);

      // And it carried on past it rather than stopping at the refusal.
      expect(wire.asked, contains('/inventory'));
    });

    test('no signal at all is not an error', () async {
      wire.down = true;

      await expectLater(warmer.warm(UserRole.admin), completes);
    });

    /// Coming back online fires the connectivity listener and the 45-second
    /// poll within the same second. Warming twice would double every
    /// request on the connection that just came back.
    test('two warms at once are one', () async {
      final first = warmer.warm(UserRole.seller);
      final second = warmer.warm(UserRole.seller);

      await Future.wait([first, second]);

      final board = wire.asked.where((p) => p == '/chane-entries/pending');

      expect(board, hasLength(1));
    });
  });
}
