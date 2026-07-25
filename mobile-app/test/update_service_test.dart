import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:bakery_app/services/update_service.dart';

/// Serves canned GitHub Releases payloads so the update check can be tested
/// without touching the network.
class _FakeAdapter implements HttpClientAdapter {
  _FakeAdapter(this.body, {this.statusCode = 200});

  final String body;
  final int statusCode;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      body,
      statusCode,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Dio _dioReturning(String json) {
  final dio = Dio();
  dio.httpClientAdapter = _FakeAdapter(json);
  return dio;
}

String _release(String tag, {String asset = 'app-release.apk'}) => '''
{
  "tag_name": "$tag",
  "body": "تغییرات نسخه",
  "assets": [
    {
      "name": "$asset",
      "size": 20971520,
      "browser_download_url": "https://example.com/$asset"
    }
  ]
}
''';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    // There is no real package on the test host; pin the installed version so
    // the comparison logic has something deterministic to work against.
    PackageInfo.setMockInitialValues(
      appName: 'bakery_app',
      packageName: 'com.bakery.bakery_app',
      version: '1.0.0',
      buildNumber: '1',
      buildSignature: '',
    );
  });

  group('AppUpdate', () {
    test('formats the download size in megabytes', () {
      const update = AppUpdate(
        version: '1.1.0',
        downloadUrl: 'https://example.com/app.apk',
        sizeBytes: 20971520,
        notes: null,
      );

      expect(update.sizeLabel, '20.0 مگابایت');
    });
  });

  group('UpdateService.checkForUpdate', () {
    test('returns null when the release has no APK asset', () async {
      final service = UpdateService(
        dio: _dioReturning(_release('v99.0.0', asset: 'notes.txt')),
      );

      expect(await service.checkForUpdate(), isNull);
    });

    test('returns null instead of throwing when the request fails', () async {
      final dio = Dio();
      dio.httpClientAdapter = _FakeAdapter('not json', statusCode: 500);

      final service = UpdateService(dio: dio);

      expect(await service.checkForUpdate(), isNull);
    });

    test('surfaces the APK asset when a newer release exists', () async {
      final service = UpdateService(dio: _dioReturning(_release('v99.0.0')));
      final update = await service.checkForUpdate();

      expect(update, isNotNull);
      expect(update!.version, '99.0.0');
      expect(update.downloadUrl, endsWith('.apk'));
      expect(update.notes, 'تغییرات نسخه');
    });

    test('ignores a release that is not newer than the installed build', () async {
      final service = UpdateService(dio: _dioReturning(_release('v0.0.1')));

      expect(await service.checkForUpdate(), isNull);
    });
  });
}
