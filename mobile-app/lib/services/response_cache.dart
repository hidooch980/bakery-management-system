import 'dart:convert';

import 'package:sqflite_common/sqflite.dart';

import 'local_database.dart';
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
/// Rows in the encrypted local database. What accumulates here is every
/// read the manager makes — wages, bank balances, what each customer owes
/// — so it belongs behind the same key as the queue, and for the same
/// reason: a phone left in a taxi should cost the shop a phone.
///
/// It was one entry per path in [SecureStore] before, and nothing ever
/// removed one. Every distinct report anybody opened stayed on the handset
/// for the life of the install, and only signing out cleared any of it. A
/// cache that only grows is a leak with a friendly name, so this one has a
/// ceiling and drops its oldest answers.
class ResponseCache {
  ResponseCache({SecureStore? store, LocalDatabase? database})
      : _store = store ?? SecureStore(),
        _database = database ?? LocalDatabase();

  final SecureStore _store;
  final LocalDatabase _database;

  /// Where the cache lived before the database.
  static const _legacyPrefix = 'read_cache_v2:';

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

  /// How many answers to keep.
  ///
  /// Nothing ever removed one before: every distinct report a manager
  /// opened stayed on the handset for the life of the install, and only
  /// signing out cleared any of it. A cache that only grows is a leak with
  /// a friendly name.
  ///
  /// Two hundred is roughly «everything a person looked at this week» and
  /// well past what any screen needs at once.
  static const maxEntries = 200;

  bool _sweptLegacy = false;

  Future<Database> get _db async {
    final db = await _database.database;

    await _dropLegacy();

    return db;
  }

  /// Forgets the old secure-storage copies.
  ///
  /// Dropped rather than carried across, unlike the queue. This is a
  /// cache: the worst an empty one costs is one screen that has to be
  /// online the first time it is opened after an upgrade, and the queue's
  /// migration exists because losing *that* costs a day of sales.
  Future<void> _dropLegacy() async {
    if (_sweptLegacy) return;

    _sweptLegacy = true;

    try {
      for (final key
          in (await _store.keys()).where((k) => k.startsWith(_legacyPrefix))) {
        await _store.delete(key);
      }
    } on Object {
      // Nothing here is worth failing a read for.
    }
  }

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
    final db = await _db;

    await db.insert(
      'cached_reads',
      {
        'cache_key': keyFor(path, query),
        'body': jsonEncode(body),
        'saved_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );

    await _evict(db);
  }

  /// Drops the oldest answers once there are more than [maxEntries].
  ///
  /// Oldest by when it was saved, which is the closest thing to «least
  /// likely to be wanted» that costs nothing to record.
  Future<void> _evict(Database db) async {
    await db.rawDelete(
      'DELETE FROM cached_reads WHERE cache_key NOT IN ('
      ' SELECT cache_key FROM cached_reads ORDER BY saved_at DESC LIMIT ?'
      ')',
      [maxEntries],
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
      final rows = await (await _db).query(
        'cached_reads',
        where: 'cache_key = ?',
        whereArgs: [keyFor(path, query)],
        limit: 1,
      );

      if (rows.isEmpty) return null;

      final at = DateTime.tryParse(rows.first['saved_at'] as String? ?? '');
      final decoded = jsonDecode(rows.first['body'] as String? ?? '');

      if (at == null || decoded is! Map) return null;

      final isStale = DateTime.now().difference(at) > maxAge;

      if (isStale && !allowStale) return null;

      return (
        body: Map<String, dynamic>.from(decoded),
        at: at,
        isStale: isStale,
      );
    } on Object {
      // A cache that will not read must not stop the shop working; the
      // request simply goes to the server, which is where it was going
      // before any of this existed.
      return null;
    }
  }

  /// Dropped on sign-out: the next person to use this phone must not be
  /// shown the last one's figures.
  Future<void> clear() async {
    await (await _db).delete('cached_reads');
  }
}
