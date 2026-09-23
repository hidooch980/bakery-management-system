import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';

/// Which Android — or iOS — this handset is running.
///
/// The device list has named the phone and the build for a while, and
/// that turned out to be the two thirds of the question that do not
/// answer it. A seller reported the new APK would not install; the list
/// said «Samsung SM-J250F» and an app version three releases old, and
/// neither said the thing that mattered — the phone was on an Android
/// older than the one the app has required since its seventh change, so
/// no release since could ever have installed on it, and nobody could
/// have known that from any screen.
///
/// Two values, not one. [name] is what a person recognises («Android
/// 7.0») and [sdkInt] is the number the build is actually compared
/// against. Working one out from the other wherever it happens to be
/// needed is how the two come to disagree.
///
/// Read once and kept, and read *off* the request path, for the same
/// reason [AppVersion] is: a platform channel in front of every request
/// is a channel that never answers under `flutter test`, and a header
/// this is only a convenience for must never hold up a sale.
class DeviceOs {
  static String? _name;

  static int? _sdkInt;

  /// What to put in the header, without waiting for anything.
  static String? get cachedName => _name;

  /// The API level, or null on iOS and anywhere it was not reported.
  static int? get cachedSdkInt => _sdkInt;

  /// Reads it once, at startup. Never throws and never hangs.
  static Future<void> warmUp() async {
    if (_name != null) return;

    try {
      final info = DeviceInfoPlugin();

      if (Platform.isAndroid) {
        final android = await info.androidInfo
            .timeout(const Duration(seconds: 3));

        _sdkInt = android.version.sdkInt;
        _name = _tidy('Android ${android.version.release}');

        return;
      }

      if (Platform.isIOS) {
        final ios = await info.iosInfo.timeout(const Duration(seconds: 3));

        _name = _tidy('iOS ${ios.systemVersion}');
      }
    } on Object {
      // A phone that cannot report its version still sells bread.
    }
  }

  /// Keeps it inside the thirty characters the server stores, and strips
  /// anything the server would refuse — so a manufacturer's odd string
  /// arrives shortened rather than being dropped in full.
  static String? _tidy(String raw) {
    final value = raw
        .replaceAll(RegExp(r'[^0-9A-Za-z. -]'), '')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();

    if (value.isEmpty) return null;

    return value.length <= 30 ? value : value.substring(0, 30);
  }

  /// Testing seam. The platform channel is not available under
  /// `flutter test`, so a test that needs known values sets them.
  static void setForTesting({String? name, int? sdkInt}) {
    _name = name;
    _sdkInt = sdkInt;
  }
}
