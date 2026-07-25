import 'package:flutter/foundation.dart';

import '../models/user.dart';
import '../services/api_client.dart';
import '../services/bakery_api.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthProvider extends ChangeNotifier {
  AuthProvider(this._api);

  final BakeryApi _api;

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

  Future<bool> login(String login, String password) async {
    _setBusy(true);
    _error = null;

    try {
      final result = await _api.login(login, password);
      _user = result.user;
      _status = AuthStatus.authenticated;
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      return false;
    } finally {
      _setBusy(false);
    }
  }

  Future<void> logout() async {
    _setBusy(true);

    try {
      await _api.logout();
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
