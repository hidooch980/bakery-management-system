import 'dart:convert';

import 'package:sqflite_common/sqflite.dart';

import 'local_database.dart';
import 'secure_store.dart';

/// One write the app could not send at the time, kept for a later retry.
class QueuedRequest {
  const QueuedRequest({
    required this.id,
    required this.path,
    required this.body,
    required this.label,
    required this.createdAt,
  });

  /// The name this write was given before its first attempt.
  ///
  /// Two jobs, deliberately the same value: it removes the entry once
  /// sent, and it goes to the server as the Idempotency-Key on every
  /// attempt. That is what lets the server recognise a replay of a write
  /// that actually landed — a receive timeout looks identical to a lost
  /// request from here, and guessing wrong records the batch twice.
  final String id;

  final String path;
  final Map<String, dynamic> body;

  /// What to show in the pending-sync list, e.g. "ثبت خمیر — ۱۰ کیسه".
  final String label;

  final DateTime createdAt;

  Map<String, dynamic> toJson() => {
        'id': id,
        'path': path,
        'body': body,
        'label': label,
        'created_at': createdAt.toIso8601String(),
      };

  factory QueuedRequest.fromJson(Map<String, dynamic> json) {
    return QueuedRequest(
      id: json['id'] as String,
      path: json['path'] as String,
      body: (json['body'] as Map).cast<String, dynamic>(),
      label: json['label'] as String? ?? '',
      createdAt: DateTime.tryParse(json['created_at'] as String? ?? '') ??
          DateTime.now(),
    );
  }
}

/// Persists writes that could not reach the server, so a shop-floor screen
/// with no signal can still record work and catch up once connected.
///
/// Deliberately narrow: only a handful of "record this" endpoints opt in
/// (see BakeryApi), never reads, logins, or anything money-adjacent enough
/// that a silent retry could be the wrong call.
///
/// Backed by the encrypted local database rather than one JSON string in
/// [SecureStore]. The old shape rewrote the entire queue on every enqueue
/// and re-parsed it to answer «how many are waiting», which the home
/// screen asks constantly: recording the tenth sale of an offline morning
/// rewrote the other nine. Rows and an index do that work instead.
///
/// The public shape is unchanged, so the screens and `ApiClient` did not
/// move with it.
class OfflineQueue {
  OfflineQueue({SecureStore? store, LocalDatabase? database})
      : _store = store ?? SecureStore(),
        _database = database ?? LocalDatabase();

  final SecureStore _store;
  final LocalDatabase _database;

  /// Where the queue lived before the database, and where an upgrading
  /// handset still has its unsent sales on first launch.
  static const _legacyKey = 'offline_queue_v2';

  static const _legacyRejectedKey = 'offline_rejected_v2';

  bool _carriedOver = false;

  Future<Database> get _db async {
    final db = await _database.database;

    await _carryOverLegacy(db);

    return db;
  }

  /// Moves anything the old JSON queue was still holding into the tables.
  ///
  /// This runs once per process and matters exactly once per handset: an
  /// upgrade that happens while a phone has unsent sales on it. Dropping
  /// them here would be the failure this whole feature exists to prevent,
  /// and it would be invisible — the queue would simply be empty and the
  /// sales would never have been recorded anywhere else.
  ///
  /// The legacy keys are deleted only after the rows are committed, so an
  /// upgrade interrupted midway still has them to read next time. An id
  /// already present is left alone rather than replaced: it is the same
  /// write under the same Idempotency-Key.
  Future<void> _carryOverLegacy(Database db) async {
    if (_carriedOver) return;

    _carriedOver = true;

    for (final (key, table) in [
      (_legacyKey, 'queued_writes'),
      (_legacyRejectedKey, 'rejected_writes'),
    ]) {
      final raw = await _store.read(key);

      if (raw == null || raw.isEmpty) continue;

      try {
        final decoded = jsonDecode(raw);

        if (decoded is! List) continue;

        await db.transaction((txn) async {
          for (final row in decoded) {
            if (row is! Map) continue;

            final json = row.cast<String, dynamic>();

            await txn.insert(
              table,
              table == 'queued_writes'
                  ? _queuedRow(QueuedRequest.fromJson(json))
                  : _rejectedRow(RejectedRequest.fromJson(json)),
              conflictAlgorithm: ConflictAlgorithm.ignore,
            );
          }
        });
      } on Object {
        // Unreadable, which the old queue also tolerated: a queue that
        // will not parse must not stop the shop recording the next sale.
        // Left in place rather than deleted, so it can be looked at.
        continue;
      }

      await _store.delete(key);
    }
  }

