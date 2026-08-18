import 'package:flutter/foundation.dart';

import '../models/user.dart';
import '../services/api_client.dart';
import '../services/bakery_api.dart';
import '../services/biometric_service.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthProvider extends ChangeNotifier {
  AuthProvider(this._api, {BiometricService? biometrics})
      : _biometrics = biometrics ?? BiometricService();

  final BakeryApi _api;
  final BiometricService _biometrics;

  BiometricService get biometrics => _biometrics;

  /// The API, for screens that need it before anyone has signed in — the
  /// forgotten-password flow is the only one, and by definition it runs
  /// with no session at all.
  BakeryApi get api => _api;

  AuthStatus _status = AuthStatus.unknown;
  AppUser? _user;
  String? _error;
  bool _busy = false;

  AuthStatus get status => _status;

  AppUser? get user => _user;

  String? get error => _error;

  bool get busy => _busy;

  /// Restores a previous session on cold start, if the stored token is valid.
  Future<void> bootstrap() async {
    final token = await _api.client.readToken();

    if (token == null) {
      _status = AuthStatus.unauthenticated;
      notifyListeners();
      return;
    }

    try {
      _user = await _api.me();
      _status = AuthStatus.authenticated;
    } on ApiException {
      await _api.client.clearToken();
      _status = AuthStatus.unauthenticated;
    }

    notifyListeners();
  }

  /// Signs in with a password. When [rememberForBiometrics] is set, the
  /// credentials are saved so the next launch can unlock with a fingerprint
  /// or face — only ever after the password itself has been accepted.
  Future<bool> login(
    String login,
    String password, {
    bool rememberForBiometrics = false,
  }) async {
    _setBusy(true);
    _error = null;

    try {
      final result = await _api.login(login, password);
      _user = result.user;
      _status = AuthStatus.authenticated;

      if (rememberForBiometrics) {
        await _biometrics.enable(login: login, password: password);
      }

      return true;
    } on ApiException catch (e) {
      _error = e.message;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  /// Unlocks with a fingerprint or face using the saved credentials.
  /// Returns false when the prompt was cancelled or nothing is stored, in
  /// which case the password form is still there to fall back on.
  Future<bool> loginWithBiometrics() async {
    final credentials = await _biometrics.authenticate();

    if (credentials == null) return false;

    final ok = await login(credentials.login, credentials.password);

    // Credentials that no longer work are worse than none: they would fail
    // silently on every launch. Drop them and make the user type again.
    if (!ok) await _biometrics.disable();

    return ok;
  }

  /// Checks a password against the server without changing who is signed in.
  ///
  /// Used before saving credentials for biometric unlock. It does issue a
  /// fresh token, which simply replaces the current one — the session
  /// continues uninterrupted.
  Future<bool> verifyPassword(String login, String password) async {
    try {
      final result = await _api.login(login, password);
      _user = result.user;
      notifyListeners();
      return true;
    } on ApiException {
      return false;
    }
  }

  Future<void> logout() async {
    _setBusy(true);

    try {
      await _api.logout();
      // A shared device must not keep one employee's saved password.
      await _biometrics.disable();
    } finally {
      _user = null;
      _status = AuthStatus.unauthenticated;
      _setBusy(false);
    }
  }

  /// The backend revokes all tokens after a password change, so the user is
  /// sent back to the login screen on success.
  Future<String?> changePassword(String current, String next) async {
    _setBusy(true);

    try {
      final message = await _api.changePassword(current: current, next: next);

      // The stored password is now wrong, so the shortcut has to be set up
      // again with the new one.
      await _biometrics.disable();

      _user = null;
      _status = AuthStatus.unauthenticated;
      return message;
    } on ApiException catch (e) {
      _error = e.message;
      return null;
    } finally {
      _setBusy(false);
    }
  }

  void clearError() {
    _error = null;
    notifyListeners();
  }

  void _setBusy(bool value) {
    _busy = value;
    notifyListeners();
  }
}
