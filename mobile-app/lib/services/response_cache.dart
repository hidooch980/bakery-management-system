import 'dart:convert';

import 'secure_store.dart';

/// The last good answer the server gave to each read.
///
/// Recording a sale already survives a lost signal — it queues and goes
/// later. Reading did not: with no signal every screen came up empty, so a
/// baker who only wanted to see what had been kneaded that morning was told
/// nothing at all. Keeping the last answer means the shop can still be read
/// while it cannot be reached.
///
/// Only whole responses are kept, exactly as they arrived, so the screens
/// parse cached and live data through the same code and cannot drift.
///
/// Kept in [SecureStore] rather than a preference file. What accumulates
/// here is every read the manager makes — wages, bank balances, what each
/// customer owes — and on Android a preference file is plain XML. The
/// version in the key moved with the move: the old plaintext entries are
/// not migrated, because copying them across would leave the originals
/// sitting in the clear anyway. They expire in twelve hours regardless.
class ResponseCache {
  ResponseCache({SecureStore? store}) : _store = store ?? SecureStore();

  final SecureStore _store;

  static const _prefix = 'read_cache_v2:';

  /// Past this, a copy stops being treated as current.
  ///
  /// It does not stop being shown. The reason written here when the cache
  /// was built — «a day-old board would be read as today's and quietly
  /// mislead» — was right about the danger and wrong about the remedy, and
  /// the remedy it chose was to show nothing at all. A shop that lost its
  /// signal at closing and opens the app the next morning got an error
  /// where yesterday's figures were sitting in storage, readable.
  ///
  /// What makes the old copy safe is saying that it is old, which nothing
  /// did until `SavedCopyBanner`. So the age is now reported rather than
  /// enforced: fresh is preferred, stale is served with its hour on the
  /// screen, and only «nothing at all» is still an error.
  static const maxAge = Duration(hours: 12);

  String _key(String path, Map<String, dynamic>? query) =>
      '$_prefix${keyFor(path, query)}';

  /// How a read is named, path and query together.
  ///
  /// Public because the staleness marker has to agree with the cache about
  /// what counts as the same read. When they disagreed, a live fetch of one
  /// month's report cleared the «saved copy» mark from another month that
  /// was still on the screen.
  static String keyFor(String path, Map<String, dynamic>? query) {
    if (query == null || query.isEmpty) return path;

    // Sorted, so the same query written in a different order is one entry.
    final parts = query.entries.map((e) => '${e.key}=${e.value}').toList()
      ..sort();

    return '$path?${parts.join('&')}';
  }

  Future<void> save(
    String path,
    Map<String, dynamic>? query,
    Map<String, dynamic> body,
  ) async {
    await _store.write(
      _key(path, query),
      jsonEncode({
        'at': DateTime.now().toIso8601String(),
        'body': body,
      }),
    );
  }

  /// The stored answer, or null when there is none.
  ///
  /// [allowStale] decides what happens to a copy past [maxAge]. The
  /// default is to refuse it, because a caller that has not thought about
  /// age should not get yesterday by accident. `getCached` passes true
  /// only on the offline path, where the alternative is a blank screen.
  Future<({Map<String, dynamic> body, DateTime at, bool isStale})?> read(
    String path,
    Map<String, dynamic>? query, {
    bool allowStale = false,
  }) async {
    try {
      final raw = await _store.read(_key(path, query));

      if (raw == null) return null;

      final decoded = jsonDecode(raw);

      if (decoded is! Map) return null;

      final at = DateTime.tryParse(decoded['at'] as String? ?? '');
      final body = decoded['body'];

      if (at == null || body is! Map) return null;

      final isStale = DateTime.now().difference(at) > maxAge;

      if (isStale && !allowStale) return null;

      return (
        body: Map<String, dynamic>.from(body),
        at: at,
        isStale: isStale,
      );
    } on Object {
      return null;
    }
  }

  /// Dropped on sign-out: the next person to use this phone must not be
  /// shown the last one's figures.
  Future<void> clear() async {
    for (final key in (await _store.keys()).where((k) => k.startsWith(_prefix))) {
      await _store.delete(key);
    }
  }
}
