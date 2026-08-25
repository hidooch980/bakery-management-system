import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/models/user.dart';
import 'package:bakery_app/providers/auth_provider.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// Opening the app with no signal used to sign the person out.
///
/// `bootstrap()` called `/me` and caught every ApiException the same way:
/// clear the token, back to the login screen. With no network that call
/// always fails, so a cold start off the network destroyed the session —
/// and signing back in needs the network too. The offline queue, the whole
/// point of which is a shift with no signal, sat behind a login screen
/// that could not be passed.
///
/// A server that answers «401» and a server that never answered are
/// different facts, and only the first one says the token is no good.

/// Never answers — a phone out of coverage, not a server refusing.
class _NoSignal implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    throw DioException.connectionError(
      requestOptions: options,
      reason: 'no route to host',
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Answers, and refuses.
class _Refuses implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      '{"message":"Unauthenticated."}',
      401,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

const _storedUser = '{"id":7,"name":"محمد حنیف","email":null,"phone":null,'
    '"roles":["seller"],"permissions":["record-sale"]}';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  AuthProvider providerWith(HttpClientAdapter adapter) {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;

    return AuthProvider(BakeryApi(client));
  }

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('a cold start with a token and no signal', () {
    setUp(() {
      FlutterSecureStorage.setMockInitialValues({
        'auth_token': 'a-token-that-is-perfectly-good',
        'last_user_v1': _storedUser,
      });
    });

    test('stays signed in', () async {
      final auth = providerWith(_NoSignal());

      await auth.bootstrap();

      expect(auth.status, AuthStatus.authenticated);
    });

    test('keeps the token rather than deleting it', () async {
      final auth = providerWith(_NoSignal());

      await auth.bootstrap();

      // The token was never shown to be bad. Deleting it is what made this
      // unrecoverable: the way back in needed the network that was missing.
      const storage = FlutterSecureStorage();
      expect(await storage.read(key: 'auth_token'), isNotNull);
    });

    test('knows whose shift it is, so a screen can be chosen', () async {
      final auth = providerWith(_NoSignal());

      await auth.bootstrap();

      expect(auth.user?.name, 'محمد حنیف');
      expect(auth.user?.role, UserRole.seller);
      expect(auth.user?.can('record-sale'), isTrue);
    });

    test('says the session was restored rather than confirmed', () async {
      final auth = providerWith(_NoSignal());

      await auth.bootstrap();

      expect(auth.isOfflineSession, isTrue);
    });
  });

  group('a cold start where the server refuses the token', () {
    setUp(() {
      FlutterSecureStorage.setMockInitialValues({
        'auth_token': 'a-revoked-token',
        'last_user_v1': _storedUser,
      });
    });

    test('signs out, because that is a real answer', () async {
      final auth = providerWith(_Refuses());

      await auth.bootstrap();

      expect(auth.status, AuthStatus.unauthenticated);
    });

    test('drops the token and the stored user together', () async {
      final auth = providerWith(_Refuses());

      await auth.bootstrap();

      const storage = FlutterSecureStorage();
      expect(await storage.read(key: 'auth_token'), isNull);
      // Left behind, it would show the next person the last one's name.
      expect(await storage.read(key: 'last_user_v1'), isNull);
    });
  });

  group('a cold start with no signal and nothing stored', () {
    setUp(() {
      FlutterSecureStorage.setMockInitialValues({
        'auth_token': 'a-token-from-before-users-were-stored',
      });
    });

    test('asks for a password but does not throw the token away', () async {
      final auth = providerWith(_NoSignal());

      await auth.bootstrap();

      expect(auth.status, AuthStatus.unauthenticated);

      // Nothing here showed the token to be bad, and it may well be the
      // only way this phone gets back in once there is signal.
      const storage = FlutterSecureStorage();
      expect(await storage.read(key: 'auth_token'), isNotNull);
    });
  });

  group('a fingerprint that fails for want of a network', () {
    test('does not erase the saved credentials', () async {
      FlutterSecureStorage.setMockInitialValues({
        'biometric_login': 'hanif',
        'biometric_password': 'a-good-password',
      });

      final auth = providerWith(_NoSignal());

      // login() is what loginWithBiometrics() calls; a connectivity failure
      // here used to be read as «these credentials are no good» and wipe
      // them, so one walk out of coverage cost the fingerprint unlock for
      // good.
      final ok = await auth.login('hanif', 'a-good-password');

      expect(ok, isFalse);

      const storage = FlutterSecureStorage();
      expect(await storage.read(key: 'biometric_password'), isNotNull);
    });
  });
}
