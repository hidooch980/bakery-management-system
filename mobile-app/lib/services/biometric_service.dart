import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

/// What the device can actually do, so the UI can explain itself rather
/// than just failing.
enum BiometricAvailability {
  /// Fingerprint or face is enrolled and usable.
  ready,

  /// The hardware exists but nothing is enrolled, or only a PIN is set.
  notEnrolled,

  /// No biometric hardware at all.
  unsupported,
}

/// Saves the login so the app can be unlocked with a fingerprint or face
/// instead of retyping the password.
///
/// The credentials live in `flutter_secure_storage`, which is backed by the
/// Android Keystore — they are never written to shared preferences and never
/// leave the device.
///
/// **Do not construct this before the token has been read.** It is the one
/// place left in the app that opens secure storage with
/// `encryptedSharedPreferences: true` while [ApiClient] opens it with the
/// plain default, and on Android those are two different implementations.
/// It is safe today only because a fingerprint is used after sign-in, so
/// the plain one is always opened first.
///
/// v4.68.0 broke exactly this. `SecureStore` was added with the flag and
/// ApiClient builds it as a field initialiser, which moved the flagged
/// configuration to the moment the client is constructed — the same moment
/// the token is read. Signing in worked, every request after it came back
/// 401, and the shop could not use the app. If this ever has to be built
/// earlier, make the options match ApiClient's first.
class BiometricService {
  BiometricService({LocalAuthentication? auth, FlutterSecureStorage? storage})
      : _auth = auth ?? LocalAuthentication(),
        _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  final LocalAuthentication _auth;
  final FlutterSecureStorage _storage;

  static const _enabledKey = 'biometric_enabled';
  static const _loginKey = 'biometric_login';
  static const _passwordKey = 'biometric_password';

  /// Whether the device can do fingerprint or face at all.
  Future<BiometricAvailability> availability() async {
    try {
      if (!await _auth.isDeviceSupported()) {
        return BiometricAvailability.unsupported;
      }

      // canCheckBiometrics is true for the hardware; the enrolled list tells
      // us whether the user has actually registered a finger or a face.
      final enrolled = await _auth.getAvailableBiometrics();

      return enrolled.isEmpty
          ? BiometricAvailability.notEnrolled
          : BiometricAvailability.ready;
    } on LocalAuthException {
      return BiometricAvailability.unsupported;
    } on PlatformException {
      return BiometricAvailability.unsupported;
    }
  }

  /// Which methods are enrolled, for wording the prompt.
  Future<List<BiometricType>> enrolledTypes() async {
    try {
      return await _auth.getAvailableBiometrics();
    } on LocalAuthException {
      return const [];
    } on PlatformException {
      return const [];
    }
  }

  /// "اثر انگشت"، "چهره" یا هر دو — used in the settings copy.
  Future<String> enrolledLabel() async {
    final types = await enrolledTypes();

    final hasFace = types.contains(BiometricType.face) ||
        types.contains(BiometricType.strong);
    final hasFinger = types.contains(BiometricType.fingerprint);

    if (types.contains(BiometricType.fingerprint) &&
        types.contains(BiometricType.face)) {
      return 'اثر انگشت و چهره';
    }
    if (hasFinger) return 'اثر انگشت';
    if (hasFace) return 'چهره';

    return 'قفل دستگاه';
  }

  /// Whether the user has turned the shortcut on and a login is stored.
  Future<bool> isEnabled() async {
    final flag = await _storage.read(key: _enabledKey);

    if (flag != 'true') return false;

    // A flag with no stored credentials would offer an unlock that can
    // never succeed, so both must be present.
    return await _storage.read(key: _passwordKey) != null;
  }

  Future<String?> savedLogin() => _storage.read(key: _loginKey);

  /// Stores the credentials after a successful password login.
  Future<void> enable({
    required String login,
    required String password,
  }) async {
    await _storage.write(key: _loginKey, value: login);
    await _storage.write(key: _passwordKey, value: password);
    await _storage.write(key: _enabledKey, value: 'true');
  }

  /// Forgets the saved credentials. Called when the user turns the feature
  /// off, and on logout, so a shared device does not keep them.
  Future<void> disable() async {
    await _storage.delete(key: _loginKey);
    await _storage.delete(key: _passwordKey);
    await _storage.delete(key: _enabledKey);
  }

  /// Prompts for the fingerprint or face and, if it succeeds, returns the
  /// stored credentials. Returns null on cancel or failure — the caller
  /// falls back to the password field.
  Future<({String login, String password})?> authenticate({
    String reason = 'برای ورود به برنامه، هویت خود را تأیید کنید',
  }) async {
    try {
      final ok = await _auth.authenticate(
        localizedReason: reason,
        // Not biometric-only: the device PIN or pattern is an acceptable
        // fallback when a finger is wet or a face will not read.
        biometricOnly: false,
        // Survives the prompt being backgrounded, which happens on some
        // devices when the sensor dialog appears.
        persistAcrossBackgrounding: true,
      );

      if (!ok) return null;

      final login = await _storage.read(key: _loginKey);
      final password = await _storage.read(key: _passwordKey);

      if (login == null || password == null) return null;

      return (login: login, password: password);
    } on LocalAuthException {
      // A locked-out sensor or a missing enrolment lands here; the password
      // form is still on screen, so there is nothing to recover.
      return null;
    } on PlatformException {
      return null;
    }
  }
}
