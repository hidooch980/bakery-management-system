import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_common/sqflite.dart';
import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;

import 'secure_store.dart';

/// The phone's own copy of the shop, and the key it is locked with.
///
/// Everything the app kept locally used to be one JSON string per concern
/// in [SecureStore]: the whole queue rewritten on every sale, the whole
/// list re-parsed to count it. That works at three entries and is the
/// wrong shape at three hundred — a shop that has been off the network for
/// a day rewrites every pending sale each time it records the next one.
///
/// **Encrypted, and this is not optional.** `SecureStore` exists because
/// «a phone left in a taxi should cost the shop a phone», and what moves
/// in here is exactly what that sentence was written about: unsent sales,
/// with amounts, customers and notes. A plain sqlite file on Android sits
/// in the app's data directory and is readable on a rooted or seized
/// handset — moving this data out of encrypted storage into one would undo
/// the reason it was moved there in the first place. So SQLCipher, with a
/// key that never leaves [SecureStore].
class LocalDatabase {
  LocalDatabase({SecureStore? store, DatabaseFactory? factory, String? path})
      : _store = store ?? SecureStore(),
        _factory = factory,
        _path = path;

  final SecureStore _store;

  /// Injected by tests, which run on the Dart VM where the SQLCipher
  /// plugin does not exist. Production leaves it null and gets the
  /// encrypted factory below.
  final DatabaseFactory? _factory;

  final String? _path;

  static const _keyName = 'local_db_key_v1';

  static const _fileName = 'bakery_local.db';

  static const _version = 2;

  Database? _open;

  Future<Database> get database async => _open ??= await _openDatabase();

  /// Why the local database is not available, or null when it is.
  ///
  /// Read by the settings screen. Everything that uses this database
  /// treats a failure as «no saved copy», which is the right behaviour on
  /// the shop floor and the wrong one for anybody trying to find out why
  /// a phone has no saved copies at all: three releases were spent fixing
  /// layers above this one while the file underneath had never opened.
  static String? lastError;

  /// Whether the phone could open its own storage the last time it tried.
  static bool get healthy => lastError == null;

  /// Set once by `test/flutter_test_config.dart`, for the code that builds
  /// a queue without being handed a database — `ApiClient` does, as a
  /// field initialiser, and threading a factory down to it from every
  /// widget test would be a lot of production API existing for the tests.
  ///
  /// Null everywhere else, so the handset always gets the encrypted file.
  @visibleForTesting
  static DatabaseFactory? factoryForTesting;

  Future<Database> _openDatabase() async {
    try {
      final db = await _tryOpen();
      lastError = null;

      return db;
    } on Object {
      // A file that will not open is a file whose contents are already
      // unreachable, so starting a new one loses nothing that was not
      // lost — and keeping the old one loses everything from here on.
      //
      // The way this happens is the key. `_password` mints a new one
      // whenever secure storage comes back empty, and on Android that
      // entry can go: a keystore invalidated by an OS update, a backup
      // restored onto a different handset. From that moment SQLCipher
      // cannot decrypt a file the app itself wrote, every open fails,
      // and because each caller treats a failure as «nothing saved», the
      // phone quietly has no cache and no queue for the rest of the
      // install. No error, no empty state, nothing to report — just a
      // seller in a shop with no signal being told there is nothing to
      // show, release after release.
      await _discard();

      try {
        final db = await _tryOpen();
        lastError = null;

        return db;
      } on Object catch (again) {
        lastError = '$again';
        rethrow;
      }
    }
  }

  /// Where the file lives, by the same rule the open uses — so what gets
  /// discarded is always the file that would not open.
  Future<String> _resolvePath() async {
    final factory = _factory ?? factoryForTesting;

    return _path ??
        (factory != null
            ? inMemoryDatabasePath
            : p.join(await cipher.getDatabasesPath(), _fileName));
  }

  /// Removes the local file so the next open can make a fresh one.
  Future<void> _discard() async {
    final path = await _resolvePath();

    if (path == inMemoryDatabasePath) return;

    try {
      final file = File(path);

      if (file.existsSync()) await file.delete();
    } on Object {
      // Nothing else to try. The open below reports what is left.
    }
  }

  Future<Database> _tryOpen() async {
    final factory = _factory ?? factoryForTesting;

    final path = await _resolvePath();

    await _ensureDirectoryExists(path);

    // Tests run on the Dart VM, where the SQLCipher plugin does not exist.
    // They pass their own factory and get an unencrypted database, which
    // is the right trade: what a test proves is the SQL, and encryption is
    // a property of the file rather than of the statements run against it.
    if (factory != null) {
      return factory.openDatabase(
        path,
        options: OpenDatabaseOptions(
          version: _version,
          onConfigure: _configure,
          onCreate: _createSchema,
          onUpgrade: _upgradeSchema,
        ),
      );
    }

    // The password goes through SQLCipher's own `openDatabase`, which is
    // the only entry point that takes one.
    return cipher.openDatabase(
      path,
      password: await _password(),
      version: _version,
      onConfigure: _configure,
      onCreate: _createSchema,
      onUpgrade: _upgradeSchema,
    );
  }

