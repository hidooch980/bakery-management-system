import 'dart:io';

import 'package:dio/dio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:path_provider/path_provider.dart';

/// A release newer than the installed build, as published on GitHub Releases.
class AppUpdate {
  const AppUpdate({
    required this.version,
    required this.downloadUrl,
    required this.sizeBytes,
    this.notes,
  });

  final String version;
  final String downloadUrl;
  final int sizeBytes;
  final String? notes;

  String get sizeLabel => '${(sizeBytes / 1024 / 1024).toStringAsFixed(1)} مگابایت';
}

/// Checks GitHub Releases for a newer APK, downloads it, and hands it to the
/// system installer. Keeps the app updatable without an app store.
class UpdateService {
  UpdateService({Dio? dio, String? repo})
      : _dio = dio ?? Dio(),
        _repo = repo ?? defaultRepo;

  /// Override with --dart-define=UPDATE_REPO=owner/name for a fork.
  static const defaultRepo = String.fromEnvironment(
    'UPDATE_REPO',
    defaultValue: 'hidooch980/bakery-management-system',
  );

  final Dio _dio;
  final String _repo;

  Future<String> currentVersion() async {
    final info = await PackageInfo.fromPlatform();
    return info.version;
  }

  /// Returns the newer release, or null when already up to date.
  /// Never throws — a failed check must not block the user's work.
  Future<AppUpdate?> checkForUpdate() async {
    try {
      final current = await currentVersion();

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
      if (latest.isEmpty || !_isNewer(latest, _normalise(current))) return null;

      final assets = (data['assets'] as List?)?.cast<Map<String, dynamic>>() ?? const [];
      final apk = assets.firstWhere(
        (a) => (a['name'] as String? ?? '').endsWith('.apk'),
        orElse: () => const <String, dynamic>{},
      );

      final url = apk['browser_download_url'] as String?;
      if (url == null) return null;

      return AppUpdate(
        version: latest,
        downloadUrl: url,
        sizeBytes: (apk['size'] as num?)?.toInt() ?? 0,
        notes: data['body'] as String?,
      );
    } on DioException {
      return null;
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
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/bakery-${update.version}.apk');

    // A partial file from an interrupted attempt would fail to install.
    if (file.existsSync()) await file.delete();

    await _dio.download(
      update.downloadUrl,
      file.path,
      cancelToken: cancelToken,
      onReceiveProgress: (received, total) {
        if (total > 0) onProgress?.call(received / total);
      },
    );

    // Named rather than guessed from the extension: Android hands an APK to
    // the installer only when the intent says it is one, and left to infer
    // it the file opened in nothing at all.
    final result = await OpenFilex.open(
      file.path,
      type: 'application/vnd.android.package-archive',
    );

    if (result.type != ResultType.done) {
      throw Exception(_failureMessage(result));
    }
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
