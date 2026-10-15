import 'dart:io';
import 'dart:typed_data';

import 'package:bakery_app/services/update_service.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:open_filex/open_filex.dart';

class _ApkServer implements HttpClientAdapter {
  int downloads = 0;
  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    downloads++;
    final bytes = Uint8List.fromList([0x50, 0x4b, 3, 4, 1, 2]);
    return ResponseBody.fromBytes(bytes, 200, headers: {
      Headers.contentLengthHeader: ['6'],
      Headers.contentTypeHeader: ['application/vnd.android.package-archive'],
    });
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late Directory dir;
  setUp(
      () => dir = Directory.systemTemp.createTempSync('bakery-install-test-'));
  tearDown(() => dir.deleteSync(recursive: true));
  const update = AppUpdate(
      version: '5.34.0',
      downloadUrl: 'https://server.test/update.apk',
      sizeBytes: 6);

  test('فایل کامل تا دادن مجوز حفظ می‌شود و نصب دوباره دانلود نمی‌کند',
      () async {
    var granted = false;
    var installerCalls = 0;
    final server = _ApkServer();
    final service = UpdateService(
      dio: Dio()..httpClientAdapter = server,
      temporaryDirectory: () async => dir,
      canInstallPackages: () async => granted,
      openApk: (path) async {
        installerCalls++;
        expect(await File(path).exists(), isTrue);
        return OpenResult();
      },
    );
    await expectLater(
        service.downloadAndInstall(update),
        throwsA(isA<UpdateInstallException>()
            .having((e) => e.permissionDenied, 'مجوز', true)));
    expect(service.hasDownloaded, isTrue);
    expect(installerCalls, 0);
    granted = true;
    await service.downloadAndInstall(update);
    expect(server.downloads, 1);
    expect(installerCalls, 1);
  });

  test('فایل خراب قبلی دوباره دریافت می‌شود و به نصب‌کننده نمی‌رسد', () async {
    await File('${dir.path}/bakery-5.34.0.apk')
        .writeAsString('<html>خطا</html>');
    final server = _ApkServer();
    final service = UpdateService(
      dio: Dio()..httpClientAdapter = server,
      temporaryDirectory: () async => dir,
      canInstallPackages: () async => true,
      openApk: (path) async {
        expect(
            await UpdateService.verifyApk(File(path), expectedSize: 6), isTrue);
        return OpenResult();
      },
    );
    await service.downloadAndInstall(update);
    expect(server.downloads, 1);
  });

  test('خطای نصب‌کننده مجوز نصب خوانده نمی‌شود و فایل برای تلاش بعدی باقی است',
      () async {
    final server = _ApkServer();
    final service = UpdateService(
      dio: Dio()..httpClientAdapter = server,
      temporaryDirectory: () async => dir,
      canInstallPackages: () async => true,
      openApk: (_) async => OpenResult(type: ResultType.noAppToOpen),
    );
    await expectLater(
        service.downloadAndInstall(update),
        throwsA(isA<UpdateInstallException>()
            .having((e) => e.permissionDenied, 'مجوز', false)));
    expect(service.hasDownloaded, isTrue);
  });
}
