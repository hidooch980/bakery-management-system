import 'dart:convert';

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
class OfflineQueue {
  OfflineQueue({SecureStore? store}) : _store = store ?? SecureStore();

  final SecureStore _store;

  // Held in [SecureStore], not a preference file. What waits here is
  // sales the server has not seen yet — amounts, customers, notes — and
  // on Android a preference file is plain XML that a rooted or seized
  // handset simply reads. The version moved with the move; an entry
  // written by an older build is left where it was rather than copied
  // across, which would leave the plaintext original in place anyway.
  static const _key = 'offline_queue_v2';

  /// Entries the server refused outright.
  ///
  /// They used to be deleted, and the only trace was a counter nobody
  /// displayed: what the seller had typed was gone, unnamed. Retrying
  /// them is not the answer — the server has said no and would say no
  /// again — but neither is silence. They are kept here until a person
  /// has seen them and dismissed them.
  static const _rejectedKey = 'offline_rejected_v2';

  Future<List<QueuedRequest>> all() async => _list(_key)
      .then((rows) => rows.map(QueuedRequest.fromJson).toList());

  /// Secure storage holds strings, not string lists, so each of these is
  /// one JSON array. Anything unreadable is treated as empty rather than
  /// thrown: a queue that will not parse must not stop the shop recording
  /// the next sale.
  Future<List<Map<String, dynamic>>> _list(String key) async {
    final raw = await _store.read(key);

    if (raw == null || raw.isEmpty) return [];

    try {
      final decoded = jsonDecode(raw);

      return decoded is List
          ? decoded.map((e) => (e as Map).cast<String, dynamic>()).toList()
          : [];
    } on Object {
      return [];
    }
  }

  Future<int> count() async => (await all()).length;

  Future<void> enqueue(QueuedRequest request) async {
    final rows = await _list(_key);

    await _store.write(_key, jsonEncode([...rows, request.toJson()]));
  }

  Future<List<RejectedRequest>> rejected() async =>
      _list(_rejectedKey).then((rows) => rows.map(RejectedRequest.fromJson).toList());

  Future<int> rejectedCount() async => (await rejected()).length;

  /// Moves an entry out of the queue and into the refused list, keeping
  /// what the server said so the person can see why.
  Future<void> reject(QueuedRequest request, String reason) async {
    final existing = await _list(_rejectedKey);

    await _store.write(_rejectedKey, jsonEncode([
      ...existing,
      RejectedRequest(request: request, reason: reason).toJson(),
    ]));

    await remove(request.id);
  }

  Future<void> dismissRejected(String id) async {
    final items = await rejected();

    await _store.write(
      _rejectedKey,
      jsonEncode(items
          .where((r) => r.request.id != id)
          .map((r) => r.toJson())
          .toList()),
    );
  }

  Future<void> remove(String id) async {
    final items = await all();

    await _store.write(
      _key,
      jsonEncode(items.where((r) => r.id != id).map((r) => r.toJson()).toList()),
    );
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
