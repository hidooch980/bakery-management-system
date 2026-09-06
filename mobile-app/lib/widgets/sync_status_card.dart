import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../services/bakery_api.dart';
import '../services/offline_queue.dart';
import '../services/connection_status.dart';
import '../services/local_database.dart';
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
  List<RejectedRequest> _rejected = const [];
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _refreshCount();
  }

  Future<void> _refreshCount() async {
    final count = await widget.api.pendingSyncCount();
    final refused = await widget.api.rejectedWrites();

    if (!mounted) return;
    setState(() {
      _pending = count;
      _rejected = refused;
    });
  }

  Future<void> _dismiss(RejectedRequest item) async {
    await widget.api.dismissRejectedWrite(item.request.id);
    await _refreshCount();
  }

  Future<void> _sync() async {
    if (_syncing) return;
    setState(() => _syncing = true);

    try {
      final result = await widget.api.syncPending();

      if (!mounted) return;
      setState(() => _pending = result.remaining);
      await _refreshCount();
      if (!mounted) return;

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

    // Nothing to say: the server is answering, nothing is waiting, and
    // nothing was refused. A refusal keeps the card on screen even when
    // the queue is empty — vanishing is what used to lose it.
    // A phone that cannot open its own storage keeps nothing: no saved
    // copies to read without signal, no queue to hold a sale. It used to
    // be indistinguishable from an ordinary quiet day, which is how three
    // releases were spent fixing layers above a file that never opened.
    final storageBroken = !LocalDatabase.healthy;

    if (online && _pending == 0 && _rejected.isEmpty && !storageBroken) {
      return const SizedBox.shrink();
    }

    final scheme = Theme.of(context).colorScheme;

    // A refusal is not the same problem as a queue waiting for signal:
    // the queue clears itself, this one needs a person.
    final color = _rejected.isNotEmpty || storageBroken
        ? AppColors.moneyOut
        : (online ? AppColors.attention : AppColors.moneyOut);

    return Card(
      color: color.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Icon(
              _rejected.isNotEmpty || storageBroken
                  ? Icons.report_problem_rounded
                  : (online
                      ? Icons.cloud_sync_rounded
                      : Icons.cloud_off_rounded),
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
                  if (storageBroken)
                    Text(
                      'این گوشی نمی‌تواند چیزی را برای حالت بدون اینترنت '
                      'ذخیره کند. یک بار برنامه را ببندید و باز کنید؛ اگر '
                      'باز هم این پیام بود، برنامه را حذف و دوباره نصب کنید.',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
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

                  // Named, with the server's reason, and dismissed only by
                  // hand. These used to be deleted the moment they were
                  // refused, so what a seller had typed disappeared and the
                  // only record was a counter nothing displayed.
                  for (final item in _rejected)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.request.label,
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(fontWeight: FontWeight.w700),
                                ),
                                Text(
                                  'ارسال نشد: ${item.reason}',
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(color: AppColors.moneyOut),
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            onPressed: () => _dismiss(item),
                            icon: const Icon(Icons.close_rounded, size: 18),
                            tooltip: 'متوجه شدم',
                          ),
                        ],
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
    if (!LocalDatabase.healthy) {
      return 'حافظهٔ داخلی گوشی کار نمی‌کند';
    }

    if (_rejected.isNotEmpty) {
      return '${_rejected.length} مورد ارسال نشد';
    }

    if (connection.online) {
      return 'در حال ارسال...';
    }

    return connection.hasRadio
        ? 'سرور در دسترس نیست'
        : 'اتصال اینترنت برقرار نیست';
  }
}
