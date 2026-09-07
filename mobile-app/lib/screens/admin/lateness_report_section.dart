import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
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

  @override
  void initState() {
    super.initState();
    _report = widget.api.workStartLateReport();
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
              AdminRow(
                // A row about a person with no name on it is a figure
                // about nobody, and this figure is money.
                label: personName({'name': person['user']}),
                value: '${person['late_count'] ?? 0} بار'
                    '  •  ${person['penalty_formatted'] ?? '—'}',
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
              child: Text(
                'این مبلغ محاسبهٔ تعرفه است و خودکار کسر نمی‌شود؛'
                ' برای کسر باید در «کسر و اضافه» ثبتش کنید.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        );
      },
    );
  }
}
