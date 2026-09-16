import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// The phone side of an owner holding more than one shop.
///
/// The server decides which shop a request is about and refuses an id the
/// person has no claim to; none of that is repeated here. What lives on
/// this side is the part the server cannot see: that the chosen shop
/// reaches *every* request rather than the ones somebody remembered to
/// thread it through, that it survives the app being closed, that it does
/// not survive somebody else signing in, and that switching throws away
/// figures belonging to the shop just left.
class _Wire implements HttpClientAdapter {
  _Wire(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);

    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _ok(Object data) => ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  ({BakeryApi api, ApiClient client, _Wire wire}) apiThat(
    ResponseBody Function(RequestOptions) answer,
  ) {
    final client = ApiClient(baseUrl: 'http://test.local');
    final wire = _Wire(answer);
    client.transport = wire;

    return (api: BakeryApi(client), client: client, wire: wire);
  }

  test('the shops on offer come back with the current one marked', () async {
    final it = apiThat((_) => _ok({
          'current_id': 2,
          'bakeries': [
            {'id': 1, 'name': 'نانوایی اول', 'is_current': false},
            {'id': 2, 'name': 'نانوایی دوم', 'is_current': true},
          ],
        }));

    final shops = await it.api.myBakeries();

    expect(shops.map((s) => s.name), ['نانوایی اول', 'نانوایی دوم']);
    expect(shops.firstWhere((s) => s.id == 2).isCurrent, isTrue);
    expect(shops.firstWhere((s) => s.id == 1).isCurrent, isFalse);
  });

  test('nothing is sent while no shop has been chosen', () async {
    // Which is every member of staff, and the owner before they switch.
    // The server reads an absent header as «their own shop».
    final it = apiThat((_) => _ok(const {'bakeries': []}));

    await it.api.myBakeries();

    expect(it.wire.seen.single.headers.containsKey('X-Bakery-Id'), isFalse);
  });

  test('the chosen shop rides on every request, not just the next one',
      () async {
    // The part that cannot be left to each screen: the first screen that
    // forgot would show another shop's figures and say nothing.
    final it = apiThat((_) => _ok(const {'bakeries': []}));

    await it.client.actAsBakery(7);

    await it.api.myBakeries();
    await it.api.myBakeries();

    expect(
      it.wire.seen.map((r) => r.headers['X-Bakery-Id']),
      ['7', '7'],
    );
  });

  test('the choice is still there after the app is closed and opened',
      () async {
    final first = apiThat((_) => _ok(const {'bakeries': []}));
    await first.client.actAsBakery(4);

    // A cold start: a new client over the same storage.
    final second = apiThat((_) => _ok(const {'bakeries': []}));
    expect(second.client.actingBakeryId, isNull);

    await second.client.restoreBakeryChoice();

    expect(second.client.actingBakeryId, 4);
  });

  test('signing out forgets which shop was open', () async {
    // Otherwise the next person to use this phone opens inside whichever
    // shop the last one was looking at.
    final it = apiThat((_) => _ok(const {'bakeries': []}));
    await it.client.actAsBakery(4);

    await it.api.logout();

    expect(it.client.actingBakeryId, isNull);

    final fresh = apiThat((_) => _ok(const {'bakeries': []}));
    await fresh.client.restoreBakeryChoice();

    expect(fresh.client.actingBakeryId, isNull);
  });

  test('switching moves the generation on so the screens ask again',
      () async {
    // Screens read their figures in initState. Without this the old
    // shop's numbers would sit under the new shop's name.
    final it = apiThat((_) => _ok(const {'bakeries': []}));
    final before = it.client.shopGeneration.value;

    await it.client.actAsBakery(3);

    expect(it.client.shopGeneration.value, greaterThan(before));
  });

  test('going back to their own shop clears the choice rather than pinning it',
      () async {
    final it = apiThat((_) => _ok(const {'bakeries': []}));
    await it.client.actAsBakery(5);

    await it.client.actAsBakery(null);

    expect(it.client.actingBakeryId, isNull);

    await it.api.myBakeries();

    expect(it.wire.seen.last.headers.containsKey('X-Bakery-Id'), isFalse);
  });

  test('a shop list the server never sent reads as no choice at all',
      () async {
    // An older server has no such route. The switcher hides rather than
    // the home screen breaking.
    final it = apiThat((_) => _ok({'current_id': 1}));

    expect(await it.api.myBakeries(), isEmpty);
  });
}
