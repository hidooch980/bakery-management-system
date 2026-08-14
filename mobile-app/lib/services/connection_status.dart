import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

import 'api_client.dart';

/// Whether the shop can actually reach its own backend right now.
///
/// The radio's own answer is not enough. A phone sitting on wifi reports
/// "connected" while behind a café login page, while the server is down, and
/// while the server has been moved to another address — and in all three the
/// app would cheerfully show "آنلاین" and then fail every request. So a
/// change in the radio is only a reason to go and ask the server; the server
/// answering is what counts as online.
///
/// Nothing here throws. Not reachable is an ordinary answer.
class ConnectionStatus extends ChangeNotifier {
  ConnectionStatus(this._client, {Connectivity? connectivity})
      : _connectivity = connectivity ?? Connectivity();

  final ApiClient _client;
  final Connectivity _connectivity;

  StreamSubscription<List<ConnectivityResult>>? _subscription;
  Timer? _timer;

  bool _online = true;
  bool _checking = false;
  bool _hasRadio = true;

  /// The server answered the last time it was asked.
  bool get online => _online;

  /// A check is in flight, so the state on screen may be about to change.
  bool get checking => _checking;

  /// How many entries went up the moment the server came back, if any.
  /// Read once and cleared, so a screen can mention it and not repeat it.
  int takeJustSynced() {
    final count = _justSynced;
    _justSynced = 0;

    return count;
  }

  int _justSynced = 0;
  bool _flushing = false;

  /// The phone has some network at all. Offline with no radio is a different
  /// message to the user than offline with full signal.
  bool get hasRadio => _hasRadio;

  /// How often to re-ask while nothing else has changed. Long enough not to
  /// drain a phone left open on the counter all day, short enough that a
  /// server coming back is noticed without the user doing anything.
  static const _pollInterval = Duration(seconds: 45);

  Future<void> start() async {
    await refresh();

    _subscription = _connectivity.onConnectivityChanged.listen((results) {
      _hasRadio = results.any((r) => r != ConnectivityResult.none);

      // Losing the radio is conclusive; regaining it only means it is worth
      // asking the server again.
      if (!_hasRadio) {
        _set(false);
        return;
      }

      refresh();
    });

    _timer = Timer.periodic(_pollInterval, (_) => refresh());
  }

  /// Asks the server, and tells anyone listening if the answer changed.
  Future<void> refresh() async {
    if (_checking) return;

    _checking = true;
    notifyListeners();

    final results = await _connectivity.checkConnectivity();
    _hasRadio = results.any((r) => r != ConnectivityResult.none);

    // No radio at all means no point troubling the server with a request
    // that cannot leave the phone.
    final reachable = _hasRadio && await _client.isServerReachable();

    _checking = false;
    _set(reachable);
  }

  /// Sends whatever was written while the phone was offline.
  ///
  /// Nothing did this. Entries queued correctly and then sat there until
  /// somebody found the sync card and pressed it by hand — so a seller who
  /// recorded a morning's sales with no signal saw them stay unsent all
  /// day. Coming back online is exactly the moment to send them, and it is
  /// the moment nobody is watching for.
  Future<void> _flushQueue() async {
    if (_flushing) return;

    _flushing = true;

    try {
      final result = await _client.syncQueue();

      if (result.sent > 0) {
        _justSynced += result.sent;
        notifyListeners();
      }
    } catch (_) {
      // Sending is best effort. Anything still queued stays queued and
      // goes again on the next reconnection or poll, and a failure here
      // must never take down the thing that reports connectivity.
    } finally {
      _flushing = false;
    }
  }

  void _set(bool value) {
    final changed = _online != value;
    _online = value;

    // Only on the edge from offline to online: the poll runs every 45
    // seconds and flushing on each one would retry a genuinely rejected
    // entry for ever.
    if (changed && value) {
      unawaited(_flushQueue());
    }

    if (changed || !_checking) {
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _subscription?.cancel();
    _timer?.cancel();
    super.dispose();
  }
}
