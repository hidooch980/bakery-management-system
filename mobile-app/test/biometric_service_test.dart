import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_platform_interface/local_auth_platform_interface.dart'
    show AuthMessages;

import 'package:bakery_app/services/biometric_service.dart';

/// In-memory stand-in for the Keystore-backed storage.
class _FakeStorage implements FlutterSecureStorage {
  final Map<String, String> values = {};

  @override
  Future<String?> read({required String key, dynamic iOptions, dynamic aOptions,
      dynamic lOptions, dynamic webOptions, dynamic mOptions, dynamic wOptions}) async {
    return values[key];
  }

  @override
  Future<void> write({required String key, required String? value, dynamic iOptions,
      dynamic aOptions, dynamic lOptions, dynamic webOptions, dynamic mOptions,
      dynamic wOptions}) async {
    if (value == null) {
      values.remove(key);
    } else {
      values[key] = value;
    }
  }

  @override
  Future<void> delete({required String key, dynamic iOptions, dynamic aOptions,
      dynamic lOptions, dynamic webOptions, dynamic mOptions, dynamic wOptions}) async {
    values.remove(key);
  }

  @override
  noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

/// Scriptable stand-in for the platform prompt.
class _FakeAuth implements LocalAuthentication {
  // Each field is reassigned by the tests that care about it.
  bool supported = true;
  List<BiometricType> enrolled = const [BiometricType.fingerprint];
  bool willSucceed = true;
  int prompts = 0;

  @override
  Future<bool> isDeviceSupported() async => supported;

  @override
  Future<List<BiometricType>> getAvailableBiometrics() async => enrolled;

  @override
  Future<bool> authenticate({
    required String localizedReason,
    Iterable<AuthMessages> authMessages = const [],
    bool biometricOnly = false,
    bool sensitiveTransaction = true,
    bool persistAcrossBackgrounding = false,
  }) async {
    prompts++;
    return willSucceed;
  }

  @override
  noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  late _FakeStorage storage;
  late _FakeAuth auth;
  late BiometricService service;

  setUp(() {
    storage = _FakeStorage();
    auth = _FakeAuth();
    service = BiometricService(auth: auth, storage: storage);
  });

  group('availability', () {
    test('is ready when a finger is enrolled', () async {
      expect(await service.availability(), BiometricAvailability.ready);
    });

    test('reports not-enrolled when the hardware has no registered finger',
        () async {
      auth.enrolled = const [];

      expect(await service.availability(), BiometricAvailability.notEnrolled);
    });

    test('reports unsupported on a device without the hardware', () async {
      auth.supported = false;

      expect(await service.availability(), BiometricAvailability.unsupported);
    });
  });

  group('label', () {
    test('names the fingerprint alone', () async {
      auth.enrolled = const [BiometricType.fingerprint];

      expect(await service.enrolledLabel(), 'اثر انگشت');
    });

    test('names the face alone', () async {
      auth.enrolled = const [BiometricType.face];

      expect(await service.enrolledLabel(), 'چهره');
    });

    test('names both when both are enrolled', () async {
      auth.enrolled = const [BiometricType.fingerprint, BiometricType.face];

      expect(await service.enrolledLabel(), 'اثر انگشت و چهره');
    });
  });

  group('enable and disable', () {
    test('is off until credentials are saved', () async {
      expect(await service.isEnabled(), isFalse);
    });

    test('is on once credentials are saved', () async {
      await service.enable(login: '09120000000', password: 'secret');

      expect(await service.isEnabled(), isTrue);
      expect(await service.savedLogin(), '09120000000');
    });

    test('disabling forgets the password', () async {
      await service.enable(login: '09120000000', password: 'secret');
      await service.disable();

      expect(await service.isEnabled(), isFalse);
      expect(await service.savedLogin(), isNull);
      // The password itself must be gone, not merely flagged off.
      expect(storage.values.containsValue('secret'), isFalse);
    });

    test('a flag without a stored password does not count as enabled',
        () async {
      // Simulates a partial write; offering an unlock that cannot succeed
      // would strand the user on the login screen.
      storage.values['biometric_enabled'] = 'true';

      expect(await service.isEnabled(), isFalse);
    });
  });

  group('authenticate', () {
    test('returns the saved credentials when the prompt succeeds', () async {
      await service.enable(login: '09120000000', password: 'secret');

      final result = await service.authenticate();

      expect(result?.login, '09120000000');
      expect(result?.password, 'secret');
      expect(auth.prompts, 1);
    });

    test('returns nothing when the prompt is refused', () async {
      await service.enable(login: '09120000000', password: 'secret');
      auth.willSucceed = false;

      expect(await service.authenticate(), isNull);
    });

    test('returns nothing when there is no saved password', () async {
      expect(await service.authenticate(), isNull);
    });

    test('a refused prompt does not leak the password', () async {
      await service.enable(login: '09120000000', password: 'secret');
      auth.willSucceed = false;

      final result = await service.authenticate();

      expect(result, isNull);
      // Still stored for the next attempt, just not handed over.
      expect(await service.isEnabled(), isTrue);
    });
  });
}
