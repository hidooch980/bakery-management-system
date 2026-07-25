import 'dart:io';

import 'package:android_intent_plus/android_intent.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// Android will not install a downloaded APK until the user has allowed this
/// app to install unknown apps. That consent cannot be bypassed, but it can be
/// checked up front and the user sent straight to the right settings page
/// instead of hitting a confusing failure mid-install.
class InstallPermission {
  const InstallPermission._();

  /// Opens the "Install unknown apps" page scoped to this app.
  /// Returns false when the screen could not be opened.
  static Future<bool> openSettings() async {
    if (!Platform.isAndroid) return false;

    try {
      final info = await PackageInfo.fromPlatform();

      final intent = AndroidIntent(
        action: 'android.settings.MANAGE_UNKNOWN_APP_SOURCES',
        data: 'package:${info.packageName}',
      );

      await intent.launch();

      return true;
    } catch (_) {
      // Some OEM builds hide the per-app screen; fall back to the app's
      // general settings entry, which also exposes the toggle.
      return _openAppDetails();
    }
  }

  static Future<bool> _openAppDetails() async {
    try {
      final info = await PackageInfo.fromPlatform();

      await AndroidIntent(
        action: 'android.settings.APPLICATION_DETAILS_SETTINGS',
        data: 'package:${info.packageName}',
      ).launch();

      return true;
    } catch (_) {
      return false;
    }
  }
}
