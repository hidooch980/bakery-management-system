import 'package:flutter/material.dart';

import '../../models/today_answer.dart';
import '../../services/bakery_api.dart';
import '../../widgets/role_home_scaffold.dart';
import 'advance_requests_section.dart';
import 'penalty_requests_section.dart';
import 'bank_loans_screen.dart';
import 'payroll_section.dart';
import 'salary_requests_section.dart';

/// هشدارهای همین پاسخ سرور در کنار فیش‌ها و درخواست‌های دریافت‌شده.
bool isTodayAction(TodayNeed need) => [
      'loan-due-',
      'monthly-wages-',
      'negative-stock-',
      'low-stock-',
      'empty-stock-',
      'diesel-tank-empty-',
      'diesel-running-out-',
    ].any(need.key.startsWith);

class TodayActions extends StatefulWidget {
  const TodayActions(
      {super.key,
      required this.api,
      required this.needs,
      required this.openNeed,
      required this.onReturn});
  final BakeryApi api;
  final List<TodayNeed> needs;
  final void Function(TodayNeed) openNeed;
  final VoidCallback onReturn;
  @override
  State<TodayActions> createState() => _TodayActionsState();
}

class _TodayActionsState extends State<TodayActions> {
  late Future<int> _salaries, _requests;
  @override
  void initState() {
    super.initState();
    _salaries = _salaryCount();
    _requests = _requestCount();
  }

  Future<int> _salaryCount() async =>
      (await widget.api.payslips(status: 'unpaid'))
          .where((p) => !p.isPaid)
          .length;
  Future<int> _requestCount() async {
    final counts = await Future.wait([
      widget.api
          .pendingAdvanceRequests()
          .then((rows) => rows.where((r) => r.isPending).length),
      widget.api
          .pendingSalaryRequests()
          .then((rows) => rows.where((r) => r.isPending).length),
    ]);
    return counts.fold<int>(0, (sum, count) => sum + count);
  }

  Future<void> _open(String title, Widget body) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(
        builder: (_) =>
            Scaffold(appBar: AppBar(title: Text(title)), body: body)));
    if (mounted) widget.onReturn();
  }

  void _payroll() => _open(
      'حقوق و پرداخت کارکنان',
      ListView(
          padding: const EdgeInsets.all(16),
          children: [PayrollSection(api: widget.api)]));
  void _requestsPage() => _open(
      'درخواست‌های پرداخت',
      ListView(padding: const EdgeInsets.all(16), children: [
        AdvanceRequestsSection(api: widget.api),
        PenaltyRequestsSection(api: widget.api),
        const SizedBox(height: 16),
        SalaryRequestsSection(api: widget.api),
      ]));

  Future<void> _follow(TodayNeed need) async {
    if (need.key.startsWith('loan-due-')) {
      await Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => BankLoansScreen(api: widget.api)));
      if (mounted) widget.onReturn();
    } else if (need.key.startsWith('monthly-wages-')) {
      _payroll();
    } else {
      final tabs = HomeTabs.of(context);
      if (tabs != null) {
        tabs.goTo('warehouse');
      } else {
        widget.openNeed(need);
      }
    }
  }

  Widget _count(
          Future<int> future, String name, VoidCallback open, bool salary) =>
      FutureBuilder<int>(
          future: future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return ListTile(
                  title: Text(name), subtitle: const Text('در حال بررسی…'));
            }
            if (snapshot.hasError) {
              return ListTile(
                  title: Text('$name دریافت نشد'),
                  subtitle: const Text('وضعیت نامشخص است؛ دوباره تلاش کنید.'),
                  trailing: IconButton(
                      tooltip: 'تلاش دوباره $name',
                      icon: const Icon(Icons.refresh),
                      onPressed: () => setState(() {
                            if (salary) {
                              _salaries = _salaryCount();
                            } else {
                              _requests = _requestCount();
                            }
                          })));
            }
            final count = snapshot.data!;
            return ListTile(
                title: Text(name),
                subtitle: Text(count == 0
                    ? 'مورد بازی در فهرست دریافتی نیست'
                    : '$count مورد در فهرست دریافتی نیازمند بررسی است'),
                trailing:
                    TextButton(onPressed: open, child: const Text('بررسی')));
          });

  @override
  Widget build(BuildContext context) {
    final needs = widget.needs.where(isTodayAction).toList();
    return Card(
        child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Text('کارهای امروز مدیر',
                          style: Theme.of(context).textTheme.titleMedium)),
                  const Padding(
                      padding: EdgeInsets.fromLTRB(16, 4, 16, 8),
                      child: Text(
                          'برای بررسی و تصمیم نهایی، بخش مربوط را باز کنید.')),
                  for (final need in needs)
                    ListTile(
                        leading: Icon(need.isCritical
                            ? Icons.error_outline
                            : Icons.info_outline),
                        title: Text(need.title),
                        subtitle:
                            need.detail.isEmpty ? null : Text(need.detail),
                        trailing: TextButton(
                            onPressed: () => _follow(need),
                            child: const Text('پیگیری'))),
                  _count(_salaries, 'فیش‌های پرداخت‌نشده', _payroll, true),
                  const Divider(height: 1),
                  _count(_requests, 'درخواست‌های منتظر بررسی', _requestsPage,
                      false),
                ])));
  }
}
