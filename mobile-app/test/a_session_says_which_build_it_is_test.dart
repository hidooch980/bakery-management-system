import 'package:bakery_app/models/signed_in_device.dart';
import 'package:flutter_test/flutter_test.dart';

/// The device list named the phone and never the build on it.
///
/// So «کار نکرد» from the shop floor could not be told apart from «کار
/// نکرد, on a build from three releases ago» — and four releases in a row
/// went into fixing things that may not have been on the handset doing the
/// complaining.
void main() {
  SignedInDevice device(Map<String, dynamic> json) =>
      SignedInDevice.fromJson({'id': 1, 'name': 'گوشی', ...json});

  group('the build on a handset', () {
    test('is shown when the phone has reported one', () {
      expect(
        device({'app_version': '5.2.0'}).versionLabel,
        'نسخهٔ 5.2.0',
      );
    });

    test('says it is unknown rather than leaving a blank', () {
      // A gap where a version belongs reads as a bug in the list. The
      // actual fact — this phone has not spoken to the server since it was
      // updated to a build that reports — is worth seeing.
      expect(device({}).versionLabel, 'نسخه نامشخص');
      expect(device({'app_version': null}).versionLabel, 'نسخه نامشخص');
    });

    test('an empty string counts as unknown, not as a version', () {
      expect(device({'app_version': ''}).versionLabel, 'نسخه نامشخص');
      expect(device({'app_version': '   '}).versionLabel, 'نسخه نامشخص');
    });

    test('the rest of the row still reads when the version is missing', () {
      final row = device({'last_used_at': '۱۴۰۵/۰۶/۱۶ — ۰۸:۳۰'});

      expect(row.appVersion, isNull);
      expect(row.when, contains('۱۴۰۵/۰۶/۱۶'));
    });
  });
}
