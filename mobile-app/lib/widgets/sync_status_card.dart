import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

import '../services/bakery_api.dart';
import 'common.dart';

/// Shows what is still waiting to be sent, and sends it the moment the
/// device reconnects — so a dough or chane entry recorded with no signal
/// never has to be remembered and resubmitted by hand.
///
/// Renders nothing at all when the queue is empty and the device is
/// online, which is the common case — this card should only ever be
/// visible when there is something to say.
class SyncStatusCard extends StatefulWidget {
  const SyncStatusCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SyncStatusCard> createState() => _SyncStatusCardState();
}

class _SyncStatusCardState extends State<SyncStatusCard> {
  int _pending = 0;
  bool _online = true;
  bool _syncing = false;
  StreamSubscription<List<ConnectivityResult>>? _subscription;

  @override
  void initState() {
    super.initState();
    _refresh();

    // Fires once for the current state and again on every change, so a
    // reconnect while the screen is open triggers a sync immediately
    // rather than waiting for the user to reopen the app.
    _subscription = Connectivity().onConnectivityChanged.listen((results) {
      final online = results.any((r) => r != ConnectivityResult.none);

      if (!mounted) return;
      setState(() => _online = online);

      if (online) _sync();
    });
  }

  @override
  void dispose() {
    _subscription?.cancel();
    super.dispose();
  }

  Future<void> _refresh() async {
    final count = await widget.api.pendingSyncCount();
    final results = await Connectivity().checkConnectivity();

    if (!mounted) return;
    setState(() {
      _pending = count;
      _online = results.any((r) => r != ConnectivityResult.none);
    });

    if (_online && _pending > 0) _sync();
  }

  Future<void> _sync() async {
    if (_syncing) return;
    setState(() => _syncing = true);

    try {
      final result = await widget.api.syncPending();

      if (!mounted) return;
      setState(() => _pending = result.remaining);

      if (result.sent > 0 && mounted) {
        showMessage(context, '${result.sent} مورد ارسال شد.');
      }
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    // Nothing to say: online with an empty queue.
    if (_online && _pending == 0) return const SizedBox.shrink();

    final scheme = Theme.of(context).colorScheme;
    final color = _online ? const Color(0xFFE8952D) : const Color(0xFFD1495B);

    return Card(
      color: color.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Icon(
              _online ? Icons.cloud_sync_rounded : Icons.cloud_off_rounded,
              color: color,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _online ? 'در حال ارسال...' : 'اتصال اینترنت برقرار نیست',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: color,
                        ),
                  ),
                  if (_pending > 0)
                    Text(
                      '$_pending مورد ثبت‌شده در انتظار ارسال است'
                      '${_online ? '' : ' — با وصل شدن اینترنت خودکار ارسال می‌شود'}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                    ),
                ],
              ),
            ),
            if (_online && _pending > 0)
              _syncing
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : IconButton(
                      onPressed: _sync,
                      icon: const Icon(Icons.sync_rounded),
                      tooltip: 'همگام‌سازی الان',
                    ),
          ],
        ),
      ),
    );
  }
}
