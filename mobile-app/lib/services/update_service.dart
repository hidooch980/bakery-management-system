import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:dio/dio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

import 'api_client.dart';
import 'install_permission.dart';

/// A release newer than the installed build — from this bakery's own server
/// when it has one, otherwise from GitHub Releases.
class AppUpdate {
  const AppUpdate({
    required this.version,
    required this.downloadUrl,
    required this.sizeBytes,
    this.notes,
    this.sha256,
    this.versionCode,
  });

  final String version;
  final int? versionCode;
  final String downloadUrl;
  final int sizeBytes;
  final String? notes;

  /// Lower-case hex. When known, the download is checked against it before
  /// it is handed to the installer.
  final String? sha256;

  String get sizeLabel =>
      '${(sizeBytes / 1024 / 1024).toStringAsFixed(1)} مگابایت';
}

/// The downloaded APK does not match the checksum that was published, so it
/// is thrown away rather than installed.
class UpdateIntegrityException implements Exception {
  const UpdateIntegrityException();

  @override
  String toString() => 'فایل دانلودشده با نسخهٔ منتشرشده یکی نیست'
      ' (ناقص یا دست‌کاری‌شده). دوباره تلاش کنید.';
}

/// خطای نصب با علت مشخص؛ خطای دانلود نباید درخواست مجوز نصب نشان دهد.
class UpdateInstallException implements Exception {
  const UpdateInstallException(this.message, {this.permissionDenied = false});
  final String message;
  final bool permissionDenied;
  @override
  String toString() => message;
}

/// Finds a newer APK, downloads it, and hands it to the system installer.
/// Keeps the app updatable without an app store.
///
/// The bakery's own server is asked first: GitHub is often blocked or slow
/// from Iran, and every signed release is copied onto the server with a
/// manifest beside it. GitHub Releases remains the fallback.
class UpdateService {
  UpdateService(
      {Dio? dio,
      String? repo,
      String? serverBaseUrl,
      Future<Directory> Function()? temporaryDirectory,
      Future<bool> Function()? canInstallPackages,
      Future<OpenResult> Function(String path)? openApk})
      : _dio = dio ??
            Dio(BaseOptions(connectTimeout: const Duration(seconds: 15))),
        _repo = repo ?? defaultRepo,
        _serverBaseUrl = serverBaseUrl,
        _temporaryDirectory = temporaryDirectory ?? getTemporaryDirectory,
        _canInstallPackages = canInstallPackages ?? InstallPermission.isGranted,
        _openApk = openApk ?? _openWithInstaller;

  /// Override with --dart-define=UPDATE_REPO=owner/name for a fork.
  static const defaultRepo = String.fromEnvironment(
    'UPDATE_REPO',
    defaultValue: 'hidooch980/bakery-management-system',
  );

  final Dio _dio;
  final String _repo;
  final Future<Directory> Function() _temporaryDirectory;
  final Future<bool> Function() _canInstallPackages;
  final Future<OpenResult> Function(String path) _openApk;
  String? _downloadedPath;

  bool get hasDownloaded => _downloadedPath != null;

  static Future<OpenResult> _openWithInstaller(String path) => OpenFilex.open(
        path,
        type: 'application/vnd.android.package-archive',
      );

  /// The API base, e.g. `http://37.32.21.125/api/v1`. Read at check time so
  /// a server move picked up after launch is followed.
  final String? _serverBaseUrl;

  String get _apiBase => _serverBaseUrl ?? ApiClient.currentBaseUrl;

  Future<String> currentVersion() async {
    final info =
        await PackageInfo.fromPlatform().timeout(const Duration(seconds: 5));
    return info.version;
  }

  /// خطای بررسی دستی باید از «به‌روز است» قابل تشخیص باشد.
  String? lastCheckError;

