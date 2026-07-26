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

  /// Local id, not a server id — used only to remove this entry once sent.
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

  Future<void> remove(String id) async {
    final prefs = await SharedPreferences.getInstance();
    final items = await all();

    await prefs.setStringList(
      _key,
      items.where((r) => r.id != id).map((r) => jsonEncode(r.toJson())).toList(),
    );
  }
}
