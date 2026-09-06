import 'dart:async';
import 'dart:typed_data';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The saved copy was always there. Reaching it took a minute.
///
/// Five releases fixed real things underneath this — the missing
/// directory, the passive cache, thirty-one uncached reads, a file that
/// could not be reopened — and the owner said «کار نکرد» after every one,
/// because none of them changed the only thing he could see.
///
/// In airplane mode every read went out anyway and waited out the
/// fifteen-second connect timeout before falling back. The seller's home
/// screen fires four reads one after another, so it drew nothing for
/// something like a minute. Everything worked. Nobody stands in a shop for
/// a minute to find out.
///
/// So a client that already knows the phone is off the network reads
/// storage first. `ConnectionStatus` was asking that question all along
/// and keeping the answer to itself.
class _Hangs implements HttpClientAdapter {
  int attempts = 0;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    attempts++;

    // A radio that is off does not refuse, it does not answer. This is the
    // shape that made the timeout the thing being waited on.
    return Completer<ResponseBody>().future;
  }

  @override
  void close({bool force = false}) {}
}

class _Answers implements HttpClientAdapter {
  _Answers(this.body);

  final String body;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(body, 200, headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      });

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  test('a known-offline read answers from storage without going out',
      () async {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(_Answers(
      '{"success":true,"data":{"sales":[],"summary":{"count":0,"total_amount":7}}}',
    ));

    final api = BakeryApi(client);
    await api.todaySales();

    // The radio goes off, and something that would never answer replaces
    // the wire.
    final hangs = _Hangs();
    client.useAdapterForTest(hangs);
    client.knownOffline = true;

    final answer = await api.todaySales().timeout(const Duration(seconds: 2));

    expect(answer.total, 7);
    expect(
      hangs.attempts,
      0,
      reason: 'a doomed request was sent anyway, and the screen waited for '
          'it — which is the whole failure, not a detail of it',
    );
  });

  test('with no copy it still goes out, because offline is not unreachable',
      () async {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(_Answers(
      '{"success":true,"data":{"sales":[],"summary":{"count":0,"total_amount":3}}}',
    ));
    client.knownOffline = true;

    // Nothing saved for this read, so the radio's opinion must not stop
    // the request: the server may be on the same wifi.
    expect((await BakeryApi(client).todaySales()).total, 3);
  });
}