  Future<AppUpdate?> checkForUpdate() async {
    lastCheckError = null;
    try {
      final info =
          await PackageInfo.fromPlatform().timeout(const Duration(seconds: 5));
      final current = _normalise(info.version);
      final currentCode = int.tryParse(info.buildNumber);
      final server = await _fromServer();
      if (server != null && _canInstall(server, current, currentCode)) {
        return server;
      }

      // پاسخ قدیمی سرور نباید نسخهٔ جدید گیت‌هاب را پنهان کند.
      final github = await _fromGitHub();
      if (github != null && _canInstall(github, current, currentCode)) {
        return github;
      }
      if (server == null && github == null) {
        lastCheckError =
            'بررسی نسخه انجام نشد؛ اتصال به سرور و گیت‌هاب را بررسی و دوباره تلاش کنید.';
      } else if (server != null &&
          _isNewer(server.version, current) &&
          server.versionCode != null &&
          currentCode != null &&
          server.versionCode! < currentCode) {
        lastCheckError =
            'نسخهٔ منتشرشده کد نصب پایین‌تری از برنامهٔ فعلی دارد؛ انتشار جدید باید با کد نصب بالاتر ساخته شود.';
      }
      return null;
    } catch (_) {
      lastCheckError = 'خواندن نسخه یا اتصال برای بررسی به‌روزرسانی ممکن نشد.';
      return null;
    }
  }

  bool _canInstall(AppUpdate release, String current, int? currentCode) {
    // اندروید کد نصب را مقایسه می‌کند؛ نام نسخه به‌تنهایی کافی نیست.
    if (_isNewer(current, release.version)) return false;
    if (release.versionCode != null && currentCode != null) {
      if (release.versionCode! < currentCode) return false;
      return release.versionCode! > currentCode;
    }
    return _isNewer(release.version, current);
  }

  /// `GET {api}/app/latest`. Null when the server has nothing published,
  /// is unreachable, or answers with something unusable — any of which
  /// sends the check on to GitHub.
  Future<AppUpdate?> _fromServer() async {
    final base = _apiBase.trim();
    if (base.isEmpty) return null;

    try {
      final response = await _dio.get<dynamic>(
        '${base.replaceFirst(RegExp(r'/+$'), '')}/app/latest',
        options: Options(
          headers: {'Accept': 'application/json'},
          receiveTimeout: const Duration(seconds: 10),
          sendTimeout: const Duration(seconds: 10),
          validateStatus: (status) => status == 200,
        ),
      );

      final body = response.data;
      final data = body is Map ? body['data'] : null;
      if (data is! Map) return null;

      final version = _normalise('${data['version'] ?? ''}');
      final url = '${data['url'] ?? ''}'.trim();
      if (!RegExp(r'^\d+\.\d+\.\d+$').hasMatch(version) || url.isEmpty) {
        return null;
      }
      final resolved = Uri.parse(base).resolve(url);
      if (resolved.scheme != 'https' && resolved.scheme != 'http') return null;

      final sha = '${data['sha256'] ?? ''}'.trim().toLowerCase();

      return AppUpdate(
        version: version,
        versionCode: int.tryParse('${data['version_code'] ?? ''}'),
        downloadUrl: resolved.toString(),
        sizeBytes: (data['size'] as num?)?.toInt() ?? 0,
        notes: data['notes'] as String?,
        sha256: RegExp(r'^[0-9a-f]{64}$').hasMatch(sha) ? sha : null,
      );
    } catch (_) {
      return null;
    }
  }

  Future<AppUpdate?> _fromGitHub() async {
    try {
      final response = await _dio.get<Map<String, dynamic>>(
        'https://api.github.com/repos/$_repo/releases/latest',
        options: Options(
          headers: {'Accept': 'application/vnd.github+json'},
          receiveTimeout: const Duration(seconds: 15),
          sendTimeout: const Duration(seconds: 15),
        ),
      );

      final data = response.data;
      if (data == null) return null;

      final latest = _normalise(data['tag_name'] as String? ?? '');
      if (latest.isEmpty) return null;

      final assets =
          (data['assets'] as List?)?.cast<Map<String, dynamic>>() ?? const [];
      final apk = assets.firstWhere(
        (a) => (a['name'] as String? ?? '').endsWith('.apk'),
        orElse: () => const <String, dynamic>{},
      );

      final url = apk['browser_download_url'] as String?;
      if (url == null) return null;

      // GitHub publishes each asset's digest as «sha256:<hex>».
      final digest = '${apk['digest'] ?? ''}'.toLowerCase();

      return AppUpdate(
        version: latest,
        downloadUrl: url,
        sizeBytes: (apk['size'] as num?)?.toInt() ?? 0,
        notes: data['body'] as String?,
        sha256: digest.startsWith('sha256:') ? digest.substring(7) : null,
      );
    } catch (_) {
      return null;
    }
  }

