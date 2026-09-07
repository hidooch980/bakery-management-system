import 'package:package_info_plus/package_info_plus.dart';

/// The version this build carries, for the `X-App-Version` header.
///
/// Sent so the device list can say what is installed on each handset. The
/// list has always named the phone and never the build, so «کار نکرد» from
/// the shop floor could not be told apart from «کار نکرد, on a build from
/// three releases ago» — and four releases in a row were spent fixing
/// things that may not have been on the phone doing the complaining.
///
/// Read once and kept. It cannot change while the app is running, and the
/// platform channel behind it is not free on every request.
class AppVersion {
  static String? _cached;

  /// What to put in the header, without waiting for anything.
  ///
  /// Read synchronously on purpose. The first version of this awaited the
  /// platform channel inside the request interceptor, which put a channel
  /// round-trip in front of every call the app makes — and under
  /// `flutter test` that channel never answers, so thirty-nine widget
  /// tests hung rather than failed. A header this is only a convenience
  /// for must never be able to hold up a request.
  ///
  /// Null until [warmUp] has finished, which costs at most the first
  /// request or two: the server records the version on any later one.
  static String? get cached => _cached;

  /// Reads it once, at startup, off the request path.
  ///
  /// Never throws and never hangs: a channel that does not answer is a
  /// missing header, and a phone that cannot report its version still
  /// sells bread.
  static Future<String?> warmUp() async {
    if (_cached != null) return _cached;

    try {
      final info = await PackageInfo.fromPlatform()
          .timeout(const Duration(seconds: 3));
      final version = info.version.trim();

      return _cached = version.isEmpty ? null : version;
    } on Object {
      return null;
    }
  }

  /// Testing seam. The platform channel is not available under
  /// `flutter test`.
  static void setForTesting(String? version) => _cached = version;
}
