import 'package:flutter/material.dart';

import '../../models/staff_adjustment.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// Asking to be paid for the month, in writing, with a date on it.
///
/// The shop once went three weeks without a single payslip and nobody had
/// a way to say so except in person — which means the person who asks is
/// the one who is willing to, not the one who is owed. The server has had
/// this since then; no screen ever asked it.
///
/// No amount is entered. The wage is what was agreed, less what has been
/// drawn, and inviting a figure would start a negotiation over a number
/// the system already knows.
class MySalaryScreen extends StatefulWidget {
  const MySalaryScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<MySalaryScreen> createState() => _MySalaryScreenState();
}

class _MySalaryScreenState extends State<MySalaryScreen> {
  late Future<List<SalaryRequest>> _requests;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _requests = widget.api.mySalaryRequests();
  }

  void _reload() =>
      setState(() => _requests = widget.api.mySalaryRequests());

  Future<void> _ask() async {
    final note = await showModalBottomSheet<String?>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _AskSheet(),
    );

    if (note == null || !mounted) return;

    setState(() => _busy = true);

    try {
      await widget.api.requestSalary(note: note.isEmpty ? null : note);

      if (!mounted) return;

      showMessage(context, 'درخواست شما ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _withdraw(SalaryRequest request) async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('درخواست پس گرفته شود؟'),
        content: Text('درخواست ${request.periodLabel} حذف می‌شود.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('پس بگیر'),
          ),
        ],
      ),
    );

    if (sure != true || !mounted) return;

    try {
      await widget.api.withdrawSalaryRequest(request.id);

      if (!mounted) return;

      showMessage(context, 'درخواست پس گرفته شد.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('حقوق من')),
      body: FutureBuilder<List<SalaryRequest>>(
        future: _requests,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
          }

          final requests = snapshot.data ?? const <SalaryRequest>[];
          final pending = requests.where((r) => r.isPending).toList();

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                FilledButton.icon(
                  // One open request at a time. A second is the same
                  // sentence twice and whoever reads them cannot tell
                  // which month is being asked about.
                  onPressed: _busy || pending.isNotEmpty ? null : _ask,
                  icon: const Icon(Icons.receipt_long_rounded),
                  label: Text(
                    pending.isNotEmpty
                        ? 'یک درخواست در انتظار پاسخ دارید'
                        : 'درخواست حقوق این ماه',
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'مبلغی وارد نمی‌کنید. حقوق همان است که توافق شده،'
                  ' منهای علی‌الحسابی که گرفته‌اید.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                if (requests.isEmpty)
                  const Padding(
                    padding: EdgeInsets.only(top: 48),
                    child: EmptyState(
                      icon: Icons.receipt_long_rounded,
                      title: 'درخواستی ثبت نکرده‌اید',
                      subtitle: 'هر درخواستی که بدهید اینجا با تاریخش می‌ماند.',
                    ),
                  )
                else ...[
                  const SizedBox(height: 20),
                  for (final request in requests)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: _RequestTile(
                        request: request,
                        onWithdraw:
                            request.isPending ? () => _withdraw(request) : null,
                      ),
                    ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _RequestTile extends StatelessWidget {
  const _RequestTile({required this.request, this.onWithdraw});

  final SalaryRequest request;
  final VoidCallback? onWithdraw;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final colour = request.isPending
        ? AppColors.attention
        : request.wasRejected
            ? scheme.error
            : AppColors.moneyIn;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    request.periodLabel,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurface,
                    ),
                  ),
                ),
                Chip(
                  label: Text(
                    request.statusLabel,
                    // Explicit, because a status is the whole point of the
                    // row and a chip that inherits nothing is invisible.
                    style: TextStyle(color: colour),
                  ),
                  backgroundColor: colour.withValues(alpha: 0.14),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              request.isPending
                  ? 'ثبت شده در ${request.requestedOn} — ${request.daysWaiting} روز در انتظار'
                  : 'ثبت شده در ${request.requestedOn}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (request.estimatedNetFormatted != null) ...[
              const SizedBox(height: 6),
              Text(
                // Named as an estimate on the screen, not just in the
                // field name: it is what today's arithmetic gives, and a
                // figure about somebody's pay read as a promise is worse
                // than no figure.
                'اگر امروز پرداخت شود حدوداً ${request.estimatedNetFormatted}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurface,
                ),
              ),
            ],
            if (request.note != null && request.note!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('یادداشت شما: ${request.note}',
                  style: Theme.of(context).textTheme.bodySmall),
            ],
            if (request.decisionNote != null &&
                request.decisionNote!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                'پاسخ: ${request.decisionNote}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: scheme.error,
                ),
              ),
            ],
            if (onWithdraw != null) ...[
              const SizedBox(height: 4),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  onPressed: onWithdraw,
                  icon: const Icon(Icons.undo_rounded, size: IconSize.button),
                  label: const Text('پس گرفتن'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _AskSheet extends StatefulWidget {
  const _AskSheet();

  @override
  State<_AskSheet> createState() => _AskSheetState();
}

class _AskSheetState extends State<_AskSheet> {
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        20,
        20,
        MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'درخواست حقوق',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'این فقط می‌گوید حقوق این ماه را نگرفته‌اید. مبلغی لازم نیست.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _note,
            autofocus: true,
            maxLength: 300,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'یادداشت (اختیاری)',
              prefixIcon: Icon(Icons.notes_rounded),
            ),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: () => Navigator.pop(context, _note.text.trim()),
            child: const Text('ثبت درخواست'),
          ),
        ],
      ),
    );
  }
}
