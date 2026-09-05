import 'package:flutter/foundation.dart';

import '../models/user.dart';
import 'bakery_api.dart';

/// Fetches the screens a role opens first, while there is still signal.
///
/// The read cache has always been passive: it holds whatever somebody
/// happened to open while online, and nothing else. So «works offline»
/// quietly meant «works offline on the screens you visited earlier
/// today», which nobody is told and nobody would think to do.
///
/// The owner found the sharp edge of it. He installed the release that
/// finally made the local database open, went straight to flight mode to
/// try it, and every screen was empty — correctly, because the database
/// had been broken until minutes before and there was nothing in it. The
/// app had never been wrong; it had simply never been given anything to
/// remember.
///
/// So the reads a role actually needs are fetched once, in the
/// background, whenever there is a connection: at sign-in and again each
/// time the phone comes back online. By the time the signal goes, the
/// answers are already on the handset.
///
/// Deliberately small. This is the shop floor's «what am I meant to be
/// doing» — not reports, which are read at a desk with signal, and not
/// anything that changes by the minute.
class CacheWarmer {
  CacheWarmer(this._api);

  final BakeryApi _api;

  /// Guards against two warms at once — coming back online can fire the
  /// connectivity listener and the poll within the same second.
  bool _warming = false;

  /// What each role opens without thinking, in the order it meets them.
  ///
  /// A role gets only its own: fetching a seller's board for the dough
  /// maker would be a 403 and a wasted request on a phone that is often
  /// on a slow connection.
  List<Future<void> Function()> _reads(UserRole role) {
    // Everyone: the shop's own settings decide bread price, sack weight
    // and the currency every other screen formats with.
    final shared = <Future<void> Function()>[
      () => _api.bakery(),
      () => _api.attendanceToday(),
    ];

    return switch (role) {
      UserRole.admin => [
          ...shared,
          () => _api.today(),
          () => _api.dashboard(),
          () => _api.inventory(),
          () => _api.chaneBoard(),
          // The two the expense and income forms refuse to save without.
          () => _api.expenseCategories(),
          () => _api.incomeCategories(),
        ],
      UserRole.seller => [
          ...shared,
          () => _api.pendingChane(),
          () => _api.saleStaff(),
          () => _api.myLateness(),
        ],
      UserRole.doughMaker => [
          ...shared,
          () => _api.myDoughHistory(),
          () => _api.pendingDough(),
        ],
      UserRole.chaneGir || UserRole.shater => [
          ...shared,
          () => _api.pendingDough(),
          () => _api.pendingChane(),
          () => _api.myChaneHistory(),
        ],
      UserRole.unknown => shared,
    };
  }

  /// Fills the cache for [role]. Never throws and never blocks.
  ///
  /// Each read is awaited in turn rather than all at once: this runs
  /// behind whatever the person is actually doing, and eight parallel
  /// requests on a shop's connection would slow down the screen they are
  /// looking at to prepare screens they are not.
  ///
  /// A failure is skipped in silence. Warming is an optimisation — a
  /// permission this role turns out not to have, or a connection that
  /// went again mid-way, must not produce anything the user sees.
  Future<void> warm(UserRole role) async {
    if (_warming) return;

    _warming = true;

    try {
      for (final read in _reads(role)) {
        try {
          await read();
        } on Object {
          // Skipped on purpose. See above.
        }
      }
    } finally {
      _warming = false;
    }
  }

  @visibleForTesting
  int readCountFor(UserRole role) => _reads(role).length;
}
