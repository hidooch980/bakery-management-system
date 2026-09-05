import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../models/user.dart';
import '../services/api_client.dart';
import '../services/bakery_api.dart';
import '../services/cache_warmer.dart';
import '../services/biometric_service.dart';
import '../services/secure_store.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthProvider extends ChangeNotifier {
  AuthProvider(
    this._api, {
    BiometricService? biometrics,
    SecureStore? store,
    CacheWarmer? warmer,
  })  : _biometrics = biometrics ?? BiometricService(),
        _store = store ?? SecureStore(),
        _warmer = warmer ?? CacheWarmer(_api) {
    _api.client.onSessionExpired = sessionExpired;
  }

  final BakeryApi _api;
  final BiometricService _biometrics;
  final SecureStore _store;

  /// Fills the cache for whatever this person opens first, while there is
  /// still a connection to fill it from.
  final CacheWarmer _warmer;

  /// Warms the cache for the signed-in role, if anybody is signed in.
  ///
  /// Public because `ConnectionStatus` calls it when the phone comes back
  /// online, and it is the only thing here that knows which role that is.
  /// Fire-and-forget: nothing on screen waits for it.
  void warmCache() {
    final user = _user;

    if (user == null || _status != AuthStatus.authenticated) return;

    unawaited(_warmer.warm(user.role));
  }

  /// Where the last signed-in user is kept, so a cold start with no signal
  /// still knows whose shift it is.
  static const _userKey = 'last_user_v1';

  /// True when the session was restored from that stored copy rather than
  /// confirmed with the server. Screens can say so; nothing depends on it
  /// for permission checks, which come from the stored user either way.
  bool _offline = false;

  bool get isOfflineSession => _offline;

  BiometricService get biometrics => _biometrics;

  /// The API, for screens that need it before anyone has signed in — the
  /// forgotten-password flow is the only one, and by definition it runs
  /// with no session at all.
  BakeryApi get api => _api;

  AuthStatus _status = AuthStatus.unknown;
  AppUser? _user;
  String? _error;
  bool _busy = false;

  /// Whether the most recent sign-in attempt failed for want of a network
  /// rather than for a wrong password. See [loginWithBiometrics].
  bool _lastFailureWasConnectivity = false;

  AuthStatus get status => _status;

  AppUser? get user => _user;

  String? get error => _error;

  bool get busy => _busy;

  /// Restores a previous session on cold start.
  ///
  /// The hard part is not the happy path but the failure: this used to
  /// treat every ApiException the same and clear the token. With no signal
  /// — which is the state this app was built for, and the state the
  /// offline queue exists to serve — `me()` fails, so opening the app off
  /// the network signed the person out and destroyed the session. They
  /// then could not sign back in, because signing in needs the network
  /// too. The offline queue was unreachable behind a login screen.
  ///
  /// A server that answers «401» and a server that never answered are
  /// different facts. Only the first one means the token is no good.
  Future<void> bootstrap() async {
    final token = await _api.client.readToken();

    if (token == null) {
      _status = AuthStatus.unauthenticated;
      notifyListeners();
      return;
    }

    try {
      _user = await _api.me();
      await _rememberUser(_user!);
      _offline = false;
      _status = AuthStatus.authenticated;

      // A cold start that reached the server is the first chance to take
      // the copies this person will need the next time it cannot.
      warmCache();
    } on ApiException catch (e) {
      final cached = await _storedUser();

      if (e.isConnectivityError && cached != null) {
        // Unreachable, not rejected. Keep the token and carry on with what
        // was true when there was last a signal.
        _user = cached;
        _offline = true;
        _status = AuthStatus.authenticated;
      } else if (e.isConnectivityError) {
        // No signal and nothing stored — this is a first run, or a session
        // from before users were stored. Leave the token alone: it may be
        // perfectly good, and there is nothing to gain by deleting it.
        _status = AuthStatus.unauthenticated;
      } else {
        // The server answered and would not have us.
        await _api.client.clearToken();
        await _forgetUser();
        _status = AuthStatus.unauthenticated;
      }
    }

    notifyListeners();
  }

  Future<void> _rememberUser(AppUser user) =>
      _store.write(_userKey, jsonEncode(user.toJson()));

  Future<void> _forgetUser() => _store.delete(_userKey);

  Future<AppUser?> _storedUser() async {
    final raw = await _store.read(_userKey);

    if (raw == null) return null;

    try {
      return AppUser.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      // A stored user this build cannot read is not worth crashing over.
      return null;
    }
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
      await _rememberUser(result.user);
      _offline = false;
      _status = AuthStatus.authenticated;

      warmCache();

      if (rememberForBiometrics) {
        await _biometrics.enable(login: login, password: password);
      }

      return true;
    } on ApiException catch (e) {
      _error = e.message;
      _lastFailureWasConnectivity = e.isConnectivityError;
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
    //
    // But only when the server actually refused them. A failure to reach
    // the server says nothing about whether the password is right, and
    // erasing them for that reason means one walk out of signal costs the
    // fingerprint unlock permanently.
    if (!ok && !_lastFailureWasConnectivity) await _biometrics.disable();

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

  /// The server refused this token mid-session, so the session is over.
  ///
  /// Sign-in revokes every other token for that user, so opening the app on
  /// a second phone ends the first one's session. The first phone had no
  /// way to know: it kept showing screens drawn before the refusal and
  /// failed one request at a time, which reads as a broken app rather than
  /// as being signed out.
  ///
  /// Only ever reached from a real 401. A request that never arrived is an
  /// `isConnectivityError` and is left alone — see bootstrap(), where
  /// treating the two the same once signed the shop out for having no
  /// signal.
  void sessionExpired() {
    if (_status == AuthStatus.unauthenticated) return;

    _status = AuthStatus.unauthenticated;
    _user = null;
    _offline = false;
    _error = 'نشست شما پایان یافته — دوباره وارد شوید.';

    // Fire-and-forget: the screen must change now, not after the disk.
    _api.client.clearToken();
    _forgetUser();

    notifyListeners();
  }

  Future<void> logout() async {
    _setBusy(true);

    try {
      await _api.logout();
      // A shared device must not keep one employee's saved password.
      await _biometrics.disable();
    } finally {
      // Nor one employee's name and permissions. This is the stored copy
      // bootstrap() falls back on with no signal, so leaving it would show
      // the next person the last person's shift.
      await _forgetUser();
      _user = null;
      _offline = false;
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
