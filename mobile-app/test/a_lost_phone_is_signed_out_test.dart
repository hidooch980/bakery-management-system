import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/models/signed_in_device.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/services/device_name.dart';

/// The phone side of signing a lost handset out.
///
/// The server has its own tests for the endpoints. What those cannot show
/// is the half that made the list worth building: that this app tells the
/// server what to call the handset, and that a row saying «همین گوشی» is
/// the server's answer rather than the app's guess. A list of three rows
/// all reading «mobile-app» is a list somebody signs the wrong one out of.
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
    // The platform channel behind device_info_plus does not exist under
    // `flutter test`, so the name is set rather than read.
    DeviceName.setForTesting('Xiaomi Redmi Note 12');
  });

  tearDown(() => DeviceName.setForTesting(null));

  ({BakeryApi api, _Wire wire}) apiThat(
    ResponseBody Function(RequestOptions) answer,
  ) {
    final client = ApiClient(baseUrl: 'http://test.local');
    final wire = _Wire(answer);
    client.transport = wire;

    return (api: BakeryApi(client), wire: wire);
  }

  test('signing in tells the server what to call this handset', () async {
    final it = apiThat((_) => _ok({
          'token': 'abc',
          'user': {'id': 1, 'name': 'عبدالله', 'permissions': <String>[]},
        }));

    await it.api.login('09151234567', 'secret');

    final sent = (it.wire.seen.single.data as Map).cast<String, dynamic>();

    expect(sent['device_name'], 'Xiaomi Redmi Note 12');
  });

  test('the list marks the phone in your hand from the server, not a guess',
      () async {
    final it = apiThat((_) => _ok({
          'devices': [
            {
              'id': 7,
              'name': 'گوشی گم‌شده',
              'is_current': false,
              'last_used_at': '۱۴۰۵/۰۶/۱۲ ۰۸:۳۰',
              'created_at': '۱۴۰۵/۰۶/۰۱ ۰۷:۰۰',
            },
            {
              'id': 9,
              'name': 'گوشی مغازه',
              'is_current': true,
              'last_used_at': null,
              'created_at': '۱۴۰۵/۰۶/۱۳ ۰۶:۰۰',
            },
          ],
          'max': 3,
        }));

    final devices = await it.api.devices();

    expect(devices, hasLength(2));
    expect(devices.first.isCurrent, isFalse);
    expect(devices.last.isCurrent, isTrue);
    expect(devices.first.name, 'گوشی گم‌شده');
  });

  test('a session opened but never used does not read as «هرگز»', () async {
    const device = SignedInDevice(
      id: 9,
      name: 'گوشی مغازه',
      isCurrent: true,
      createdAt: '۱۴۰۵/۰۶/۱۳ ۰۶:۰۰',
    );

    // This is the row somebody is most likely looking at — the phone they
    // are holding, signed in a minute ago. «هرگز استفاده نشده» there reads
    // as a stranger's session.
    expect(device.when, contains('ورود'));
    expect(device.when, contains('۱۴۰۵/۰۶/۱۳'));
  });

  test('a row the server sent without a name still says something', () async {
    final device = SignedInDevice.fromJson(const {
      'id': 4,
      'name': '',
      'is_current': false,
    });

    expect(device.name, 'دستگاه ناشناس');
    expect(device.when, 'بدون سابقهٔ استفاده');
  });

  test('closing this phone is reported back as such', () async {
    final it = apiThat((_) => _ok({'signed_self_out': true}));

    // The screen has to know, or it sits there holding a list it can no
    // longer read.
    expect(await it.api.signOutDevice(9), isTrue);
    expect(it.wire.seen.single.method, 'DELETE');
    expect(it.wire.seen.single.path, '/devices/9');
  });

  test('closing the rest says how many went', () async {
    final it = apiThat((_) => _ok({'closed': 2}));

    expect(await it.api.signOutOtherDevices(), 2);
    expect(it.wire.seen.single.path, '/devices/others');
  });

  test('a server that answers without the count is read as none', () async {
    final it = apiThat((_) => _ok(const <String, dynamic>{}));

    // An older server, or one that changes its mind about the payload.
    // «null دستگاه خارج شد» on a screen is worse than being conservative.
    expect(await it.api.signOutOtherDevices(), 0);
  });
}
