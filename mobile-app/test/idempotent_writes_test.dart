import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';

/// Proof that the phone names a write and keeps the name on the retry.
///
/// The server side of this has its own tests and they pass, but they
/// prove only that a repeated name is recognised. If the app minted a
/// fresh uuid on the replay — which is exactly what the old code did,
/// because the id was generated at enqueue time and never sent — every
/// one of those server tests would still be green and the duplicate
/// batch would still be recorded. This is the half that was missing.
class _Recorder implements HttpClientAdapter {
  _Recorder(this.behave);

  /// Decides what the wire does for attempt n (1-based).
  final ResponseBody Function(int attempt) behave;

  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);

    return behave(seen.length);
  }

  @override
  void close({bool force = false}) {}

  List<String?> get keysSent =>
      [for (final r in seen) r.headers['Idempotency-Key'] as String?];
}

ResponseBody _ok(Map<String, dynamic> data) => ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  group('naming a write', () {
    test('a write that goes straight through still carries a name', () async {
      final client = ApiClient(baseUrl: 'http://test.local');
      final wire = _Recorder((_) => _ok({'id': 1}));
      client.transport = wire;

      await client.postOrQueue(
        '/dough-entries',
        {'bag_count': 10},
        label: 'خمیر — ۱۰ کیسه',
      );

      expect(wire.keysSent.single, isNotNull);
      expect(wire.keysSent.single, hasLength(greaterThan(8)));
    });

    test('the retry sends the same name as the attempt that timed out',
        () async {
      final client = ApiClient(baseUrl: 'http://test.local');

      // Attempt 1 is a receive timeout: the request reached the server and
      // very likely ran, and only the answer was lost. That is the case
      // that used to record the batch twice.
      final wire = _Recorder((attempt) {
        if (attempt == 1) {
          throw DioException(
            requestOptions: RequestOptions(path: '/dough-entries'),
            type: DioExceptionType.receiveTimeout,
          );
        }

        return _ok({'id': 1});
      });
      client.transport = wire;

      final queued = await client.postOrQueue(
        '/dough-entries',
        {'bag_count': 10},
        label: 'خمیر — ۱۰ کیسه',
      );

      expect(queued['queued'], isTrue);

      await client.syncQueue();

      expect(wire.seen, hasLength(2), reason: 'the replay never went out');

      // The whole feature is this one assertion. A fresh uuid here and the
      // server has no way to know the two are the same write.
      expect(wire.keysSent[1], wire.keysSent[0]);
      expect(wire.keysSent[0], isNotNull);
    });

    test('two separate writes are given different names', () async {
      final client = ApiClient(baseUrl: 'http://test.local');
      final wire = _Recorder((_) => _ok({'id': 1}));
      client.transport = wire;

      await client.postOrQueue('/dough-entries', {'bag_count': 10},
          label: 'یک');
      await client.postOrQueue('/dough-entries', {'bag_count': 10},
          label: 'دو');

      // Identical bodies a moment apart. A bakery really does knead two
      // matching batches in a row, and sharing a name would swallow one.
      expect(wire.keysSent[0], isNot(wire.keysSent[1]));
    });

    test('a queued write keeps its name across a restart', () async {
      final first = ApiClient(baseUrl: 'http://test.local');
      final offline = _Recorder((_) {
        throw DioException(
          requestOptions: RequestOptions(path: '/dough-entries'),
          type: DioExceptionType.connectionError,
        );
      });
      first.transport = offline;

      await first.postOrQueue('/dough-entries', {'bag_count': 10},
          label: 'خمیر');

      final nameAtQueueTime =
          (await first.queue.all()).single.id;

      // A phone that was turned off overnight and syncs in the morning.
      final second = ApiClient(baseUrl: 'http://test.local');
      final wire = _Recorder((_) => _ok({'id': 1}));
      second.transport = wire;

      await second.syncQueue();

      expect(wire.keysSent.single, nameAtQueueTime);
    });
  });
}