  static Map<String, Object?> _queuedRow(QueuedRequest r) => {
        'id': r.id,
        'path': r.path,
        'body': jsonEncode(r.body),
        'label': r.label,
        'created_at': r.createdAt.toIso8601String(),
      };

  static Map<String, Object?> _rejectedRow(RejectedRequest r) => {
        ..._queuedRow(r.request),
        'reason': r.reason,
        'rejected_at': DateTime.now().toIso8601String(),
      };

  static QueuedRequest _toRequest(Map<String, Object?> row) => QueuedRequest(
        id: row['id'] as String,
        path: row['path'] as String,
        body: _decodeBody(row['body']),
        label: row['label'] as String? ?? '',
        createdAt:
            DateTime.tryParse(row['created_at'] as String? ?? '') ??
                DateTime.now(),
      );

  static Map<String, dynamic> _decodeBody(Object? raw) {
    try {
      final decoded = jsonDecode(raw as String? ?? '{}');

      return decoded is Map ? decoded.cast<String, dynamic>() : {};
    } on Object {
      return {};
    }
  }

  /// Everything waiting, oldest first — the order it will be sent in.
  Future<List<QueuedRequest>> all() async {
    final rows = await (await _db).query(
      'queued_writes',
      orderBy: 'created_at, seq',
    );

    return rows.map(_toRequest).toList();
  }

  /// Counted by the database rather than by reading the queue and taking
  /// its length. The home screen asks this on every build.
  Future<int> count() async {
    final rows =
        await (await _db).rawQuery('SELECT count(*) AS n FROM queued_writes');

    return (rows.first['n'] as num?)?.toInt() ?? 0;
  }

  Future<void> enqueue(QueuedRequest request) async {
    await (await _db).insert(
      'queued_writes',
      _queuedRow(request),
      // Same id means the same write. A retry that reaches here twice is
      // one entry, not two — the id is the Idempotency-Key.
      conflictAlgorithm: ConflictAlgorithm.ignore,
    );
  }

  Future<List<RejectedRequest>> rejected() async {
    final rows = await (await _db).query(
      'rejected_writes',
      orderBy: 'rejected_at, seq',
    );

    return rows
        .map((row) => RejectedRequest(
              request: _toRequest(row),
              reason: row['reason'] as String? ?? 'دلیلی ثبت نشد.',
            ))
        .toList();
  }

  Future<int> rejectedCount() async {
    final rows =
        await (await _db).rawQuery('SELECT count(*) AS n FROM rejected_writes');

    return (rows.first['n'] as num?)?.toInt() ?? 0;
  }

  /// Moves an entry out of the queue and into the refused list, keeping
  /// what the server said so the person can see why.
  ///
  /// One transaction: an entry that left the queue without arriving in the
  /// refused list is a sale that vanished with nothing said about it, and
  /// the two writes used to be separate.
  Future<void> reject(QueuedRequest request, String reason) async {
    final db = await _db;

    await db.transaction((txn) async {
      await txn.insert(
        'rejected_writes',
        _rejectedRow(RejectedRequest(request: request, reason: reason)),
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

      await txn.delete(
        'queued_writes',
        where: 'id = ?',
        whereArgs: [request.id],
      );
    });
  }

  Future<void> dismissRejected(String id) async {
    await (await _db)
        .delete('rejected_writes', where: 'id = ?', whereArgs: [id]);
  }

  Future<void> remove(String id) async {
    await (await _db).delete('queued_writes', where: 'id = ?', whereArgs: [id]);
  }
}

/// A queued write the server refused, and what it said.
class RejectedRequest {
  const RejectedRequest({required this.request, required this.reason});

  final QueuedRequest request;
  final String reason;

  Map<String, dynamic> toJson() => {
        'request': request.toJson(),
        'reason': reason,
      };

  factory RejectedRequest.fromJson(Map<String, dynamic> json) {
    return RejectedRequest(
      request: QueuedRequest.fromJson(
          (json['request'] as Map).cast<String, dynamic>()),
      reason: json['reason'] as String? ?? 'دلیلی ثبت نشد.',
    );
  }
}
