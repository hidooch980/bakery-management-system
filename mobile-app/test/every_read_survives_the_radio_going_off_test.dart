import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Which screens still have something to show with the radio off.
///
/// The saved-copy machinery has been right for a while: `getCached` keeps
/// the last good answer and serves it when the server cannot be reached,
/// the banner says the figures are a saved copy, and the cache is filled
/// at sign-in rather than waiting for someone to open the screen first.
/// All of that was built and all of it worked.
///
/// It covered half the app. Thirty-one reads still called `get`, so they
/// threw on a connectivity failure with a perfectly good copy sitting in
/// storage unused — «حساب من», the roster, what a customer owes, the
/// month's pay summary, the day's flour. A seller in a shop with no signal
/// opened their own account and got an error, and nothing in the codebase
/// was in a position to say why: each of those lines was correct on its
/// own, and the only place the gap was visible was by counting the two
/// spellings against each other.
///
/// So the split is pinned. A read that is allowed to fail without signal
/// is listed here with the reason it has to be, and every other read must
/// be a cached one. Adding a `get` to the API is now a line in this file
/// and a sentence to justify it, which is the review.
void main() {
  /// Reads that are meant to fail rather than answer from a saved copy.
  ///
  /// Both are the same kind of thing: an answer whose whole value is that
  /// it is current, where a stale «yes» is worse than an honest error.
  const mustAskTheServer = <String, String>{
    '/me': 'confirms the session is still live — a cached «yes» would '
        'outlive a session the owner had revoked',
    '/devices': 'the screen is opened because something just changed, so '
        'this morning\'s list is worse than no list',
  };

  test('every read but the two that must be current survives no signal', () {
    final source = File('lib/services/bakery_api.dart').readAsStringSync();

    final plain = RegExp(r"_client\.get\(\s*'([^']+)'")
        .allMatches(source)
        .map((m) => m.group(1)!.split('?').first)
        .toSet();

    expect(
      plain,
      mustAskTheServer.keys.toSet(),
      reason: 'A read here throws when the phone has no signal, even with a '
          'saved copy in storage. Either call getCached, or add it to '
          'mustAskTheServer with the reason it has to be current.',
    );
  });

  test('the saved copy is the default and not the exception', () {
    final source = File('lib/services/bakery_api.dart').readAsStringSync();

    final cached = RegExp(r'_client\.getCached\(').allMatches(source).length;
    final plain = RegExp(r"_client\.get\(\s*'").allMatches(source).length;

    // Not a style rule. The count is the finding: when it drifted the
    // other way nobody noticed for four releases, because no single line
    // was wrong.
    expect(
      cached,
      greaterThan(plain * 10),
      reason: 'Reads that cannot answer offline should be a named handful, '
          'not a third of the API.',
    );
  });
}
