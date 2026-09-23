import 'package:bakery_app/models/signed_in_device.dart';
import 'package:flutter_test/flutter_test.dart';

/// Which Android each handset is on, on the device list.
///
/// A seller reported the new APK would not install; the list named the
/// model and an app version three releases old, and neither said the
/// thing that mattered — the phone was on an Android older than the app
/// has required since its seventh change.
void main() {
  SignedInDevice device({
    String? os,
    int? sdk,
    bool? canInstall,
  }) {
    return SignedInDevice.fromJson({
      'id': 1,
      'name': 'Samsung SM-J250F',
      'is_current': false,
      'app_version': '5.19.0',
      'os_version': os,
      'sdk_int': sdk,
      'can_install_updates': canInstall,
    });
  }

  test('the android is read and shown', () {
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: false);

    expect(row.osVersion, 'Android 6.0');
    expect(row.sdkInt, 23);
    expect(row.osLabel, 'Android 6.0');
  });

  test('a stranded handset says why, and says the app still works', () {
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: false);

    expect(row.strandedReason, isNotNull);
    expect(row.strandedReason, contains('Android 6.0'));

    // The phone goes on working on the build it already has, which is
    // exactly why the problem is invisible — so the row says so.
    expect(row.strandedReason, contains('کار می‌کند'));
  });

  test('a handset that can take a release is not warned about', () {
    expect(
      device(os: 'Android 7.0', sdk: 24, canInstall: true).strandedReason,
      isNull,
    );
  });

  /// A warning on a phone nobody has heard from would send somebody to
  /// replace a handset that is perfectly fine.
  test('an unreported handset is not called stranded', () {
    final row = device();

    expect(row.osLabel, 'اندروید نامشخص');
    expect(row.strandedReason, isNull);
  });

  /// The verdict is the server's, not the app's, so the panel and the
  /// phone cannot disagree about which handsets are stranded.
  test('the verdict is taken from the server rather than recomputed', () {
    // An SDK below the floor, but the server says it is fine. The app
    // must not overrule it — the floor lives in one place.
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: true);

    expect(row.strandedReason, isNull);
  });
}
