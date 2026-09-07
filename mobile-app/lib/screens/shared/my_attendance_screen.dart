import 'package:flutter/material.dart';

import '../../models/entries.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// The days this person came in, as the shop recorded them.
///
/// The endpoint has existed all along and nothing called it, so a baker
/// who wanted to know how many days he had worked this month had to ask
/// the owner, who had to open the panel. Now it is on his own phone.
///
/// It is his own record only — `/attendance/my-history` is scoped to the
/// signed-in user on the server, so this cannot become a way to read
/// somebody else's days.
class MyAttendanceScreen extends StatefulWidget {
  const MyAttendanceScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<MyAttendanceScreen> createState() => _MyAttendanceScreenState();
}

class _MyAttendanceScreenState extends State<MyAttendanceScreen> {
  late Future<List<AttendanceRecord>> _records;

  @override
  void initState() {
    super.initState();
    _records = widget.api.attendanceHistory();
  }

  void _reload() =>
      setState(() => _records = widget.api.attendanceHistory());

  /// How many of these fall in the Jalali month the newest one is in.
  ///
  /// The question a person actually asks is «این ماه چند روز آمده‌ام», and
  /// counting the whole list would answer a different one — the list runs
  /// back thirty records, which is more than a month for anybody who takes
  /// a day off.
  int _thisMonth(List<AttendanceRecord> records) {
    final dates = records.map((r) => r.date).whereType<DateTime>().toList();

    if (dates.isEmpty) return 0;

    final newest = JalaliFormat.monthLabel(dates.first);

    return dates.where((d) => JalaliFormat.monthLabel(d) == newest).length;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('حضور من')),
      body: FutureBuilder<List<AttendanceRecord>>(
        future: _records,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
          }

          final records = snapshot.data ?? const <AttendanceRecord>[];

          if (records.isEmpty) {
            return RefreshIndicator(
              onRefresh: () async => _reload(),
              child: ListView(
                children: const [
                  SizedBox(height: 80),
                  EmptyState(
                    icon: Icons.event_available_rounded,
                    title: 'هنوز حضوری ثبت نشده',
                    subtitle: 'روزهایی که تیک حضور می‌زنید اینجا می‌آید.',
                  ),
                ],
              ),
            );
          }

          final month = _thisMonth(records);

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: records.length + 1,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                if (index == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _MonthCard(
                      days: month,
                      label: JalaliFormat.monthLabel(records.first.date),
                    ),
                  );
                }

                return _DayTile(record: records[index - 1]);
              },
            ),
          );
        },
      ),
    );
  }
}

class _MonthCard extends StatelessWidget {
  const _MonthCard({required this.days, required this.label});

  final int days;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(Icons.event_available_rounded, color: scheme.primary),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                // The month is named rather than left as «این ماه», which
                // on the fifth of a new one means the wrong thing to the
                // reader and the right thing to the code.
                '$days روز در $label',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: scheme.onSurface,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DayTile extends StatelessWidget {
  const _DayTile({required this.record});

  final AttendanceRecord record;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        leading: const Icon(Icons.check_circle_rounded, color: AppColors.moneyIn),
        title: Text(
          JalaliFormat.longDate(record.date),
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
            color: Theme.of(context).colorScheme.onSurface,
          ),
        ),
        // A day with no recorded time still counts as a day worked, so the
        // row stays and only the chip goes quiet.
        trailing: record.checkedInAt == null
            ? null
            : Chip(
                label: Text(JalaliFormat.time(record.checkedInAt)),
                avatar: const Icon(Icons.schedule_rounded, size: IconSize.inline),
              ),
      ),
    );
  }
}