  /// Makes the folder the file goes in, because sqlite will not.
  ///
  /// Android does not create the app's `databases/` directory for us, and
  /// opening a database inside one that is not there fails with
  /// `open_failed` and nothing else. On a fresh install that was every
  /// open: the queue could not hold a sale, the cache could not keep an
  /// answer, and the owner's home screen showed
  ///
  ///   DatabaseException(open_failed .../databases/bakery_local.db)
  ///
  /// No test caught it, because tests ran against an in-memory database
  /// where there is no directory to be missing. sqflite's own README
  /// opens with this step; it was read as advice rather than as the
  /// requirement it is.
  Future<void> _ensureDirectoryExists(String path) async {
    if (path == inMemoryDatabasePath) return;

    try {
      await Directory(p.dirname(path)).create(recursive: true);
    } on Object {
      // Already there, or unwritable. The open below answers either way,
      // and it answers better than a guess here would.
    }
  }

  /// Foreign keys are off by default in sqlite.
  Future<void> _configure(Database db) =>
      db.execute('PRAGMA foreign_keys = ON');

  Future<void> _createSchema(Database db, int version) async {
    // What has been recorded but not yet sent. `id` is the same uuid the
    // request carries as its Idempotency-Key, so a replay of a write that
    // did land is recognised by the server rather than recorded twice —
    // the queue and the wire agree on what a write is called.
    await db.execute('''
      CREATE TABLE queued_writes (
        -- Insertion order, kept explicitly. Two entries recorded in the
        -- same minute have the same `created_at`, and «the order it
        -- happened» has to be an answer rather than whatever the storage
        -- engine returns: a settlement recorded after a sale must reach
        -- the server after it.
        seq         INTEGER PRIMARY KEY AUTOINCREMENT,
        id          TEXT NOT NULL UNIQUE,
        path        TEXT NOT NULL,
        body        TEXT NOT NULL,
        label       TEXT NOT NULL DEFAULT '',
        created_at  TEXT NOT NULL
      )
    ''');

    // Sending is in the order things happened, and the shop asks «how many
    // are waiting» on every home screen. Both read this.
    await db.execute(
      'CREATE INDEX queued_writes_created_at ON queued_writes (created_at, seq)',
    );

    // What the server refused. Kept rather than deleted: what the seller
    // typed is not the server's to throw away, and until somebody has seen
    // it the only trace used to be a counter nothing displayed.
    await _createReadCache(db);

    await db.execute('''
      CREATE TABLE rejected_writes (
        seq         INTEGER PRIMARY KEY AUTOINCREMENT,
        id          TEXT NOT NULL UNIQUE,
        path        TEXT NOT NULL,
        body        TEXT NOT NULL,
        label       TEXT NOT NULL DEFAULT '',
        created_at  TEXT NOT NULL,
        reason      TEXT NOT NULL,
        rejected_at TEXT NOT NULL
      )
    ''');
  }

  /// The last good answer the server gave to each read.
  ///
  /// One row per path-and-query, replaced whenever a fresh answer arrives.
  /// Kept here rather than in [SecureStore] for the same reason the queue
  /// moved, and for one the queue did not have: nothing ever removed an
  /// entry. Every distinct report a manager opened stayed on the handset
  /// for the life of the install, and only sign-out cleared any of it.
  Future<void> _createReadCache(Database db) async {
    await db.execute("""
      CREATE TABLE cached_reads (
        cache_key TEXT PRIMARY KEY,
        body      TEXT NOT NULL,
        saved_at  TEXT NOT NULL
      )
    """);

    // Eviction reads this, oldest first.
    await db.execute(
      'CREATE INDEX cached_reads_saved_at ON cached_reads (saved_at)',
    );
  }

  Future<void> _upgradeSchema(Database db, int from, int to) async {
    if (from < 2) await _createReadCache(db);
  }

  /// The key the database file is encrypted with.
  ///
  /// Minted once on this handset and then read back for the life of the
  /// install. Losing it means losing the file, so it is written before the
  /// database that depends on it is ever opened.
  Future<String> _password() async {
    final existing = await _store.read(_keyName);

    if (existing != null && existing.isNotEmpty) return existing;

    final random = Random.secure();
    final key = base64Url.encode(
      List<int>.generate(32, (_) => random.nextInt(256)),
    );

    await _store.write(_keyName, key);

    return key;
  }

  @visibleForTesting
  Future<void> close() async {
    await _open?.close();
    _open = null;
  }
}
