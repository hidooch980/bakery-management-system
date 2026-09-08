import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// What the late tariff comes to this month, per person.
///
/// The baker has been able to see his own since the tariff was written —
/// «a tariff nobody can check is a fine, not a rule». The person who would
/// apply it could not see anybody's. `/work-starts/late-report` computes
/// exactly this, and `workStartLateReport()` was written to call it; no
/// screen ever did, and the panel has no penalty column at all. So the
/// figure the baker is shown existed in one place on his phone and nowhere
/// the owner looks.
///
/// It says plainly that nothing is deducted on its own. Nothing in the
/// payroll reads a `WorkStart`: a deduction happens when somebody enters
/// it as a کسر. Leaving that unsaid beside a money figure would invite the
/// opposite conclusion, and the person it costs is the one who would find
/// out last.
class LatenessReportSection extends StatefulWidget {
  const LatenessReportSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<LatenessReportSection> createState() => _LatenessReportSectionState();
}

class _LatenessReportSectionState extends State<LatenessReportSection> {
  late Future<Map<String, dynamic>> _report;
  final Set<int> _busy = {};

  @override
  void initState() {
    super.initState();
    _report = widget.api.workStartLateReport();
  }

  void _reload() =>
      setState(() => _report = widget.api.workStartLateReport());

  Future<void> _forgive(int id, String name) async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('بخشیدن کسر تأخیر'),
        content: Text(
          'کسر تأخیر $name این ماه از حقوقش کم نمی‌شود.'
          ' مبلغش ثبت می‌ماند و هر وقت خواستید برمی‌گردانید.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('ببخش'),
          ),
        ],
      ),
    );

    if (sure != true || !mounted) return;

    setState(() => _busy.add(id));

    try {
      await widget.api.waiveAdjustment(id);
      if (!mounted) return;
      showMessage(context, 'بخشیده شد.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy.remove(id));
    }
  }

  Future<void> _restore(int id) async {
    setState(() => _busy.add(id));

    try {
      await widget.api.restoreAdjustment(id);
      if (!mounted) return;
      showMessage(context, 'دوباره اعمال شد.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy.remove(id));
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'تأخیرها',
            icon: Icons.running_with_errors_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // The lists above are what this tab is for; a report that cannot
        // be read stays away rather than sitting there as an error.
        if (snapshot.hasError || snapshot.data == null) {
          return const SizedBox.shrink();
        }

        final data = snapshot.data!;
        final people = rowList(data['by_user']);
        final lateDays = (data['late_days'] as num?)?.toInt() ?? 0;
        final period = '${data['period_label'] ?? ''}';

        if (lateDays == 0 || people.isEmpty) {
          return AdminSection(
            title: 'تأخیرها',
            icon: Icons.running_with_errors_rounded,
            children: [
              AdminRow(
                label: period.isEmpty ? 'این ماه' : period,
                value: 'تأخیری ثبت نشده',
              ),
            ],
          );
        }

        return AdminSection(
          title: 'تأخیرها',
          icon: Icons.running_with_errors_rounded,
          trailing: Text(
            period,
            style: Theme.of(context).textTheme.bodySmall,
          ),
          children: [
            AdminRow(
              label: 'روزهای تأخیر',
              value: '$lateDays روز',
              color: AppColors.attention,
            ),
            AdminRow(
              label: 'جمع طبق تعرفه',
              value: '${data['penalty_total_formatted'] ?? '—'}',
            ),
            const Divider(height: 20),
            for (final person in people)
              _PersonRow(
                // A row about a person with no name on it is a figure
                // about nobody, and this figure is money.
                name: personName({'name': person['user']}),
                summary: '${person['late_count'] ?? 0} بار'
                    '  •  ${person['penalty_formatted'] ?? '—'}',
                waived: person['waived'] == true,
                settled: person['settled'] == true,
                busy: _busy.contains((person['adjustment_id'] as num?)?.toInt()),
                onForgive: person['adjustment_id'] == null
                    ? null
                    : () => _forgive(
                          (person['adjustment_id'] as num).toInt(),
                          personName({'name': person['user']}),
                        ),
                onRestore: person['adjustment_id'] == null
                    ? null
                    : () => _restore((person['adjustment_id'] as num).toInt()),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
              child: Text(
                'این مبلغ طبق تعرفه از حقوق کسر می‌شود.'
                ' اگر نمی‌خواهید بگیرید، «بخشیدن» را بزنید.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        );
      },
    );
  }
}


/// One person's line, with the decision on it.
class _PersonRow extends StatelessWidget {
  const _PersonRow({
    required this.name,
    required this.summary,
    required this.waived,
    required this.settled,
    required this.busy,
    required this.onForgive,
    required this.onRestore,
  });

  final String name;
  final String summary;
  final bool waived;
  final bool settled;
  final bool busy;
  final VoidCallback? onForgive;
  final VoidCallback? onRestore;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 8, 14, 0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: scheme.onSurface,
                      ),
                ),
                Text(
                  waived ? '$summary — بخشیده شد' : summary,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        // Struck-through would be smaller and worse: the
                        // figure still matters next month, when the
                        // question is «چقدر بود که نگرفتم».
                        color: waived
                            ? AppColors.moneyIn
                            : scheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
          ),
          if (busy)
            const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          // A month already on a payslip is history: that figure is part
          // of a net somebody was paid, so the screen does not offer it.
          else if (settled)
            Text(
              'در فیش',
              style: Theme.of(context).textTheme.bodySmall,
            )
          else if (waived)
            TextButton(
              onPressed: onRestore,
              child: const Text('بازگرداندن'),
            )
          else
            TextButton(
              onPressed: onForgive,
              child: const Text('بخشیدن'),
            ),
        ],
      ),
    );
  }
}
