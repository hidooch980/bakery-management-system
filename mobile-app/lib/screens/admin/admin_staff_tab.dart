import 'package:flutter/material.dart';
import '../../models/payroll.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import '../../widgets/admin_detail_group.dart';
import 'advance_requests_section.dart';
import 'lateness_report_section.dart';
import 'salary_requests_section.dart';
import 'payroll_section.dart';
import 'staff_report_section.dart';
import 'staff_yield_section.dart';
import 'staff_account_screen.dart';

/// فهرست کارکنان مستقل از گزارش حضور، با دسترسی روشن به حقوق و جزئیات.
class AdminStaffTab extends StatefulWidget {
  const AdminStaffTab({super.key, required this.api});
  final BakeryApi api;
  @override
  State<AdminStaffTab> createState() => _AdminStaffTabState();
}

class _AdminStaffTabState extends State<AdminStaffTab> {
  late Future<List<Employee>> _people;
  int _generation = 0;
  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _people = widget.api.payrollEmployees();
  }

  void _reload() => setState(() {
        _generation++;
        _load();
      });
  String _date(DateTime value) =>
      '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';
  Future<void> _openPayroll() async {
    await Navigator.push(
        context,
        MaterialPageRoute<void>(
            builder: (_) => Scaffold(
                appBar: AppBar(title: const Text('حقوق و پرداخت کارکنان')),
                body: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [PayrollSection(api: widget.api)]))));
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) => RefreshIndicator(
        onRefresh: () async {
          _reload();
          try {
            await _people;
          } catch (_) {/* خطا در کارت فهرست نمایش داده می‌شود. */}
        },
        child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              Text('کارکنان و پرداخت‌ها',
                  style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 4),
              const Text(
                  'برای ریز حقوق، بدهی‌ها و ثبت علی‌الحساب، پرونده هر کارمند را باز کنید.'),
              const SizedBox(height: 12),
              FilledButton.icon(
                  onPressed: _openPayroll,
                  icon: const Icon(Icons.payments_outlined),
                  label: const Text('حقوق و پرداخت کارکنان')),
              const SizedBox(height: 12),
              FutureBuilder<List<Employee>>(
                  future: _people,
                  builder: (context, snapshot) {
                    if (snapshot.connectionState == ConnectionState.waiting) {
                      return const Padding(
                          padding: EdgeInsets.all(24),
                          child: Center(child: CircularProgressIndicator()));
                    }
                    if (snapshot.hasError) {
                      return ErrorBox(
                          message: '${snapshot.error}', onRetry: _reload);
                    }
                    final people = snapshot.data ?? [];
                    if (people.isEmpty) {
                      return const Card(
                          child: ListTile(title: Text('کارمندی ثبت نشده')));
                    }
                    return Card(
                        child: Column(children: [
                      for (var i = 0; i < people.length; i++) ...[
                        if (i > 0) const Divider(height: 1),
                        ListTile(
                            leading: CircleAvatar(
                                backgroundColor: Theme.of(context)
                                    .colorScheme
                                    .primaryContainer,
                                child: Icon(Icons.person_outline,
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onPrimaryContainer)),
                            title: Text(people[i].displayName,
                                style: Theme.of(context).textTheme.titleSmall),
                            subtitle: Text(
                                'حقوق ماهانه: ${people[i].monthlySalaryFormatted}'),
                            trailing: const Icon(Icons.chevron_left),
                            onTap: () async {
                              await Navigator.push(
                                  context,
                                  MaterialPageRoute<void>(
                                      builder: (_) => StaffAccountScreen(
                                          api: widget.api, person: people[i])));
                              if (mounted) _reload();
                            }),
                      ],
                    ]));
                  }),
              const SizedBox(height: 12),
              AdminDetailGroup(
                  title: 'درخواست‌های پرداخت',
                  subtitle: 'علی‌الحساب و حقوق در انتظار بررسی',
                  icon: Icons.pending_actions,
                  builder: (_) => Column(children: [
                        AdvanceRequestsSection(api: widget.api),
                        const SizedBox(height: 16),
                        SalaryRequestsSection(api: widget.api)
                      ])),
              AdminDetailGroup(
                  title: 'حضور امروز',
                  subtitle: 'نام کارکنان و ساعت ورود',
                  icon: Icons.how_to_reg,
                  builder: (_) => _TodayAttendance(
                      key: ValueKey(_generation), api: widget.api)),
              AdminDetailGroup(
                  title: 'گزارش حضور و تأخیر',
                  subtitle: 'عملکرد ماهانه و ریز تأخیرها',
                  icon: Icons.schedule,
                  builder: (_) => Column(children: [
                        StaffReportSection(api: widget.api),
                        const SizedBox(height: 16),
                        LatenessReportSection(api: widget.api)
                      ])),
              AdminDetailGroup(
                  title: 'بازده کارکنان',
                  subtitle: 'گزارش تولید در ۳۰ روز اخیر',
                  icon: Icons.bakery_dining,
                  builder: (_) => StaffYieldSection(
                      api: widget.api,
                      from: _date(
                          DateTime.now().subtract(const Duration(days: 29))),
                      to: _date(DateTime.now()))),
            ]),
      );
}

/// حضور هنگام باز شدن بخش خوانده می‌شود و خطای آن روی فهرست کارکنان اثر ندارد.
class _TodayAttendance extends StatefulWidget {
  const _TodayAttendance({super.key, required this.api});
  final BakeryApi api;
  @override
  State<_TodayAttendance> createState() => _TodayAttendanceState();
}

class _TodayAttendanceState extends State<_TodayAttendance> {
  late Future<List<Map<String, dynamic>>> _future;
  @override
  void initState() {
    super.initState();
    _future = widget.api.adminAttendanceToday();
  }

  void _reload() => setState(() => _future = widget.api.adminAttendanceToday());
  @override
  Widget build(BuildContext context) =>
      FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Padding(
                  padding: EdgeInsets.all(16),
                  child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
            }
            final records = snapshot.data ?? [];
            if (records.isEmpty) {
              return const ListTile(title: Text('هنوز کسی تیک حضور نزده'));
            }
            return Column(children: [
              for (final record in records)
                ListTile(
                    title: Text(personName(keyedGroup(record['user']),
                        fallbackId: record['user_id'])),
                    subtitle: Text(
                        'ورود: ${JalaliFormat.time(DateTime.tryParse('${record['checked_in_at']}'))}'))
            ]);
          });
}
