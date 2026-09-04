import 'dart:convert';
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

  static const _version = 1;

  Database? _open;

  Future<Database> get database async => _open ??= await _openDatabase();

  /// Set once by `test/flutter_test_config.dart`, for the code that builds
  /// a queue without being handed a database — `ApiClient` does, as a
  /// field initialiser, and threading a factory down to it from every
  /// widget test would be a lot of production API existing for the tests.
  ///
  /// Null everywhere else, so the handset always gets the encrypted file.
  @visibleForTesting
  static DatabaseFactory? factoryForTesting;

  Future<Database> _openDatabase() async {
    final factory = _factory ?? factoryForTesting;

    // Tests run on the Dart VM, where the SQLCipher plugin does not exist.
    // They pass their own factory and get an unencrypted database, which
    // is the right trade: what a test proves is the SQL, and encryption is
    // a property of the file rather than of the statements run against it.
    if (factory != null) {
      return factory.openDatabase(
        _path ?? inMemoryDatabasePath,
        options: OpenDatabaseOptions(
          version: _version,
          onConfigure: _configure,
          onCreate: _createSchema,
        ),
      );
    }

    final path = _path ??
        p.join(await cipher.getDatabasesPath(), _fileName);

    // The password goes through SQLCipher's own `openDatabase`, which is
    // the only entry point that takes one.
    return cipher.openDatabase(
      path,
      password: await _password(),
      version: _version,
      onConfigure: _configure,
      onCreate: _createSchema,
    );
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
