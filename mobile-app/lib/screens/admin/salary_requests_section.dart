import 'package:flutter/material.dart';

import '../../models/staff_adjustment.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// Staff asking to be paid for the month, and who has waited longest.
///
/// The server has carried this since the three weeks the shop went without
/// writing a payslip, and no screen ever asked for it — so the requests
/// have been arriving into a table nobody opens.
///
/// There is no approve button, deliberately and on the server's side too:
/// paying somebody through the pay sheet is what approval means, and that
/// is the screen where the figures are in front of you before the money
/// moves. What can be done from here is say no, in words, which is the
/// thing that was missing.
class SalaryRequestsSection extends StatefulWidget {
  const SalaryRequestsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SalaryRequestsSection> createState() => _SalaryRequestsSectionState();
}

class _SalaryRequestsSectionState extends State<SalaryRequestsSection> {
  late Future<List<SalaryRequest>> _requests;

  @override
  void initState() {
    super.initState();
    _requests = widget.api.pendingSalaryRequests();
  }

  void _reload() =>
      setState(() => _requests = widget.api.pendingSalaryRequests());

  Future<void> _reject(SalaryRequest request) async {
    final note = await showDialog<String>(
      context: context,
      builder: (_) => const _RejectDialog(),
    );

    if (note == null) return;

    try {
      await widget.api.rejectSalaryRequest(request.id, note: note);
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
    return FutureBuilder<List<SalaryRequest>>(
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

        final requests = snapshot.data ?? const <SalaryRequest>[];

        if (requests.isEmpty) {
          return const AdminSection(
            title: 'درخواست حقوق',
            icon: Icons.receipt_long_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'درخواست بی‌پاسخی نیست'),
            ],
          );
        }

        return AdminSection(
          title: 'درخواست حقوق',
          icon: Icons.receipt_long_rounded,
          trailing: Text(
            '${requests.length} در انتظار',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.attention,
                ),
          ),
          children: [
            for (final request in requests)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _RequestCard(
                  request: request,
                  onReject: () => _reject(request),
                ),
              ),
            const SizedBox(height: 14),
          ],
        );
      },
    );
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({required this.request, required this.onReject});

  final SalaryRequest request;
  final VoidCallback onReject;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    // A row about a person with no name on it is a figure
                    // about nobody, and the name can be absent.
                    personName({'name': request.userName}, fallbackId: request.id),
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurface,
                    ),
                  ),
                ),
                Text(
                  // Days waiting rather than the date: «۱۹ روز» is the
                  // fact that decides whether this is urgent, and reading
                  // it off a Jalali date is arithmetic in the reader's head.
                  '${request.daysWaiting} روز در انتظار',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.attention,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              request.periodLabel,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (request.estimatedNetFormatted != null) ...[
              const SizedBox(height: 6),
              Text(
                'اگر امروز پرداخت شود حدوداً ${request.estimatedNetFormatted}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: scheme.onSurface,
                ),
              ),
            ],
            if (request.note != null && request.note!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('یادداشت: ${request.note}',
                  style: Theme.of(context).textTheme.bodySmall),
            ],
            const SizedBox(height: 4),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: onReject,
                icon: const Icon(Icons.close_rounded, size: IconSize.button),
                label: const Text('رد کردن'),
                style: TextButton.styleFrom(foregroundColor: scheme.error),
              ),
            ),
            Text(
              // Said on the screen rather than only in the code, because
              // «چرا دکمهٔ تأیید نیست» is a fair question with one answer.
              'پرداخت از «حقوق ماه» انجام می‌شود؛ آنجا مبلغ‌ها پیش از'
              ' جابه‌جایی پول جلوی چشم است.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
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
      title: const Text('رد درخواست حقوق'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'علت را بنویسید. کسی که حقوقش را نگرفته، «نه»ی بی‌توضیح را '
            'جواب نمی‌داند.',
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

            // Required by the server as well; refused here so nobody is
            // bounced back by a validation error the form could have
            // mentioned first.
            if (note.isEmpty) return;

            Navigator.pop(context, note);
          },
          child: const Text('رد کن'),
        ),
      ],
    );
  }
}
