import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

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
class ResponseCache {
  static const _prefix = 'read_cache_v1:';

  /// Anything older than this is not worth showing: a day-old board would
  /// be read as today's and quietly mislead.
  static const maxAge = Duration(hours: 12);

  String _key(String path, Map<String, dynamic>? query) {
    if (query == null || query.isEmpty) {
      return '$_prefix$path';
    }

    // Sorted, so the same query written in a different order is one entry.
    final parts = query.entries.map((e) => '${e.key}=${e.value}').toList()..sort();

    return '$_prefix$path?${parts.join('&')}';
  }

  Future<void> save(
    String path,
    Map<String, dynamic>? query,
    Map<String, dynamic> body,
  ) async {
    try {
      final prefs = await SharedPreferences.getInstance();

      await prefs.setString(
        _key(path, query),
        jsonEncode({
          'at': DateTime.now().toIso8601String(),
          'body': body,
        }),
      );
    } on Object {
      // A cache that will not write is not worth failing a good request over.
    }
  }

  /// The stored answer, or null when there is none or it is too old.
  Future<({Map<String, dynamic> body, DateTime at})?> read(
    String path,
    Map<String, dynamic>? query,
  ) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_key(path, query));

      if (raw == null) return null;

      final decoded = jsonDecode(raw);

      if (decoded is! Map) return null;

      final at = DateTime.tryParse(decoded['at'] as String? ?? '');
      final body = decoded['body'];

      if (at == null || body is! Map) return null;

      if (DateTime.now().difference(at) > maxAge) return null;

      return (body: Map<String, dynamic>.from(body), at: at);
    } on Object {
      return null;
    }
  }

  /// Dropped on sign-out: the next person to use this phone must not be
  /// shown the last one's figures.
  Future<void> clear() async {
    try {
      final prefs = await SharedPreferences.getInstance();

      for (final key in prefs.getKeys().where((k) => k.startsWith(_prefix))) {
        await prefs.remove(key);
      }
    } on Object {
      // Nothing to be done, and nothing worth interrupting a logout for.
    }
  }
}