  /// Downloads the APK and opens it so Android can prompt to install.
  /// [onProgress] receives a value between 0 and 1.
  Future<void> downloadAndInstall(
    AppUpdate update, {
    void Function(double progress)? onProgress,
    CancelToken? cancelToken,
  }) async {
    final dir = await _temporaryDirectory();
    final file = File('${dir.path}/bakery-${update.version}.apk');
    final reusable = await file.exists() &&
        await verifyApk(file, expectedSize: update.sizeBytes) &&
        await verifyChecksum(file, update.sha256);
    if (!reusable) {
      _downloadedPath = null;
      if (await file.exists()) await file.delete();
      try {
        await _dio.download(
          update.downloadUrl,
          file.path,
          cancelToken: cancelToken,
          options: Options(
              receiveTimeout: const Duration(seconds: 60),
              sendTimeout: const Duration(seconds: 15)),
          onReceiveProgress: (received, total) {
            if (total > 0) {
              onProgress?.call((received / total).clamp(0.0, 1.0).toDouble());
            }
          },
        );
        if (!await verifyApk(file, expectedSize: update.sizeBytes) ||
            !await verifyChecksum(file, update.sha256)) {
          throw const UpdateIntegrityException();
        }
      } catch (_) {
        if (await file.exists()) await file.delete();
        rethrow;
      }
    }
    _downloadedPath = file.path;
    onProgress?.call(1);

    // فایل معتبر نگه داشته می‌شود تا برگشت از تنظیم مجوز، دانلود دوباره نخواهد.
    if (!await _canInstallPackages()) {
      throw const UpdateInstallException(
          'اجازه نصب برنامه‌های ناشناس برای این برنامه فعال نیست.',
          permissionDenied: true);
    }
    final result = await _openApk(file.path);

    if (result.type != ResultType.done) {
      throw UpdateInstallException(
        _failureMessage(result),
        permissionDenied: result.type == ResultType.permissionDenied,
      );
    }
  }

  /// بررسی اندازه و امضای آغاز آرشیو؛ فایل ناقص یا HTML به نصب‌کننده نمی‌رود.
  @visibleForTesting
  static Future<bool> verifyApk(File file, {int expectedSize = 0}) async {
    final length = await file.length();
    if (length < 4 || (expectedSize > 0 && length != expectedSize)) {
      return false;
    }
    final handle = await file.open();
    try {
      final bytes = await handle.read(4);
      return bytes[0] == 0x50 &&
          bytes[1] == 0x4b &&
          bytes[2] == 3 &&
          bytes[3] == 4;
    } finally {
      await handle.close();
    }
  }

  /// True when [expected] is unknown or matches the file's SHA-256.
  @visibleForTesting
  static Future<bool> verifyChecksum(File file, String? expected) async {
    if (expected == null || expected.isEmpty) return true;

    final digest = await sha256.bind(file.openRead()).first;

    return digest.toString() == expected.toLowerCase();
  }

  /// Says which of the two things went wrong, because they have different
  /// fixes and "could not open" sent people to toggle a permission they had
  /// already granted.
  String _failureMessage(OpenResult result) {
    if (result.type == ResultType.permissionDenied) {
      return 'اجازه «نصب برنامه‌های ناشناس» داده نشده است.'
          ' از تنظیمات آن را برای این برنامه فعال کنید.';
    }

    if (result.type == ResultType.noAppToOpen) {
      return 'نصب‌کننده سیستم پیدا نشد. فایل دانلود شد ولی گوشی'
          ' برنامه‌ای برای نصب آن معرفی نکرد.';
    }

    return 'باز کردن فایل نصب ممکن نشد: ${result.message}';
  }

  /// Strips a leading "v" and any build metadata so "v1.2.0" == "1.2.0+3".
  String _normalise(String version) =>
      version.trim().replaceFirst(RegExp('^v'), '').split('+').first;

  /// Semantic comparison, so 1.10.0 correctly beats 1.9.0.
  bool _isNewer(String candidate, String current) {
    final a = _parts(candidate);
    final b = _parts(current);

    for (var i = 0; i < 3; i++) {
      if (a[i] != b[i]) return a[i] > b[i];
    }

    return false;
  }

  List<int> _parts(String version) {
    final parsed = version.split('.').map((p) => int.tryParse(p) ?? 0).toList();

    while (parsed.length < 3) {
      parsed.add(0);
    }

    return parsed.take(3).toList();
  }
}
