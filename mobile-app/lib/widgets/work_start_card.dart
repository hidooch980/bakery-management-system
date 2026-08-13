import 'package:flutter/material.dart';

import '../models/work_start.dart';
import '../services/api_client.dart';
import '../services/bakery_api.dart';
import '../theme/app_theme.dart';
import 'common.dart';

/// The daily start ticks for shaping and baking, with the deadline and the
/// salary-deduction warning.
///
/// Shown in the chane gir's, seller's and shater's cartable — whoever is
/// first in can tick it.
class WorkStartCard extends StatefulWidget {
  const WorkStartCard({super.key, required this.api, this.onChanged, this.visibleTypes});

  final BakeryApi api;

  /// Lets the host screen refresh once a tick lands.
  final VoidCallback? onChanged;

  /// Restricts which of the day's activities this card shows — e.g. the
  /// chane gir's cartable only cares about shaping, not baking, which is
  /// the shater's tick. Null (the default) shows everything on the board.
  final Set<WorkStartType>? visibleTypes;

  @override
  State<WorkStartCard> createState() => _WorkStartCardState();
}

class _WorkStartCardState extends State<WorkStartCard> {
  WorkStartBoard? _board;
  String? _error;
  bool _loading = true;
  WorkStartType? _submitting;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final board = await widget.api.workStartBoard();
      if (!mounted) return;
      setState(() {
        _board = board;
        _loading = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _tick(WorkStartItem item) async {
    setState(() => _submitting = item.type);

    try {
      final result = await widget.api.recordWorkStart(item.type);

      if (!mounted) return;

      setState(() {
        // Queued offline: the board is unknown until it syncs, so the
        // last-known board stays on screen rather than being blanked.
        if (result.board != null) _board = result.board;
        _submitting = null;
      });

      if (result.queued) {
        showMessage(
          context,
          'اینترنت وصل نیست؛ ${item.label} ذخیره شد و با اتصال بعدی ارسال می‌شود.',
        );
      } else {
        // A late tick is reported as an error so it is not mistaken for a
        // routine confirmation.
        showMessage(
          context,
          result.warning ?? '${item.label} ثبت شد.',
          isError: result.isLate,
        );
      }

      widget.onChanged?.call();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _submitting = null);
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    if (_loading) {
      return const Card(
        child: SizedBox(
          height: 120,
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }

    if (_error != null || _board == null) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: ErrorBox(message: _error ?? 'خطا', onRetry: _load),
        ),
      );
    }

    final board = _board!;
    final items = widget.visibleTypes == null
        ? board.items
        : board.items.where((i) => widget.visibleTypes!.contains(i.type)).toList();

    if (items.isEmpty) return const SizedBox.shrink();

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.alarm_rounded, color: scheme.primary),
                const SizedBox(width: 10),
                Text(
                  'شروع کار امروز',
                  style: Theme.of(context)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
                const Spacer(),
                Text(
                  board.dateDisplay,
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
              ],
            ),

            if (board.isHoliday) ...[
              const SizedBox(height: 12),
              Text(
                'امروز تعطیل است؛ مهلتی برای شروع کار در نظر گرفته نمی‌شود.',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: scheme.onSurfaceVariant),
              ),
            ],

            const SizedBox(height: 14),
            for (final item in items) ...[
              _StartRow(
                item: item,
                busy: _submitting == item.type,
                onTick: () => _tick(item),
              ),
              if (item != items.last) const SizedBox(height: 10),
            ],
          ],
        ),
      ),
    );
  }
}

class _StartRow extends StatelessWidget {
  const _StartRow({
    required this.item,
    required this.busy,
    required this.onTick,
  });

  final WorkStartItem item;
  final bool busy;
  final VoidCallback onTick;

  static const _late = Color(0xFFD1495B);
  static const _done = Color(0xFF2E9E6B);
  static const _soon = AppColors.emberHot;

  Color _tone(ColorScheme scheme) {
    if (item.isLate || item.overdue) return _late;
    if (item.started) return _done;
    if (item.isApproaching) return _soon;

    return scheme.onSurfaceVariant;
  }

  String _status() {
    if (item.started) {
      return item.isLate
          ? 'ثبت شد ${item.startedAt} — ${item.lateMinutes} دقیقه تأخیر'
          : 'ثبت شد ${item.startedAt}';
    }

    if (item.isHoliday) return 'تعطیل';
    if (item.overdue) return 'مهلت گذشته است';

    if (item.minutesRemaining != null) {
      return '${item.minutesRemaining} دقیقه تا مهلت ${item.deadline}';
    }

    return 'مهلت ${item.deadline}';
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final tone = _tone(scheme);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: tone.withValues(alpha: 0.3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                item.started
                    ? (item.isLate
                        ? Icons.warning_amber_rounded
                        : Icons.check_circle_rounded)
                    : Icons.radio_button_unchecked_rounded,
                color: tone,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.label,
                      style: Theme.of(context)
                          .textTheme
                          .bodyMedium
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    Text(
                      _status(),
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: tone),
                    ),
                  ],
                ),
              ),
              if (!item.started)
                FilledButton(
                  onPressed: busy ? null : onTick,
                  style: FilledButton.styleFrom(
                    backgroundColor: tone,
                    minimumSize: const Size(84, 38),
                  ),
                  child: busy
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Text('ثبت'),
                ),
            ],
          ),

          if (item.warning != null) ...[
            const SizedBox(height: 8),
            Text(
              item.warning!,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: _late,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ] else if (!item.started && item.overdue) ...[
            const SizedBox(height: 8),
            Text(
              'شروع نشدن تا ساعت ${item.deadline} مشمول کسر حقوق است.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: _late,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ],
        ],
      ),
    );
  }
}
