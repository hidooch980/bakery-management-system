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

  void _set(bool value) {
    final changed = _online != value;
    _online = value;

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
