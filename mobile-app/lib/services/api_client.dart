import 'dart:io' show HandshakeException, SocketException;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

import 'offline_queue.dart';
import 'response_cache.dart';

/// Thrown for any non-2xx API response, carrying the backend's Persian message.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.errors,
    this.isConnectivityError = false,
  });

  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;

  /// True when the request never reached the server at all — no signal, DNS
  /// failure, timeout — as opposed to the server answering with an error.
  /// Only this kind of failure is worth queueing for a later retry; a
  /// validation error or a 409 would just fail again identically.
  final bool isConnectivityError;

  bool get isUnauthorized => statusCode == 401;

  bool get isForbidden => statusCode == 403;

  @override
  String toString() => message;
}

/// Single entry point for every backend call. Owns the token lifecycle so no
/// screen has to think about headers.
class ApiClient {
  ApiClient({String? baseUrl})
      : _dio = Dio(
          BaseOptions(
            baseUrl: baseUrl ?? defaultBaseUrl,
            connectTimeout: const Duration(seconds: 15),
            receiveTimeout: const Duration(seconds: 20),
            headers: {'Accept': 'application/json'},
            // Let every answer flow through to _unwrap, 5xx included.
            //
            // This used to stop at 500, so a 502 became a transport
            // failure and the screen said «خطا در ارتباط با سرور.» —
            // while the server had answered with the actual reason. The
            // nanino sign-in returns 502 with what nanino said, and the
            // owner was shown a connection error for a request that
            // arrived, was understood, and was refused for a reason he
            // was never told.
            validateStatus: (status) => status != null && status < 600,
          ),
        ) {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _storage.read(key: _tokenKey);
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
      ),
    );
  }

  /// 10.0.2.2 is the Android emulator's alias for the host machine.
  /// Override with --dart-define=API_BASE_URL=... for a real device.
  ///
  /// This is only where the app starts: [ServerDirectory] looks up the
  /// address published in the repository at launch and hands it to
  /// [useBaseUrl], so the server can move without a new build.
  static const defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// Swaps the transport so a test can answer requests without a network.
  @visibleForTesting
  void useAdapterForTest(HttpClientAdapter adapter) {
    _dio.httpClientAdapter = adapter;
  }

  /// Called when the server answers 401 — the session is gone, not the
  /// signal. Set by AuthProvider, which sends the user back to sign in.
  ///
  /// Nothing acted on a 401 before this. `ApiException.isUnauthorized` had
  /// existed unused since it was written, so a revoked session left the app
  /// sitting on a screen that still looked usable, failing one call at a
  /// time. Signing in on a second device revokes the first, and the first
  /// then showed «برای دسترسی باید وارد شوید» under a form that
  /// invited you to keep typing.
  void Function()? onSessionExpired;

  /// Points every later call at a different backend.
  ///
  /// Changed on the live Dio instance rather than by building a new client,
  /// so screens already holding this one follow the move too.
  void useBaseUrl(String url) {
    _dio.options.baseUrl = url;
  }

  String get baseUrl => _dio.options.baseUrl;

  static const _tokenKey = 'auth_token';

  final Dio _dio;
  final _storage = const FlutterSecureStorage();
  final _queue = OfflineQueue();
  final _cache = ResponseCache();
  static const _uuid = Uuid();

  Future<void> saveToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  /// Identical GETs that overlap in time share one round trip.
  ///
  /// A screen builds its widgets independently, so the same figure gets
  /// asked for more than once as the tree settles — the finance tab was
  /// fetching the same series twice on every open. Each of those costs a
  /// full round trip plus ~65ms of server boot, which on a phone over a
  /// slow link is the difference the shop actually feels.
  final Map<String, Future<Map<String, dynamic>>> _inFlight = {};

  Future<Map<String, dynamic>> get(String path,
      {Map<String, dynamic>? query}) {
    final key = '$path?${_stableQuery(query)}';
    final running = _inFlight[key];

    if (running != null) return running;

    final request = _unwrap0(path, query);
    _inFlight[key] = request;

    // Cleared however it ends, so a failure is retried rather than the
    // error being handed to every later caller for the life of the app.
    return request.whenComplete(() => _inFlight.remove(key));
  }

  Future<Map<String, dynamic>> _unwrap0(
    String path,
    Map<String, dynamic>? query,
  ) async =>
      _unwrap(await _send(() => _dio.get(path, queryParameters: query)));

  /// Query keys sorted, so the same parameters in a different order are
  /// recognised as the same request.
  static String _stableQuery(Map<String, dynamic>? query) {
    if (query == null || query.isEmpty) return '';

    final keys = query.keys.toList()..sort();

    return [for (final k in keys) '$k=${query[k]}'].join('&');
  }

  /// [idempotencyKey] names this write so the server can tell a retry
  /// from a second one. Pass the *same* key on every attempt; see
  /// [postOrQueue], which is where that matters.
  Future<Map<String, dynamic>> post(
    String path, [
    Map<String, dynamic>? body,
    String? idempotencyKey,
  ]) async {
    return _unwrap(await _send(() => _dio.post(
          path,
          data: body,
          options: idempotencyKey == null
              ? null
              : Options(headers: {'Idempotency-Key': idempotencyKey}),
        )));
  }

  Future<Map<String, dynamic>> put(String path, [Map<String, dynamic>? body]) async {
    return _unwrap(await _send(() => _dio.put(path, data: body)));
  }

  Future<Map<String, dynamic>> patch(String path,
      [Map<String, dynamic>? body]) async {
    return _unwrap(await _send(() => _dio.patch(path, data: body)));
  }

  Future<Map<String, dynamic>> delete(String path) async {
    return _unwrap(await _send(() => _dio.delete(path)));
  }

  OfflineQueue get queue => _queue;

  /// Swaps the transport so a test can see what actually went on the wire.
  ///
  /// Nothing in the app calls this. It exists because the thing worth
  /// proving about idempotency is not that the server recognises a name —
  /// that has its own tests — but that the phone sends the same one twice,
  /// and no assertion about the queue's contents can show that.
  @visibleForTesting
  // ignore: avoid_setters_without_getters
  set transport(HttpClientAdapter adapter) => _dio.httpClientAdapter = adapter;

  /// When the shown copy of [path] was last fetched, or null if it is live.
  ///
  /// Keyed with the query, not the path alone. `/reports` for مرداد and
  /// `/reports` for شهریور are two answers, and sharing one marker meant a
  /// live fetch of either cleared the «this is a saved copy» mark from the
  /// other — while the saved figures were still on the screen.
  DateTime? servedFrom(String path, {Map<String, dynamic>? query}) =>
      _servedAt[ResponseCache.keyFor(path, query)];

  final Map<String, DateTime> _servedAt = {};

  /// When the app last had to answer a read from its saved copy, or null
  /// while everything on screen came from the server.
  ///
  /// `servedFrom` has existed since the cache was written and no screen
  /// ever read it, so the shop has been shown saved figures with nothing
  /// saying so — a manager reading last night's bank balance at noon has
  /// no way to tell. This is the same fact in a shape a widget can listen
  /// to.
  ///
  /// One value for the whole client rather than one per screen: it errs
  /// toward showing the warning, and a warning shown once too often costs
  /// nothing next to a stale figure shown as today's.
  final ValueNotifier<DateTime?> savedCopyAt = ValueNotifier(null);

  /// Same as [get], except the last good answer is kept and served when the
  /// server cannot be reached.
  ///
  /// Only a connectivity failure falls back. A 403 or a validation error is
  /// the server talking, and answering it from yesterday's copy would hide
  /// a real problem behind stale figures.
  Future<Map<String, dynamic>> getCached(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    final marker = ResponseCache.keyFor(path, query);

    try {
      final body = await get(path, query: query);

      _servedAt.remove(marker);
      savedCopyAt.value = null;
      await _cache.save(path, query, body);

      return body;
    } on ApiException catch (e) {
      if (!e.isConnectivityError) rethrow;

      return await _servedFromCache(path, query, marker, e);
    } catch (e) {
      // Anything that is not an ApiException never came from the server,
      // so it is the connection — and the saved copy is the right answer.
      //
      // Today `_send` turns every socket and DNS failure into an
      // ApiException, so this arm is not on any known path. It is here
      // because `postOrQueue` needed exactly this and reading did not have
      // it: one throw escaping the write path lost what a seller had just
      // typed. The cost of the same escape here is a screen that comes up
      // empty with a good copy sitting unused in storage, and the cost of
      // the arm is nothing.
      return await _servedFromCache(path, query, marker, e);
    }
  }

  /// The saved copy, or the original failure when there is none.
  ///
  /// Rethrowing matters: a screen with nothing to show must say so. An
  /// empty board presented as today's is the one answer worse than an
  /// error.
  Future<Map<String, dynamic>> _servedFromCache(
    String path,
    Map<String, dynamic>? query,
    String marker,
    Object failure,
  ) async {
    // Stale is allowed here and nowhere else. This is the arm that runs
    // when the server cannot be reached, so the choice is not «yesterday
    // or today» — it is «yesterday or nothing», and the banner says which
    // one is on screen.
    final cached = await _cache.read(path, query, allowStale: true);

    if (cached == null) throw failure;

    _servedAt[marker] = cached.at;
    savedCopyAt.value = cached.at;

    return cached.body;
  }

  /// Forgets every cached read. Called on sign-out, so the next person to
  /// use this phone is not shown the last one's figures.
  Future<void> clearCache() async {
    _servedAt.clear();
    savedCopyAt.value = null;
    await _cache.clear();
  }

  /// Whether the backend itself is answering.
  ///
  /// A phone can be firmly on wifi and still reach nothing — a café portal,
  /// a server that is down, or one that has been moved. Asking the radio
  /// gets "connected" in all three cases, so the only honest answer comes
  /// from the server saying so itself. Never throws: not reachable is an
  /// answer, not a failure.
  Future<bool> isServerReachable() async {
    try {
      final response = await _dio.get<dynamic>(
        '/health',
        options: Options(
          receiveTimeout: const Duration(seconds: 5),
          sendTimeout: const Duration(seconds: 5),
          validateStatus: (status) => status != null && status < 500,
        ),
      );

      final data = response.data;

      // The body is checked, not just the status, because a captive portal
      // answers 200 to everything.
      return response.statusCode == 200 &&
          data is Map &&
          data['service'] == 'bakery';
    } on Object {
      return false;
    }
  }

  /// Same as [post], except a connectivity-level failure — no signal, not a
  /// validation error from the server — is queued locally instead of
  /// thrown. The shop floor keeps working; nothing is lost, it is just sent
  /// once [syncQueue] next succeeds.
  ///
  /// [label] is what a "پیش‌نویس‌های ارسال‌نشده" list shows for this entry.
  /// The returned map always has 'queued': true when the record was saved
  /// locally rather than sent, so the caller can tell the user which
  /// happened.
  Future<Map<String, dynamic>> postOrQueue(
    String path,
    Map<String, dynamic> body, {
    required String label,
  }) async {
    // Minted before the first attempt, not at enqueue time, and carried
    // through every retry. Two of the failures below — a receive or send
    // timeout — mean the request reached the server and very likely ran;
    // only the answer was lost. Without a name the server could not tell
    // that replay from a real second batch, and it recorded both.
    final key = _uuid.v4();

    try {
      final response = await post(path, body, key);
      return {...response, 'queued': false};
    } on ApiException catch (e) {
      // The server answered and said no. Queueing a validation error or a
      // 409 would only replay the same refusal for ever.
      if (!e.isConnectivityError) rethrow;

      await _queue.enqueue(QueuedRequest(
        id: key,
        path: path,
        body: body,
        label: label,
        createdAt: DateTime.now(),
      ));

      return {'success': true, 'queued': true, 'data': body};
    } catch (_) {
      // Never reached the server, but arrived as something other than an
      // ApiException — a raw timeout, a socket or DNS failure. This used
      // to escape, and the entry the seller had just typed was lost with
      // it. Anything that is not the server talking is the connection.
      await _queue.enqueue(QueuedRequest(
        id: key,
        path: path,
        body: body,
        label: label,
        createdAt: DateTime.now(),
      ));

      return {'success': true, 'queued': true, 'data': body};
    }
  }

  /// Resends everything queued, in the order it was recorded. Stops at the
  /// first connectivity failure (still offline) rather than reordering
  /// what is left; a real server error drops just that one entry, since
  /// retrying it unchanged would only fail the same way again.
  Future<({int sent, int failed, int remaining})> syncQueue() async {
    var sent = 0;
    var failed = 0;

    for (final item in await _queue.all()) {
      try {
        // The id is the name the first attempt used. Same name, so a
        // write that did land is recognised rather than repeated.
        await post(item.path, item.body, item.id);
        await _queue.remove(item.id);
        sent++;
      } on ApiException catch (e) {
        if (e.isConnectivityError) {
          break; // Still offline — leave the rest queued for next time.
        }

        // The server rejected it outright (e.g. stale reference). Retrying
        // would fail identically for ever and hide everything queued
        // behind it — but deleting it, which is what happened before,
        // threw away what the person had typed and left only a counter
        // nothing displayed. It moves to the refused list instead, with
        // the server's own words, and waits to be seen.
        await _queue.reject(item, e.message);
        failed++;
      } catch (_) {
        // Not the server talking, so the connection went again mid-flush.
        // Stop and keep the rest: an unrecognised throw used to escape the
        // loop entirely, leaving this item neither sent nor removed and
        // everything behind it unexamined.
        break;
      }
    }

    return (sent: sent, failed: failed, remaining: await _queue.count());
  }

  Future<Response<dynamic>> _send(Future<Response<dynamic>> Function() call) async {
    try {
      return await call();
    } on DioException catch (e) {
      // What «could not reach the server» looks like, in all the shapes it
      // actually arrives in.
      //
      // The typed cases are the easy half. The hard half is `unknown`:
      // on Android a DNS failure — «No address associated with hostname»,
      // which is what a phone with no data gives — comes through as
      // `unknown` wrapping a SocketException, not as connectionError. It
      // was classified as a server refusal, and everything downstream that
      // asks this question got the wrong answer: sales were not queued but
      // dropped, and a cold start deleted the session.
      final cause = e.error;
      final isConnectivity = switch (e.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.receiveTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.connectionError =>
          true,
        DioExceptionType.unknown =>
          cause is SocketException || cause is HandshakeException,
        _ => false,
      };

      throw ApiException(
        switch (e.type) {
          DioExceptionType.connectionTimeout ||
          DioExceptionType.receiveTimeout =>
            'اتصال به سرور برقرار نشد. اینترنت را بررسی کنید.',
          DioExceptionType.connectionError =>
            'سرور در دسترس نیست. آدرس سرور را بررسی کنید.',
          _ => 'خطا در ارتباط با سرور.',
        },
        isConnectivityError: isConnectivity,
      );
    }
  }

  /// Every backend response uses the same {success, message, data} envelope.
  Map<String, dynamic> _unwrap(Response<dynamic> response) {
    final body = response.data;

    if (body is! Map<String, dynamic>) {
      // A 5xx that is not ours — nginx's own page when php-fpm is down,
      // say — arrives as HTML. Saying «the server is having trouble» is
      // true and useful; «the response was invalid» blames the wrong
      // thing and tells nobody what to do.
      final status = response.statusCode ?? 0;

      throw ApiException(
        status >= 500
            ? 'سرور در حال حاضر پاسخ نمی‌دهد. کمی بعد دوباره امتحان کنید.'
            : 'پاسخ سرور نامعتبر بود.',
        statusCode: response.statusCode,
      );
    }

    if (response.statusCode! >= 200 && response.statusCode! < 300) {
      return body;
    }

    // A 401 is the server refusing this token, which is not the same as
    // not reaching the server — that arrives as isConnectivityError and
    // must never end a session, or a lift with no signal signs everyone
    // out. Sign-in itself answers 422 for a wrong password, so it cannot
    // land here, but it is excluded anyway: a failed sign-in has no
    // session to expire.
    if (response.statusCode == 401 &&
        !response.requestOptions.path.endsWith('/login')) {
      onSessionExpired?.call();
    }

    throw ApiException(
      body['message'] as String? ?? 'خطای نامشخص رخ داد.',
      statusCode: response.statusCode,
      errors: body['errors'] as Map<String, dynamic>?,
    );
  }
}
