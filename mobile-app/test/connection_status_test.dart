import 'dart:async';
import 'dart:typed_data';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/connection_status.dart';

/// Answers the health probe from a script, so "the server is up" and "the
/// server is gone" can both be rehearsed without either being true.
class _HealthAdapter implements HttpClientAdapter {
  _HealthAdapter({
    this.body = '{"success":true,"service":"bakery"}',
    this.throws = false,
  });

  String body;
  bool throws;
  int calls = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    calls++;

    if (throws) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'unreachable',
      );
    }

    return ResponseBody.fromString(
      body,
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Stands in for the radio, which in tests is whatever we say it is.
class _FakeConnectivity implements Connectivity {
  _FakeConnectivity(this._current);

  List<ConnectivityResult> _current;
  final _controller = StreamController<List<ConnectivityResult>>.broadcast();

  void emit(List<ConnectivityResult> results) {
    _current = results;
    _controller.add(results);
  }

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => _current;

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _controller.stream;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

ApiClient _clientWith(_HealthAdapter adapter) {
  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(adapter);
  return client;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    // The client attaches a bearer token from secure storage on every
    // request; without a stand-in the plugin channel throws and every
    // probe would look like an unreachable server.
    FlutterSecureStorage.setMockInitialValues({});
  });

  group('ConnectionStatus', () {
    test('online when the server answers as the bakery', () async {
      final status = ConnectionStatus(
        _clientWith(_HealthAdapter()),
        connectivity: _FakeConnectivity([ConnectivityResult.wifi]),
      );

      await status.refresh();

      expect(status.online, isTrue);
      expect(status.hasRadio, isTrue);
    });

    test('offline when the server cannot be reached', () async {
      final status = ConnectionStatus(
        _clientWith(_HealthAdapter(throws: true)),
        connectivity: _FakeConnectivity([ConnectivityResult.wifi]),
      );

      await status.refresh();

      // Full signal, no server — the case the radio alone gets wrong.
      expect(status.online, isFalse);
      expect(status.hasRadio, isTrue);
    });

    test('something answering that is not the bakery is not online', () async {
      // A café login page returns 200 to anything.
      final status = ConnectionStatus(
        _clientWith(_HealthAdapter(body: '{"login":"please"}')),
        connectivity: _FakeConnectivity([ConnectivityResult.wifi]),
      );

      await status.refresh();

      expect(status.online, isFalse);
    });

    test('no radio is offline without troubling the server', () async {
      final adapter = _HealthAdapter();

      final status = ConnectionStatus(
        _clientWith(adapter),
        connectivity: _FakeConnectivity([ConnectivityResult.none]),
      );

      await status.refresh();

      expect(status.online, isFalse);
      expect(status.hasRadio, isFalse);
      // A request that cannot leave the phone is not worth making.
      expect(adapter.calls, 0);
    });

    test('losing the radio goes offline at once', () async {
      final connectivity = _FakeConnectivity([ConnectivityResult.wifi]);
      final status = ConnectionStatus(_clientWith(_HealthAdapter()), connectivity: connectivity);

      await status.start();
      expect(status.online, isTrue);

      connectivity.emit([ConnectivityResult.none]);
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(status.online, isFalse);

      status.dispose();
    });

    test('regaining the radio asks the server again', () async {
      final adapter = _HealthAdapter(throws: true);
      final connectivity = _FakeConnectivity([ConnectivityResult.none]);
      final status = ConnectionStatus(_clientWith(adapter), connectivity: connectivity);

      await status.start();
      expect(status.online, isFalse);

      // Signal is back and so is the server.
      adapter.throws = false;
      connectivity.emit([ConnectivityResult.wifi]);
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(status.online, isTrue);

      status.dispose();
    });

    test('tells listeners when the answer changes', () async {
      final adapter = _HealthAdapter();
      final status = ConnectionStatus(
        _clientWith(adapter),
        connectivity: _FakeConnectivity([ConnectivityResult.wifi]),
      );

      var notifications = 0;
      status.addListener(() => notifications++);

      await status.refresh();
      expect(status.online, isTrue);

      adapter.throws = true;
      await status.refresh();

      expect(status.online, isFalse);
      expect(notifications, greaterThan(0));

      status.dispose();
    });
  });
}
