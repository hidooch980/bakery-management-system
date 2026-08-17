import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../services/bakery_api.dart';
import '../services/connection_status.dart';
import '../theme/app_theme.dart';
import 'common.dart';

/// Shows whether the shop is talking to its server and what is still
/// waiting to be sent, so a dough or chane entry recorded with no signal
/// never has to be remembered and resubmitted by hand.
///
/// The sending itself belongs to [ConnectionStatus], which knows the moment
/// the server comes back whether or not this card is on screen.
///
/// Online is read from [ConnectionStatus], which asks the server rather than
/// the radio. A phone on café wifi, or on a server that has moved, reports
/// itself connected while reaching nothing, and this card used to believe
/// it — showing "در حال ارسال" over a queue that could not go anywhere.
///
/// Renders nothing at all when the queue is empty and the server is
/// answering, which is the common case.
class SyncStatusCard extends StatefulWidget {
  const SyncStatusCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SyncStatusCard> createState() => _SyncStatusCardState();
}

class _SyncStatusCardState extends State<SyncStatusCard> {
  int _pending = 0;
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _refreshCount();
  }

  Future<void> _refreshCount() async {
    final count = await widget.api.pendingSyncCount();

    if (!mounted) return;
    setState(() => _pending = count);
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
    final connection = context.watch<ConnectionStatus>();
    final online = connection.online;

    // Sending on reconnect used to live here, and only worked while this
    // card happened to be on screen with a count it had read at mount —
    // so anything queued after that read left _pending at zero and the
    // send never fired. ConnectionStatus does it now, whatever is being
    // looked at. This card reports and offers the manual retry.
    final justSynced = connection.takeJustSynced();

    if (justSynced > 0) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        showMessage(context, '$justSynced مورد ارسال شد.');
        _refreshCount();
      });
    }

    // Nothing to say: the server is answering and nothing is waiting.
    if (online && _pending == 0) return const SizedBox.shrink();

    final scheme = Theme.of(context).colorScheme;
    final color = online ? AppColors.emberHot : AppColors.moneyOut;

    return Card(
      color: color.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Icon(
              online ? Icons.cloud_sync_rounded : Icons.cloud_off_rounded,
              color: color,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _headline(connection),
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: color,
                        ),
                  ),
                  if (_pending > 0)
                    Text(
                      '$_pending مورد ثبت‌شده در انتظار ارسال است'
                      '${online ? '' : ' — با برقراری اتصال خودکار ارسال می‌شود'}',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                    ),
                ],
              ),
            ),
            if (online && _pending > 0)
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

  /// No signal and "signal but no server" are different problems with
  /// different fixes, so they are not given the same sentence.
  String _headline(ConnectionStatus connection) {
    if (connection.online) {
      return 'در حال ارسال...';
    }

    return connection.hasRadio
        ? 'سرور در دسترس نیست'
        : 'اتصال اینترنت برقرار نیست';
  }
}
