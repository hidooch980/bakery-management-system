import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:bakery_app/services/update_service.dart';

/// Answers by path, and remembers what was asked, so the order — our own
/// server first, GitHub only when it has nothing — can be checked.
class _Router implements HttpClientAdapter {
  _Router(this.routes);

  final Map<String, (int, String)> routes;
  final asked = <String>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final url = options.uri.toString();
    asked.add(url);

    for (final entry in routes.entries) {
      if (url.contains(entry.key)) {
        return ResponseBody.fromString(entry.value.$2, entry.value.$1,
            headers: {
              Headers.contentTypeHeader: [Headers.jsonContentType],
            });
      }
    }

    throw DioException.connectionError(
        requestOptions: options, reason: 'no route for $url');
  }

  @override
  void close({bool force = false}) {}
}

const _github = 'api.github.com/repos/owner/name/releases/latest';

String _server(String version, {String url = '/download/bakery-app-v9.apk'}) =>
    jsonEncode({
      'success': true,
      'data': {
        'version': version,
        'version_code': 10600,
        'url': url,
        'size': 48175473,
        'sha256':
            'E113B1719846E363057752457B632966E1B71E0FF78871121D21C3B158E6EE27',
        'notes': 'از سرور خودمان',
      },
    });

String _githubRelease(String tag) => jsonEncode({
      'tag_name': tag,
      'body': 'از گیت‌هاب',
      'assets': [
        {
          'name': 'bakery-app-$tag.apk',
          'size': 1024,
          'browser_download_url': 'https://github.com/x/$tag.apk',
          'digest': 'sha256:${'a' * 64}',
        }
      ],
    });

UpdateService _service(_Router router) {
  final dio = Dio()..httpClientAdapter = router;

  return UpdateService(
    dio: dio,
    repo: 'owner/name',
    serverBaseUrl: 'http://37.32.21.125/api/v1',
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    PackageInfo.setMockInitialValues(
      appName: 'bakery_app',
      packageName: 'com.bakery.bakery_app',
      version: '5.31.0',
      buildNumber: '10514',
      buildSignature: '',
    );
  });

  test('our own server is asked first and GitHub is not touched', () async {
    final router = _Router({
      '/api/v1/app/latest': (200, _server('5.32.0')),
      _github: (200, _githubRelease('v9.9.9')),
    });

    final update = await _service(router).checkForUpdate();

    expect(update, isNotNull);
    expect(update!.version, '5.32.0');
    expect(update.notes, 'از سرور خودمان');
    expect(update.sha256,
        'e113b1719846e363057752457b632966e1b71e0ff78871121d21c3b158e6ee27');
    expect(router.asked.single, 'http://37.32.21.125/api/v1/app/latest');
  });

  test('a relative link is resolved against the server the phone uses',
      () async {
    final update = await _service(_Router({
      '/api/v1/app/latest': (200, _server('5.32.0')),
    })).checkForUpdate();

    expect(
        update!.downloadUrl, 'http://37.32.21.125/download/bakery-app-v9.apk');
  });

  test('an absolute link from the server is kept as it is', () async {
    final update = await _service(_Router({
      '/api/v1/app/latest': (
        200,
        _server('5.32.0', url: 'https://baker.molido.ir/download/a.apk')
      ),
    })).checkForUpdate();

    expect(update!.downloadUrl, 'https://baker.molido.ir/download/a.apk');
  });

  test('nothing published on the server falls back to GitHub', () async {
    final router = _Router({
      '/api/v1/app/latest': (404, '{"success":false,"message":"x"}'),
      _github: (200, _githubRelease('v5.33.0')),
    });

    final update = await _service(router).checkForUpdate();

    expect(update!.version, '5.33.0');
    expect(update.notes, 'از گیت‌هاب');
    expect(update.sha256, 'a' * 64);
    expect(router.asked.length, 2);
  });

  test('an unreachable server falls back to GitHub', () async {
    final update = await _service(_Router({
      _github: (200, _githubRelease('v5.33.0')),
    })).checkForUpdate();

    expect(update!.version, '5.33.0');
  });

  test('the server already being on this version is not an update', () async {
    final update = await _service(_Router({
      '/api/v1/app/latest': (200, _server('5.31.0')),
    })).checkForUpdate();

    expect(update, isNull);
  });

  test('both unreachable says nothing rather than throwing', () async {
    expect(await _service(_Router({})).checkForUpdate(), isNull);
  });

  group('checksum', () {
    late Directory dir;
    late File file;

    setUp(() {
      dir = Directory.systemTemp.createTempSync('apk');
      file = File('${dir.path}/a.apk')..writeAsBytesSync(utf8.encode('apk'));
    });

    tearDown(() => dir.deleteSync(recursive: true));

    test('a matching file passes, in either case', () async {
      final hex = sha256.convert(utf8.encode('apk')).toString();

      expect(await UpdateService.verifyChecksum(file, hex), isTrue);
      expect(
          await UpdateService.verifyChecksum(file, hex.toUpperCase()), isTrue);
    });

    test('a different file is refused', () async {
      expect(await UpdateService.verifyChecksum(file, 'b' * 64), isFalse);
    });

    test('no published checksum does not block the install', () async {
      expect(await UpdateService.verifyChecksum(file, null), isTrue);
    });
  });
}
