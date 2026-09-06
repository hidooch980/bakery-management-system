import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'advance_requests_section.dart';
import 'payroll_section.dart';
import 'staff_report_section.dart';
import 'staff_yield_section.dart';
import '../../theme/app_theme.dart';

/// Who checked in today, and at what time.
class AdminStaffTab extends StatefulWidget {
  const AdminStaffTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminStaffTab> createState() => _AdminStaffTabState();
}

/// The last thirty days, as the API takes them.
///
/// Computed once rather than in `build`: a section that takes its range
/// as a parameter reloads when the parameter changes, and a fresh
/// `DateTime.now()` on every frame would change it on every frame.
final String _today = _apiDate(DateTime.now());
final String _thirtyDaysAgo =
    _apiDate(DateTime.now().subtract(const Duration(days: 29)));

String _apiDate(DateTime value) =>
    '${value.year}-${value.month.toString().padLeft(2, '0')}'
    '-${value.day.toString().padLeft(2, '0')}';

class _AdminStaffTabState extends State<AdminStaffTab> {
  late Future<List<Map<String, dynamic>>> _attendance;

  @override
  void initState() {
    super.initState();
    _attendance = widget.api.adminAttendanceToday();
  }

  void _reload() =>
      setState(() => _attendance = widget.api.adminAttendanceToday());

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<List<Map<String, dynamic>>>(
        future: _attendance,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: _reload)],
            );
          }

          final records = snapshot.data ?? const <Map<String, dynamic>>[];

          if (records.isEmpty) {
            return ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
              children: [
                PayrollSection(api: widget.api),
                const SizedBox(height: 12),
                AdvanceRequestsSection(api: widget.api),
                const SizedBox(height: 12),
                StaffReportSection(api: widget.api),
                const SizedBox(height: 22),
                // What each bench got out of a sack. The same thirty days
                // the report above covers, so the two are read together.
                StaffYieldSection(
                  api: widget.api,
                  from: _thirtyDaysAgo,
                  to: _today,
                ),
                const SizedBox(height: 40),
                const EmptyState(
                  icon: Icons.how_to_reg_rounded,
                  title: 'هنوز کسی تیک حضور نزده',
                  subtitle: 'ساعت ورود کارکنان اینجا نمایش داده می‌شود.',
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            // One more than the records: the month's report sits above
            // today's list, which answers a different question.
            itemCount: records.length + 1,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              if (index == 0) {
                return Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Column(
                    children: [
                      PayrollSection(api: widget.api),
                      const SizedBox(height: 12),
                      AdvanceRequestsSection(api: widget.api),
                      const SizedBox(height: 12),
                      StaffReportSection(api: widget.api),
                const SizedBox(height: 22),
                // What each bench got out of a sack. The same thirty days
                // the report above covers, so the two are read together.
                StaffYieldSection(
                  api: widget.api,
                  from: _thirtyDaysAgo,
                  to: _today,
                ),
                    ],
                  ),
                );
              }

              final record = records[index - 1];
              final user = record['user'] as Map<String, dynamic>?;
              final checkedInAt = DateTime.tryParse('${record['checked_in_at']}');

              return Card(
                child: ListTile(
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  leading: CircleAvatar(
                    backgroundColor: scheme.primary.withValues(alpha: 0.14),
                    child: Icon(Icons.person_rounded, color: scheme.primary),
                  ),
                  title: Text(
                    '${user?['name'] ?? '—'}',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: Theme.of(context).colorScheme.onSurface,
                    ),
                  ),
                  subtitle: Text(JalaliFormat.date(checkedInAt)),
                  trailing: Chip(
                    label: Text(JalaliFormat.time(checkedInAt)),
                    avatar: const Icon(Icons.schedule_rounded, size: IconSize.inline),
                    backgroundColor:
                        AppColors.moneyIn.withValues(alpha: 0.15),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
