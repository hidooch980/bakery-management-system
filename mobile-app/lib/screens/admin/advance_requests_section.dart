import 'package:flutter/material.dart';

import '../../models/quota_and_advance.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// Staff asking for pay early, and the answer.
///
/// Only the ones still waiting appear: answered requests are history, and
/// what is wanted here is the short list of people owed a reply.
class AdvanceRequestsSection extends StatefulWidget {
  const AdvanceRequestsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdvanceRequestsSection> createState() => _AdvanceRequestsSectionState();
}

class _AdvanceRequestsSectionState extends State<AdvanceRequestsSection> {
  late Future<List<AdvanceRequest>> _requests;

  @override
  void initState() {
    super.initState();
    _requests = widget.api.pendingAdvanceRequests();
  }

  void _reload() =>
      setState(() => _requests = widget.api.pendingAdvanceRequests());

  Future<void> _approve(AdvanceRequest request) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('تأیید علی‌الحساب'),
        // Scrollable: the dialog grew from one sentence to a small statement
        // of account, and an AlertDialog does not scroll on its own — on a
        // short screen the buttons would be pushed off the bottom, leaving
        // no way to approve or to cancel.
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${request.userName} — ${request.amountFormatted}\n\n'
                'با تأیید، این مبلغ به‌عنوان علی‌الحساب ثبت می‌شود و از '
                'حقوق بعدی کسر خواهد شد.',
              ),
              // Said before the money goes out. The server reported the
              // same thing after approving, which is the wrong moment to
              // learn that this person had drawn most of their month.
              if (request.outstandingFormatted != null) ...[
                const SizedBox(height: 14),
                _StandingLine(
                  label: 'علی‌الحساب فعلی',
                  value: request.outstandingFormatted!,
                ),
                if (request.monthlySalaryFormatted != null)
                  _StandingLine(
                    label: 'حقوق ماهانه',
                    value: request.monthlySalaryFormatted!,
                  ),
                if (request.totalAfterFormatted != null)
                  _StandingLine(
                    label: 'جمع پس از تأیید',
                    value: request.totalAfterFormatted!,
                    emphasised: true,
                  ),
              ],
              if (request.exceedsSalary) ...[
                const SizedBox(height: 12),
                Text(
                  'با این تأیید، جمع علی‌الحساب از حقوق یک ماه بیشتر می‌شود و'
                  ' باقی‌اش به ماه بعد می‌افتد.',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.emberHot,
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('تأیید و پرداخت'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      await widget.api.approveAdvanceRequest(request.id);
      if (!mounted) return;
      showMessage(context, 'علی‌الحساب ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _reject(AdvanceRequest request) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => const _RejectDialog(),
    );

    if (note == null) return;

    try {
      await widget.api.rejectAdvanceRequest(request.id, note: note);
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
    return FutureBuilder<List<AdvanceRequest>>(
      future: _requests,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }

        final requests = snapshot.data!;

        if (requests.isEmpty) {
          return const AdminSection(
            title: 'درخواست علی‌الحساب',
            icon: Icons.pan_tool_alt_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'درخواست بی‌پاسخی نیست'),
            ],
          );
        }

        return AdminSection(
          title: 'درخواست علی‌الحساب',
          icon: Icons.pan_tool_alt_rounded,
          trailing: Text(
            '${requests.length} در انتظار',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.emberHot,
                ),
          ),
          children: [
            for (final request in requests)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _RequestTile(
                  request: request,
                  onApprove: () => _approve(request),
                  onReject: () => _reject(request),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _RequestTile extends StatelessWidget {
  const _RequestTile({
    required this.request,
    required this.onApprove,
    required this.onReject,
  });

  final AdvanceRequest request;
  final VoidCallback onApprove;
  final VoidCallback onReject;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                request.userName,
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
            ),
            Text(
              request.amountFormatted,
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w800),
            ),
          ],
        ),
        const SizedBox(height: 2),
        Text(
          request.requestedAtLabel,
          style: Theme.of(context).textTheme.bodySmall,
        ),
        if (request.reason != null) ...[
          const SizedBox(height: 4),
          Text(request.reason!),
        ],
        const SizedBox(height: 6),
        Row(
          children: [
            TextButton.icon(
              onPressed: onApprove,
              icon: const Icon(Icons.check_rounded, size: 18),
              label: const Text('تأیید'),
            ),
            TextButton.icon(
              onPressed: onReject,
              style: TextButton.styleFrom(
                foregroundColor: const Color(0xFFD1495B),
              ),
              icon: const Icon(Icons.close_rounded, size: 18),
              label: const Text('رد'),
            ),
          ],
        ),
        const Divider(height: 16),
      ],
    );
  }
}

/// One line of "here is where this person stands" in the approval dialog.
class _StandingLine extends StatelessWidget {
  const _StandingLine({
    required this.label,
    required this.value,
    this.emphasised = false,
  });

  final String label;
  final String value;
  final bool emphasised;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          Text(
            value,
            style: theme.textTheme.bodySmall?.copyWith(
              fontWeight: emphasised ? FontWeight.w800 : FontWeight.w600,
            ),
          ),
        ],
      ),
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
      title: const Text('رد درخواست'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'علت را بنویسید. «نه»ی خالی به کسی که پول لازم دارد از '
            'بی‌پاسخی بدتر است.',
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _note,
            maxLines: 2,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'علت رد'),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          onPressed: () {
            final note = _note.text.trim();

            // Required by the server too; refused here so the person is
            // not bounced back by a validation error for something the
            // form could have said first.
            if (note.isEmpty) return;

            Navigator.pop(context, note);
          },
          child: const Text('رد درخواست'),
        ),
      ],
    );
  }
}
