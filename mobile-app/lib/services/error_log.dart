import 'package:flutter/foundation.dart';

/// The last few things that went wrong, kept where a person can read them.
///
/// This app had nowhere for a failure to go. A widget that threw was drawn
/// as a grey rectangle with no text; an error outside a build reached the
/// console, which on a handset in a shop is nobody. So the only report
/// that ever came back was «کار نکرد», and placing one of them cost five
/// releases, a photograph and a round of questions — while the actual
/// message, `type 'List<dynamic>' is not a subtype of Map`, existed all
/// along and named the file it happened in.
///
/// Small on purpose. Not a crash reporter, no server, nothing sent
/// anywhere: the shop's phone holds the shop's money and this holds only
/// what is already on its own screen. Just the last few, in memory, so
/// that «چه نوشته بود؟» has an answer.
class ErrorLog {
  ErrorLog._();

  /// Enough to cover one bad screen and what led to it. A longer list is
  /// not more useful to somebody reading it on a phone.
  static const capacity = 20;

  static final ValueNotifier<List<LoggedError>> entries =
      ValueNotifier(const []);

  static void record(Object error, {StackTrace? stack, String? where}) {
    final entry = LoggedError(
      message: '$error',
      where: where,
      at: DateTime.now(),
    );

    // Newest first: the one being asked about is the one that just
    // happened.
    entries.value = [entry, ...entries.value].take(capacity).toList();
  }

  static void clear() => entries.value = const [];
}

class LoggedError {
  const LoggedError({required this.message, required this.at, this.where});

  final String message;
  final String? where;
  final DateTime at;
}
