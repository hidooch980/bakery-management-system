import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';

/// How the shop was staffed over the last month, and what it owes for it.
///
/// The staff tab showed who is in today, which answers this morning's
/// question. It did not answer the month's: how many working days there
/// were, how many were covered, and what the wages come to — figures the
/// admin had to open the web panel for.
class StaffReportSection extends StatefulWidget {
  const StaffReportSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<StaffReportSection> createState() => _StaffReportSectionState();
}

class _StaffReportSectionState extends State<StaffReportSection> {
  late Future<({Map<String, dynamic> attendance, Map<String, dynamic> payroll})> _report;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  Future<({Map<String, dynamic> attendance, Map<String, dynamic> payroll})> _load() async {
    final now = DateTime.now();
    final from = _apiDate(now.subtract(const Duration(days: 29)));
    final to = _apiDate(now);

    // Asked together so the two halves describe the same stretch, and one
    // slow answer does not hold the other up.
    final results = await Future.wait([
      widget.api.attendanceSummary(from: from, to: to),
      widget.api.payrollReport(from: from, to: to),
    ]);

    return (attendance: results[0], payroll: results[1]);
  }

  String _apiDate(DateTime value) =>
      '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<({Map<String, dynamic> attendance, Map<String, dynamic> payroll})>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'گزارش ۳۰ روز اخیر',
            icon: Icons.insights_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // Today's list above is the important thing on this tab; a report
        // that cannot be read stays away rather than sitting there as an
        // error.
        if (snapshot.hasError) {
          return const SizedBox.shrink();
        }

        final attendance = snapshot.data!.attendance;
        final payroll = snapshot.data!.payroll;

        final workingDays = _int(attendance['working_days']);
        final holidays = _int(attendance['holiday_count']);
        final coverage = _num(attendance['coverage_percent']);
        final staff = _int(attendance['active_staff']);

        final byEmployee = ((payroll['by_employee'] as List?) ?? const [])
            .whereType<Map>()
            .map((e) => e.map((k, v) => MapEntry('$k', v)))
            .toList();

        final unpaid = _num(payroll['unpaid']);

        return AdminSection(
          title: 'گزارش ۳۰ روز اخیر',
          icon: Icons.insights_rounded,
          children: [
            AdminRow(
              label: 'روزهای کاری',
              value: holidays > 0
                  ? '$workingDays روز  •  $holidays تعطیل'
                  : '$workingDays روز',
            ),
            AdminRow(label: 'کارکنان فعال', value: '$staff نفر'),
            AdminRow(
              label: 'پوشش حضور',
              value: '${_trim(coverage)}٪',
              // Below three-quarters is worth noticing: either people are
              // not turning up or they are not recording it, and both
              // matter before payday.
              color: coverage < 75 ? AppColors.emberHot : null,
            ),

            const Divider(height: 1),

            AdminRow(
              label: 'جمع حقوق دوره',
              value: (payroll['total_net_formatted'] as String?) ?? '—',
              emphasise: true,
            ),
            if (unpaid > 0)
              AdminRow(
                label: 'پرداخت‌نشده',
                value: _money(payroll, unpaid),
                color: const Color(0xFFD1495B),
              ),

            if (byEmployee.isNotEmpty) ...[
              const Divider(height: 1),
              for (final row in byEmployee)
                AdminRow(
                  label: (row['employee'] as String?) ?? 'بدون نام',
                  value: (row['net_amount_formatted'] as String?) ?? '—',
                ),
            ],
          ],
        );
      },
    );
  }

  static int _int(dynamic value) =>
      value is num ? value.toInt() : int.tryParse('$value') ?? 0;

  static double _num(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

  /// Drops a pointless ".0" — "۸۰٪" reads better than "۸۰.۰٪".
  static String _trim(double value) =>
      value == value.roundToDouble() ? '${value.round()}' : '$value';

  /// The total arrives formatted; the unpaid figure does not, so the unit is
  /// borrowed from the total rather than guessed at.
  String _money(Map<String, dynamic> payroll, double amount) {
    final formatted = (payroll['total_net_formatted'] as String?) ?? '';
    final unit = formatted.replaceAll(RegExp(r'[\d,٬.\-−\s]'), '');

    final rounded = amount.round().toString().replaceAllMapped(
          RegExp(r'(\d)(?=(\d{3})+$)'),
          (m) => '${m[1]},',
        );

    return unit.isEmpty ? rounded : '$rounded $unit';
  }
}
