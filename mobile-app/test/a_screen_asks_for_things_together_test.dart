import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// A screen that waits for one read before starting the next.
///
/// Off the network each read waits out the connect timeout, so two taken
/// in turn are two waits and four are four. That is what made the seller's
/// home screen draw nothing for something close to a minute, and it is why
/// the owner said «کار نکرد» after four releases that each fixed something
/// real underneath it. The saved copies were there the whole time; the
/// screen was waiting to be told it could not have the live ones.
///
/// This is the source rule rather than a timing test. I tried the timing
/// test first — hold every request open until four are in flight — and
/// could not get it past the harness, so the honest alternative is to pin
/// the shape: in a screen's opening reads, network calls are started
/// before any is awaited.
///
/// Deliberately narrow. It covers the methods a screen runs when it opens,
/// where the waits stack up and nobody has asked for anything yet. A
/// method that records a sale is a person pressing a button and waiting on
/// purpose, and its steps often do depend on each other.
void main() {
  /// Method names that run when a screen opens.
  bool isAnOpeningRead(String name) =>
      name == '_prepare' || name.startsWith('_load') || name == '_refresh';

  /// The awaited API calls inside one method body.
  int awaitedCallsIn(String body) =>
      RegExp(r'await\s+(widget\.)?api\.').allMatches(body).length;

  test('an opening read starts its requests before awaiting any of them', () {
    final offenders = <String>[];

    final screens = Directory('lib/screens')
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('_home_screen.dart'));

    for (final file in screens) {
      final source = file.readAsStringSync();

      // Each method: from its signature to the closing brace that
      // balances the one opening its body.
      for (final match
          in RegExp(r'Future<[^>]*>\s+(_\w+)\([^)]*\)\s+async\s*\{')
              .allMatches(source)) {
        final name = match.group(1)!;

        if (!isAnOpeningRead(name)) continue;

        var depth = 0;
        var end = match.end - 1;

        for (var i = match.end - 1; i < source.length; i++) {
          if (source[i] == '{') depth++;
          if (source[i] == '}') {
            depth--;

            if (depth == 0) {
              end = i;
              break;
            }
          }
        }

        final calls = awaitedCallsIn(source.substring(match.end, end));

        if (calls > 1) {
          offenders.add('${file.path}: $name awaits $calls reads in turn');
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'Start them first and await the futures, so the waiting '
          'overlaps instead of stacking.',
    );
  });
}
