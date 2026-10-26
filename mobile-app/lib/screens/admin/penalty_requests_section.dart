import 'package:flutter/material.dart';

import '../../models/penalty_request.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// Penalties the seller asked for, waiting on the manager.
///
/// Approving writes one penalty to «کسورات»; rejecting needs a reason the
/// seller will read. Nothing is docked until then.
class PenaltyRequestsSection extends StatefulWidget {
  const PenaltyRequestsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<PenaltyRequestsSection> createState() => _PenaltyRequestsSectionState();
}

class _PenaltyRequestsSectionState extends State<PenaltyRequestsSection> {
  late Future<List<PenaltyRequest>> _requests;

  @override
  void initState() {
    super.initState();
    _requests = widget.api.pendingPenaltyRequests();
  }

  void _reload() =>
      setState(() => _requests = widget.api.pendingPenaltyRequests());

  Future<void> _approve(PenaltyRequest r) async {
    final result = await showDialog<PenaltyApproval>(
      context: context,
      builder: (_) => PenaltyApproveDialog(request: r),
    );
    if (result == null) return;
    try {
      await widget.api.approvePenaltyRequest(r.id, amount: result.amount);
      if (!mounted) return;
      showMessage(
          context, r.isFine ? 'تنبیه در کسورات ثبت شد.' : 'اخطار ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _reject(PenaltyRequest r) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => const _RejectDialog(),
    );
    if (note == null) return;
    try {
      await widget.api.rejectPenaltyRequest(r.id, note: note);
      if (!mounted) return;
      showMessage(context, 'درخواست رد شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<PenaltyRequest>>(
      future: _requests,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const SizedBox.shrink();
        }
        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }
        final requests = snapshot.data!;
        // Nothing waiting: say nothing, so the page stays short.
        if (requests.isEmpty) return const SizedBox.shrink();

        return PenaltyRequestsCard(
          requests: requests,
          onApprove: _approve,
          onReject: _reject,
        );
      },
    );
  }
}

/// The waiting list, drawn on its own so it can be shown without a server.
class PenaltyRequestsCard extends StatelessWidget {
  const PenaltyRequestsCard({
    super.key,
    required this.requests,
    required this.onApprove,
    required this.onReject,
  });

  final List<PenaltyRequest> requests;
  final ValueChanged<PenaltyRequest> onApprove;
  final ValueChanged<PenaltyRequest> onReject;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bold =
        theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800);

    return AdminSection(
      title: 'درخواست تنبیه',
      icon: Icons.gavel_rounded,
      trailing: Text(
        '${requests.length} در انتظار',
        style: theme.textTheme.bodySmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: AppColors.attention,
        ),
      ),
      children: [
        for (final r in requests)
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(r.userName, style: bold)),
                    Text(r.isFine ? (r.amountFormatted ?? '') : 'اخطار',
                        style: bold),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  '${r.reasonLabel} · درخواست‌دهنده: ${r.requestedByName ?? '-'} · ${r.requestedAtLabel}',
                  style: theme.textTheme.bodySmall,
                ),
                if (r.note != null && r.note!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text('«${r.note}»'),
                ],
                if (r.lateWarning != null) ...[
                  const SizedBox(height: 6),
                  Text(r.lateWarning!,
                      style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.attention,
                          fontWeight: FontWeight.w700)),
                ],
                const SizedBox(height: 6),
                Row(
                  children: [
                    TextButton.icon(
                      onPressed: () => onApprove(r),
                      icon: const Icon(Icons.check_rounded, size: IconSize.row),
                      label: const Text('تأیید'),
                    ),
                    TextButton.icon(
                      onPressed: () => onReject(r),
                      style: TextButton.styleFrom(
                          foregroundColor: AppColors.moneyOut),
                      icon: const Icon(Icons.close_rounded, size: IconSize.row),
                      label: const Text('رد'),
                    ),
                  ],
                ),
                const Divider(height: 16),
              ],
            ),
          ),
      ],
    );
  }
}

class PenaltyApproval {
  const PenaltyApproval(this.amount);
  final double? amount;
}

/// Approve, with the fine's amount open to change before it is docked.
class PenaltyApproveDialog extends StatefulWidget {
  const PenaltyApproveDialog({super.key, required this.request});

  final PenaltyRequest request;

  @override
  State<PenaltyApproveDialog> createState() => _PenaltyApproveDialogState();
}

class _PenaltyApproveDialogState extends State<PenaltyApproveDialog> {
  late final _amount = TextEditingController(
    text: widget.request.amount == null
        ? ''
        : widget.request.amount!.toStringAsFixed(0),
  );

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.request;

    return AlertDialog(
      title: const Text('تأیید درخواست تنبیه'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('${r.userName} - ${r.typeLabel} - ${r.reasonLabel}'),
            const SizedBox(height: 8),
            Text(
              r.isFine
                  ? 'با تأیید، این مبلغ در کسورات فیش حقوق همین ماه ثبت می‌شود.'
                  : 'اخطار فقط ثبت می‌شود و مبلغی کسر نمی‌شود.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (r.lateWarning != null) ...[
              const SizedBox(height: 8),
              Text(r.lateWarning!,
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: AppColors.attention)),
            ],
            if (r.isFine) ...[
              const SizedBox(height: 14),
              TextField(
                controller: _amount,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                    labelText: 'مبلغ نهایی (قابل ویرایش)'),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          onPressed: () {
            double? amount;
            if (r.isFine) {
              amount = double.tryParse(
                  latinDigits(_amount.text).replaceAll(RegExp(r'[^0-9.]'), ''));
              if (amount == null || amount <= 0) return;
            }
            Navigator.pop(context, PenaltyApproval(amount));
          },
          child: const Text('تأیید و اعمال'),
        ),
      ],
    );
  }
}

class _RejectDialog extends StatefulWidget {
  const _RejectDialog();

  @override
  State<_RejectDialog> createState() => _RejectDialogState();
}

class _RejectDialogState extends State<_RejectDialog> {
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('رد درخواست تنبیه'),
      content: TextField(
        controller: _note,
        maxLines: 2,
        autofocus: true,
        decoration: const InputDecoration(labelText: 'دلیل رد'),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          onPressed: () {
            final note = _note.text.trim();
            if (note.isEmpty) return;
            Navigator.pop(context, note);
          },
          child: const Text('رد درخواست'),
        ),
      ],
    );
  }
}
