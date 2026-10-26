import 'package:flutter/material.dart';

import '../../models/penalty_request.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// «درخواست تنبیه» on the seller's page: ask, and see what came back.
///
/// Asking never docks anything. Only the manager's approval turns a fine
/// into a deduction on the payslip.
class SellerPenaltyRequestsSection extends StatefulWidget {
  const SellerPenaltyRequestsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerPenaltyRequestsSection> createState() =>
      _SellerPenaltyRequestsSectionState();
}

class _SellerPenaltyRequestsSectionState
    extends State<SellerPenaltyRequestsSection> {
  late Future<List<PenaltyRequest>> _mine;

  @override
  void initState() {
    super.initState();
    _mine = widget.api.myPenaltyRequests();
  }

  void _reload() => setState(() => _mine = widget.api.myPenaltyRequests());

  Future<void> _new() async {
    final sent = await showModalBottomSheet<PenaltyRequest>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => PenaltyRequestForm(api: widget.api),
    );
    if (sent == null || !mounted) return;
    showMessage(
      context,
      sent.lateWarning == null
          ? 'درخواست برای تأیید مدیر ارسال شد.'
          : 'ارسال شد. ${sent.lateWarning}',
    );
    _reload();
  }

  Future<void> _withdraw(PenaltyRequest r) async {
    try {
      await widget.api.withdrawPenaltyRequest(r.id);
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
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
          child: Row(
            children: [
              Icon(Icons.gavel_rounded,
                  size: IconSize.row, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Text('درخواست تنبیه',
                  style: theme.textTheme.titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700)),
              const Spacer(),
              TextButton.icon(
                onPressed: _new,
                icon: const Icon(Icons.add_rounded),
                label: const Text('درخواست جدید'),
              ),
            ],
          ),
        ),
        FutureBuilder<List<PenaltyRequest>>(
          future: _mine,
          builder: (context, snap) {
            if (snap.connectionState == ConnectionState.waiting) {
              return const Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            if (snap.hasError) {
              return ErrorBox(message: '${snap.error}', onRetry: _reload);
            }
            final rows = snap.data!;
            if (rows.isEmpty) {
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    'درخواستی نفرستاده‌اید. تنبیه فقط پس از تأیید مدیر اجرا می‌شود.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),
              );
            }
            return Column(
              children: [
                for (final r in rows.take(10))
                  PenaltyRequestCard(
                    request: r,
                    onWithdraw: r.isPending ? () => _withdraw(r) : null,
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}

/// One request, with its status said in words and colour.
class PenaltyRequestCard extends StatelessWidget {
  const PenaltyRequestCard({super.key, required this.request, this.onWithdraw});

  final PenaltyRequest request;
  final VoidCallback? onWithdraw;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final r = request;
    final color = r.isPending
        ? AppColors.warning
        : r.isApproved
            ? AppColors.success
            : r.isRejected
                ? AppColors.danger
                : theme.colorScheme.outline;
    final what = [
      r.isFine ? 'جریمه' : 'اخطار',
      r.reasonLabel,
      if (r.amountFormatted != null) r.amountFormatted!,
      r.requestedAtLabel,
    ].join(' · ');

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(r.userName,
                      style: theme.textTheme.titleSmall
                          ?.copyWith(fontWeight: FontWeight.w800)),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(r.statusLabel,
                      style: theme.textTheme.bodySmall?.copyWith(
                          color: color, fontWeight: FontWeight.w700)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(what, style: theme.textTheme.bodySmall),
            if (r.note != null && r.note!.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('«${r.note}»', style: theme.textTheme.bodySmall),
            ],
            if (r.isRejected && r.decisionNote != null) ...[
              const SizedBox(height: 6),
              Text('دلیل رد: ${r.decisionNote}',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: AppColors.danger)),
            ],
            if (r.isApproved && r.isFine) ...[
              const SizedBox(height: 6),
              Text('در کسورات حقوق ثبت شد',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: AppColors.success)),
            ],
            if (onWithdraw != null)
              Align(
                alignment: AlignmentDirectional.centerEnd,
                child: TextButton(
                  onPressed: onWithdraw,
                  child: const Text('پس گرفتن'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// The form: who, fine or warning, why, how much, and a note.
class PenaltyRequestForm extends StatefulWidget {
  const PenaltyRequestForm({super.key, required this.api, this.options});

  final BakeryApi api;

  /// Given directly in tests; otherwise read from the server.
  final PenaltyOptions? options;

  @override
  State<PenaltyRequestForm> createState() => _PenaltyRequestFormState();
}

class _PenaltyRequestFormState extends State<PenaltyRequestForm> {
  late Future<PenaltyOptions> _options;
  String? _userId;
  String _type = 'fine';
  String? _reason;
  final _amount = TextEditingController();
  final _note = TextEditingController();
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _options = widget.options != null
        ? Future.value(widget.options)
        : widget.api.penaltyOptions();
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  double? get _amountValue => double.tryParse(
      latinDigits(_amount.text).replaceAll(RegExp(r'[^0-9.]'), ''));

  Future<void> _send() async {
    if (_userId == null || _reason == null) {
      showMessage(context, 'کارمند و علت را انتخاب کنید.', isError: true);
      return;
    }
    if (_type == 'fine' && (_amountValue ?? 0) <= 0) {
      showMessage(context, 'مبلغ جریمه را بنویسید.', isError: true);
      return;
    }
    setState(() => _saving = true);
    try {
      final sent = await widget.api.requestPenalty(
        userId: int.parse(_userId!),
        type: _type,
        reason: _reason!,
        amount: _type == 'fine' ? _amountValue : null,
        note: _note.text.trim(),
      );
      if (!mounted) return;
      Navigator.pop(context, sent);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.fromLTRB(
          20, 16, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: FutureBuilder<PenaltyOptions>(
        future: _options,
        builder: (context, snap) {
          if (snap.hasError) {
            return ErrorBox(
              message: '${snap.error}',
              onRetry: () => setState(_load),
            );
          }
          if (!snap.hasData) {
            return const Padding(
              padding: EdgeInsets.all(32),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final o = snap.data!;
          return SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('درخواست تنبیه',
                    style: theme.textTheme.titleMedium
                        ?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: _userId,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'کارمند'),
                  items: [
                    for (final s in o.staff)
                      DropdownMenuItem(value: s.key, child: Text(s.label)),
                  ],
                  onChanged: (v) => setState(() => _userId = v),
                ),
                const SizedBox(height: 14),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(
                        value: 'fine', label: Text('جریمه (کسر از حقوق)')),
                    ButtonSegment(value: 'warning', label: Text('اخطار')),
                  ],
                  selected: {_type},
                  onSelectionChanged: (s) => setState(() => _type = s.first),
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  initialValue: _reason,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'علت'),
                  items: [
                    for (final r in o.reasons)
                      DropdownMenuItem(value: r.key, child: Text(r.label)),
                  ],
                  onChanged: (v) => setState(() => _reason = v),
                ),
                if (_type == 'fine') ...[
                  const SizedBox(height: 14),
                  TextField(
                    controller: _amount,
                    keyboardType: TextInputType.number,
                    decoration:
                        const InputDecoration(labelText: 'مبلغ جریمه (تومان)'),
                  ),
                ],
                const SizedBox(height: 14),
                TextField(
                  controller: _note,
                  maxLines: 2,
                  decoration:
                      const InputDecoration(labelText: 'توضیح (اختیاری)'),
                ),
                const SizedBox(height: 12),
                Text(
                  'این درخواست فقط پس از تأیید مدیر اجرا می‌شود و جریمه در کسورات فیش حقوق می‌نشیند.',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: AppColors.attention),
                ),
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: _saving ? null : _send,
                  child:
                      Text(_saving ? 'در حال ارسال' : 'ارسال برای تأیید مدیر'),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
