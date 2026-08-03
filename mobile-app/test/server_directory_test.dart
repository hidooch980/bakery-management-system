import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/server_directory.dart';

/// Answers the published-address lookup and each health probe from a script,
/// so a server move can be rehearsed without any of it being up.
class _FakeAdapter implements HttpClientAdapter {
  _FakeAdapter({this.directory, this.directoryStatus = 200, this.alive = const {}});

  /// The body served for server.json, or null to fail the lookup outright.
  final String? directory;
  final int directoryStatus;

  /// Which base URLs answer their health check.
  final Map<String, bool> alive;

  final List<String> requested = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final url = options.uri.toString();
    requested.add(url);

    if (url.contains('server.json')) {
      if (directory == null) {
        throw DioException.connectionError(
          requestOptions: options,
          reason: 'offline',
        );
      }

      return _json(directory!, directoryStatus);
    }

    final base = url.replaceAll('/health', '');

    if (alive[base] == true) {
      return _json('{"success":true,"service":"bakery"}', 200);
    }

    throw DioException.connectionError(
      requestOptions: options,
      reason: 'unreachable',
    );
  }

  ResponseBody _json(String body, int status) => ResponseBody.fromString(
        body,
        status,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );

  @override
  void close({bool force = false}) {}
}

ServerDirectory _directory(_FakeAdapter adapter, {String? fallback}) {
  final dio = Dio();
  dio.httpClientAdapter = adapter;

  return ServerDirectory(
    dio: dio,
    repo: 'owner/name',
    fallback: fallback ?? 'http://compiled-in.test/api/v1',
  );
}

void main() {
  const newServer = 'http://194.5.176.140:8000/api/v1';
  const oldServer = 'http://185.97.119.91:8000/api/v1';

  final bothPublished = '{"api_base_url":"$newServer",'
      '"fallback_urls":["$oldServer"]}';

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('ServerDirectory', () {
    test('takes the published address when it answers', () async {
      final directory = _directory(_FakeAdapter(
        directory: '{"api_base_url":"$newServer"}',
        alive: {newServer: true},
      ));

      expect(await directory.resolve(), newServer);
    });

    test('falls back to the second address while the move is under way',
        () async {
      // The new machine is listed but not up yet — the old one still serves.
      final directory = _directory(_FakeAdapter(
        directory: bothPublished,
        alive: {oldServer: true},
      ));

      expect(await directory.resolve(), oldServer);
    });

    test('a phone already on the old server stays there while it answers',
        () async {
      SharedPreferences.setMockInitialValues({'api_base_url': oldServer});

      final directory = _directory(_FakeAdapter(
        directory: bothPublished,
        alive: {newServer: true, oldServer: true},
      ));

      expect(await directory.resolve(), oldServer);
    });

    test('moves across by itself once the old server is switched off',
        () async {
      SharedPreferences.setMockInitialValues({'api_base_url': oldServer});

      final directory = _directory(_FakeAdapter(
        directory: bothPublished,
        alive: {newServer: true},
      ));

      expect(await directory.resolve(), newServer);
    });

    test('remembers the address it settled on', () async {
      final directory = _directory(_FakeAdapter(
        directory: bothPublished,
        alive: {newServer: true},
      ));

      await directory.resolve();

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('api_base_url'), newServer);
    });

    test('uses the remembered address when the lookup cannot be reached',
        () async {
      SharedPreferences.setMockInitialValues({'api_base_url': oldServer});

      final directory = _directory(_FakeAdapter(directory: null));

      expect(await directory.resolve(), oldServer);
    });

    test('falls back to the compiled-in address on a fresh install offline',
        () async {
      final directory = _directory(
        _FakeAdapter(directory: null),
        fallback: 'http://compiled-in.test/api/v1',
      );

      expect(await directory.resolve(), 'http://compiled-in.test/api/v1');
    });

    test('a missing server.json is not an error', () async {
      final directory = _directory(_FakeAdapter(
        directory: 'Not Found',
        directoryStatus: 404,
      ));

      expect(await directory.resolve(), 'http://compiled-in.test/api/v1');
    });

    test('something that answers but is not the bakery is never remembered',
        () async {
      // A captive portal or parked domain returns 200 to anything. The
      // published address is still what gets used — with every probe
      // failing there is no telling a dead server from a dead signal — but
      // it must not be recorded as one that was checked and worked.
      final dio = Dio();
      dio.httpClientAdapter = _CaptivePortalAdapter(newServer);

      final directory = ServerDirectory(
        dio: dio,
        repo: 'owner/name',
        fallback: 'http://compiled-in.test/api/v1',
      );

      await directory.resolve();

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('api_base_url'), isNull);
    });

    test('never probes an address twice when the file repeats it', () async {
      final adapter = _FakeAdapter(
        directory: '{"api_base_url":"$newServer","fallback_urls":["$newServer/"]}',
        alive: {newServer: true},
      );

      await _directory(adapter).resolve();

      final probes = adapter.requested.where((url) => url.endsWith('/health'));
      expect(probes, hasLength(1));
    });
  });

  group('ServerDirectory.normalise', () {
    test('drops a trailing slash so paths do not double up', () {
      expect(ServerDirectory.normalise('http://a.test/api/v1/'),
          'http://a.test/api/v1');
    });

    test('refuses anything that is not an address', () {
      expect(ServerDirectory.normalise(''), isNull);
      expect(ServerDirectory.normalise('   '), isNull);
      expect(ServerDirectory.normalise('not a url'), isNull);
      expect(ServerDirectory.normalise(42), isNull);
      expect(ServerDirectory.normalise(null), isNull);
    });
  });
}

/// Answers 200 to everything, the way a hotel wifi login page does.
class _CaptivePortalAdapter implements HttpClientAdapter {
  _CaptivePortalAdapter(this.published);

  final String published;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final body = options.uri.toString().contains('server.json')
        ? '{"api_base_url":"$published"}'
        : '{"login":"please"}';

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
