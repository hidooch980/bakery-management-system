import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

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
  static const _key = 'offline_queue_v1';

  /// Entries the server refused outright.
  ///
  /// They used to be deleted, and the only trace was a counter nobody
  /// displayed: what the seller had typed was gone, unnamed. Retrying
  /// them is not the answer — the server has said no and would say no
  /// again — but neither is silence. They are kept here until a person
  /// has seen them and dismissed them.
  static const _rejectedKey = 'offline_rejected_v1';

  Future<List<QueuedRequest>> all() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getStringList(_key) ?? const [];

    return raw
        .map((s) => QueuedRequest.fromJson(jsonDecode(s) as Map<String, dynamic>))
        .toList();
  }

  Future<int> count() async => (await all()).length;

  Future<void> enqueue(QueuedRequest request) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getStringList(_key) ?? const [];

    await prefs.setStringList(_key, [
      ...raw,
      jsonEncode(request.toJson()),
    ]);
  }

  Future<List<RejectedRequest>> rejected() async {
    final prefs = await SharedPreferences.getInstance();

    return (prefs.getStringList(_rejectedKey) ?? const [])
        .map((s) =>
            RejectedRequest.fromJson(jsonDecode(s) as Map<String, dynamic>))
        .toList();
  }

  Future<int> rejectedCount() async => (await rejected()).length;

  /// Moves an entry out of the queue and into the refused list, keeping
  /// what the server said so the person can see why.
  Future<void> reject(QueuedRequest request, String reason) async {
    final prefs = await SharedPreferences.getInstance();
    final existing = prefs.getStringList(_rejectedKey) ?? const [];

    await prefs.setStringList(_rejectedKey, [
      ...existing,
      jsonEncode(RejectedRequest(request: request, reason: reason).toJson()),
    ]);

    await remove(request.id);
  }

  Future<void> dismissRejected(String id) async {
    final prefs = await SharedPreferences.getInstance();
    final items = await rejected();

    await prefs.setStringList(
      _rejectedKey,
      items
          .where((r) => r.request.id != id)
          .map((r) => jsonEncode(r.toJson()))
          .toList(),
    );
  }

  Future<void> remove(String id) async {
    final prefs = await SharedPreferences.getInstance();
    final items = await all();

    await prefs.setStringList(
      _key,
      items.where((r) => r.id != id).map((r) => jsonEncode(r.toJson())).toList(),
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
