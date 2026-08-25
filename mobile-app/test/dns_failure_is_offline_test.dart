import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/models/user.dart';
import 'package:bakery_app/providers/auth_provider.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// A phone with no data does not report «connectionError».
///
/// Dio's typed connection cases cover a refused socket and the timeouts.
/// The case an Iranian phone with mobile data switched off actually
/// produces is a DNS failure — «No address associated with hostname» —
/// and Dio hands that back as `DioExceptionType.unknown` wrapping a
/// SocketException.
///
/// That was classified as «the server answered and refused», which is the
/// one thing it is not. Everything that asks the question got the wrong
/// answer: a sale taken with no signal was dropped instead of queued, and
/// a cold start deleted the session rather than restoring it.
class _DnsFailure implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    throw DioException(
      requestOptions: options,
      type: DioExceptionType.unknown,
      error: const SocketException(
        'Failed host lookup: server.test (No address associated with hostname)',
      ),
    );
  }

  @override
  void close({bool force = false}) {}
}

/// TLS that never completed — the shape a captive portal or a broken
/// middlebox produces, and equally not a refusal.
class _TlsFailure implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    throw DioException(
      requestOptions: options,
      type: DioExceptionType.unknown,
      error: const HandshakeException('Connection terminated during handshake'),
    );
  }

  @override
  void close({bool force = false}) {}
}

/// `unknown` that is genuinely not a network problem — a parse error, say.
/// This one must NOT be excused as offline, or a real fault would be
/// queued for ever and never reported.
class _RealFault implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    throw DioException(
      requestOptions: options,
      type: DioExceptionType.unknown,
      error: FormatException('not json'),
    );
  }

  @override
  void close({bool force = false}) {}
}

const _storedUser = '{"id":7,"name":"محمد حنیف","email":null,"phone":null,'
    '"roles":["seller"],"permissions":["record-sale"]}';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  ApiClient clientWith(HttpClientAdapter adapter) {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;
    return client;
  }

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({
      'auth_token': 'a-token-that-is-perfectly-good',
      'last_user_v1': _storedUser,
    });
  });

  group('a DNS failure is a phone with no data', () {
    test('the session survives a cold start', () async {
      final auth = AuthProvider(BakeryApi(clientWith(_DnsFailure())));

      await auth.bootstrap();

      expect(auth.status, AuthStatus.authenticated);
      expect(auth.user?.role, UserRole.seller);
    });

    test('the token is kept', () async {
      final auth = AuthProvider(BakeryApi(clientWith(_DnsFailure())));

      await auth.bootstrap();

      const storage = FlutterSecureStorage();
      expect(await storage.read(key: 'auth_token'), isNotNull);
    });

    test('a sale is queued rather than lost', () async {
      final client = clientWith(_DnsFailure());

      // This is the path a seller's «ثبت فروش» takes. Before, the
      // exception was rethrown and the sale existed nowhere.
      final result = await client.postOrQueue(
        '/sales',
        {'bread_count': 40, 'payment_type': 'cash'},
        label: 'ثبت فروش',
      );

      expect(result['queued'], isTrue);
    });
  });

  group('a handshake that never finished', () {
    test('is offline, not a refusal', () async {
      final auth = AuthProvider(BakeryApi(clientWith(_TlsFailure())));

      await auth.bootstrap();

      expect(auth.status, AuthStatus.authenticated);
    });
  });

  group('an unknown error that is not a network problem', () {
    test('is not excused as offline', () async {
      final client = clientWith(_RealFault());

      // Queueing this would replay a broken request for ever and hide a
      // real fault behind a «will send later» that never comes.
      await expectLater(
        client.postOrQueue('/sales', {'bread_count': 40}, label: 'ثبت فروش'),
        throwsA(isA<ApiException>()),
      );
    });
  });
}
