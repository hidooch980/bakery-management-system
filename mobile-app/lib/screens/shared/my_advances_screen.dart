import 'package:flutter/material.dart';

import '../../models/quota_and_advance.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

typedef _AdvanceData = ({
  List<StaffAdvance> advances,
  String summary,
  double outstanding,
  List<AdvanceRequest> requests,
  bool hasPending,
});

/// What this person has drawn against their pay, and asking for more.
///
/// Both used to happen in the doorway: the advance was written in a book
/// the person who took it never saw, and the asking left no record of who
/// asked or what was said back. The one whose pay is about to be short is
/// the one who most needs to see it.
class MyAdvancesScreen extends StatefulWidget {
  const MyAdvancesScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<MyAdvancesScreen> createState() => _MyAdvancesScreenState();
}

class _MyAdvancesScreenState extends State<MyAdvancesScreen> {
  late Future<_AdvanceData> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_AdvanceData> _load() async {
    final results = await Future.wait([
      widget.api.myAdvances(),
      widget.api.myAdvanceRequests(),
    ]);

    final mine = results[0] as ({
      List<StaffAdvance> advances,
      String summary,
      double outstanding,
    });
    final asked = results[1] as ({
      List<AdvanceRequest> requests,
      bool hasPending,
    });

    return (
      advances: mine.advances,
      summary: mine.summary,
      outstanding: mine.outstanding,
      requests: asked.requests,
      hasPending: asked.hasPending,
    );
  }

  void _reload() => setState(() => _data = _load());

  Future<void> _ask() async {
    final result = await showModalBottomSheet<({double amount, String? reason})>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _RequestSheet(),
    );

    if (result == null) return;

    try {
      await widget.api.requestAdvance(
        amount: result.amount,
        reason: result.reason,
      );

      if (!mounted) return;
      showMessage(context, 'درخواست شما ثبت و برای مدیر ارسال شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _withdraw(AdvanceRequest request) async {
    try {
      await widget.api.withdrawAdvanceRequest(request.id);
      if (!mounted) return;
      showMessage(context, 'درخواست پس گرفته شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('علی‌الحساب من')),
      body: FutureBuilder<_AdvanceData>(
        future: _data,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
          }

          final data = snapshot.data!;

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _SummaryCard(
                  summary: data.summary,
                  owesSomething: data.outstanding > 0,
                ),
                const SizedBox(height: 16),
                FilledButton.icon(
                  // One open request at a time: a second is the same
                  // conversation twice, and whoever answers cannot tell
                  // which one is meant.
                  onPressed: data.hasPending ? null : _ask,
                  icon: const Icon(Icons.pan_tool_alt_rounded),
                  label: Text(
                    data.hasPending
                        ? 'یک درخواست در انتظار پاسخ دارید'
                        : 'درخواست علی‌الحساب',
                  ),
                ),
                if (data.requests.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  Text(
                    'درخواست‌های من',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 8),
                  for (final request in data.requests)
                    _RequestTile(
                      request: request,
                      onWithdraw:
                          request.isPending ? () => _withdraw(request) : null,
                    ),
                ],
                if (data.advances.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  Text(
                    'دریافتی‌ها',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 8),
                  for (final advance in data.advances)
                    _AdvanceTile(advance: advance),
                ],
                if (data.advances.isEmpty && data.requests.isEmpty)
                  const Padding(
                    padding: EdgeInsets.only(top: 40),
                    child: EmptyState(
                      icon: Icons.wallet_outlined,
                      title: 'چیزی ثبت نشده',
                      subtitle: 'تا امروز علی‌الحسابی نگرفته‌اید.',
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.summary, required this.owesSomething});

  final String summary;
  final bool owesSomething;

  @override
  Widget build(BuildContext context) {
    final colour =
        owesSomething ? AppColors.attention : AppColors.moneyIn;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(
              owesSomething
                  ? Icons.info_outline_rounded
                  : Icons.check_circle_outline_rounded,
              color: colour,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                summary,
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AdvanceTile extends StatelessWidget {
  const _AdvanceTile({required this.advance});

  final StaffAdvance advance;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        title: Text(advance.amountFormatted),
        subtitle: Text(
          advance.isSettled
              ? '${advance.paidOnLabel} · از حقوق کسر شد'
              : '${advance.paidOnLabel} · مانده ${advance.outstandingFormatted}',
        ),
        trailing: Icon(
          advance.isSettled
              ? Icons.check_circle_rounded
              : Icons.schedule_rounded,
          color: advance.isSettled
              ? AppColors.moneyIn
              : AppColors.attention,
        ),
      ),
    );
  }
}

class _RequestTile extends StatelessWidget {
  const _RequestTile({required this.request, this.onWithdraw});

  final AdvanceRequest request;
  final VoidCallback? onWithdraw;

  @override
  Widget build(BuildContext context) {
    final colour = switch (request.status) {
      'approved' => AppColors.moneyIn,
      'rejected' => AppColors.moneyOut,
      _ => AppColors.attention,
    };

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    request.amountFormatted,
                    style: Theme.of(context)
                        .textTheme
                        .titleSmall
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    color: colour.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    request.statusLabel,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: colour,
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              request.requestedAtLabel,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (request.reason != null) ...[
              const SizedBox(height: 6),
              Text(request.reason!),
            ],
            // The reason for a refusal matters more than the refusal, so it
            // is shown rather than folded away behind the status chip.
            if (request.decisionNote != null) ...[
              const SizedBox(height: 6),
              Text(
                'پاسخ: ${request.decisionNote}',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: colour, fontWeight: FontWeight.w600),
              ),
            ],
            if (onWithdraw != null)
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  onPressed: onWithdraw,
                  child: const Text('پس گرفتن درخواست'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _RequestSheet extends StatefulWidget {
  const _RequestSheet();

  @override
  State<_RequestSheet> createState() => _RequestSheetState();
}

class _RequestSheetState extends State<_RequestSheet> {
  final _amount = TextEditingController();
  final _reason = TextEditingController();

  @override
  void dispose() {
    _amount.dispose();
    _reason.dispose();
    super.dispose();
  }

  void _submit() {
    final amount = double.tryParse(_amount.text.trim());

    if (amount == null || amount <= 0) {
      showMessage(context, 'مبلغ را وارد کنید.', isError: true);

      return;
    }

    Navigator.pop(context, (
      amount: amount,
      reason: _reason.text.trim().isEmpty ? null : _reason.text.trim(),
    ));
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
            'درخواست علی‌الحساب',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'این مبلغ از حقوق ماه بعد شما کسر می‌شود.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amount,
            keyboardType: TextInputType.number,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'مبلغ',
              prefixIcon: Icon(Icons.payments_outlined),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _reason,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'علت',
              prefixIcon: Icon(Icons.notes_rounded),
            ),
          ),
          const SizedBox(height: 20),
          FilledButton(onPressed: _submit, child: const Text('ارسال درخواست')),
        ],
      ),
    );
  }
}
