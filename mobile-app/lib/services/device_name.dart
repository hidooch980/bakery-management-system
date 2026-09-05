import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';

/// What this handset should be called in the device list.
///
/// The list exists so somebody who has lost a phone can pick it out and
/// close it. Three rows all reading «mobile-app» would make that a guess,
/// which is how the wrong session gets ended and a seller is signed out
/// mid-shift.
///
/// Read once and kept: it cannot change while the app is running, and the
/// platform channel behind it is not free on a cold start at the login
/// screen.
class DeviceName {
  static String? _cached;

  /// Never throws and never returns an empty string.
  ///
  /// A name is a nicety; signing in is not. Every failure here — an
  /// unsupported platform, a channel that is not ready, a manufacturer
  /// returning nothing — falls back to a plain word, and the server
  /// substitutes its own if even that is missing.
  static Future<String> read() async {
    if (_cached != null) return _cached!;

    return _cached = await _read();
  }

  static Future<String> _read() async {
    try {
      final info = DeviceInfoPlugin();

      if (Platform.isAndroid) {
        final android = await info.androidInfo;

        // Manufacturer and model, because «SM-A546E» alone is not a phone
        // anybody recognises as theirs.
        return _tidy('${android.manufacturer} ${android.model}');
      }

      if (Platform.isIOS) {
        final ios = await info.iosInfo;

        return _tidy(ios.name.isNotEmpty ? ios.name : ios.utsname.machine);
      }
    } on Object {
      // Falls through to the default below.
    }

    return 'گوشی';
  }

  /// Collapses the whitespace some manufacturers pad their names with, and
  /// keeps it inside the 60 characters the server stores.
  static String _tidy(String raw) {
    final name = raw.replaceAll(RegExp(r'\s+'), ' ').trim();

    if (name.isEmpty) return 'گوشی';

    return name.length <= 60 ? name : name.substring(0, 60);
  }

  /// Testing seam. The platform channel is not available under
  /// `flutter test`, so a test that needs a known name sets one.
  static void setForTesting(String? name) => _cached = name;
}
