import 'dart:io';
import 'dart:typed_data';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The owner put the phone in airplane mode to try the offline queue and
/// «اصلا کار نکرد».
///
/// Every screen behind his home tab was served from a saved copy. The home
/// tab itself — «امروز», the first thing the app shows him — asked the
/// server directly, so with the radio off it was a red error box, and the
/// expense form asked the server for its category list and would not save
/// without one. The queue was fine; it was behind a door that would not
/// open.
class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  bool down = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (down) {
      throw const SocketException('No address associated with hostname');
    }

    return ResponseBody.fromString(
      bodies[options.path]!,
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
  late BakeryApi api;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    wire = _Wire({
      '/today': '{"success":true,"data":{"tone":"sound","system":"مغازه امروز سالم است.",'
          '"yours":"چیزی کار شما نیست.","cycles":8,"sound":true,"failures":[],'
          '"warnings":[],"needs":[],"figures":[]}}',
      '/expenses/categories':
          '{"success":true,"data":[{"key":"diesel","label":"گازوئیل"}]}',
    });

    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);
    api = BakeryApi(client);
  });

  test('«امروز» opens from the saved copy and says when it looked', () async {
    await api.today();
    expect(api.todayCheckedAt(), isNull, reason: 'live answers carry no stamp');

    wire.down = true;

    final answer = await api.today();

    expect(answer.system, 'مغازه امروز سالم است.');
    expect(
      api.todayCheckedAt(),
      isNotNull,
      reason: 'a saved answer must not be presented as checked just now',
    );
  });

  test('the expense form still has its categories', () async {
    await api.expenseCategories();
    wire.down = true;

    final categories = await api.expenseCategories();

    expect(categories.single.key, 'diesel');
  });

  test('with no copy at all the error is still the error', () async {
    wire.down = true;

    expect(api.today(), throwsA(isA<ApiException>()));
  });
}
