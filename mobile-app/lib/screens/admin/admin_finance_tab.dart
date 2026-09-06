import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../models/bakery.dart';
import '../../services/bakery_api.dart';
import '../../widgets/jalali_date_range.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';
import 'balance_sheet_section.dart';
import 'bank_balances_section.dart';
import 'consumption_report_section.dart';
import 'customer_debts_section.dart';
import 'follow_ups_section.dart';
import 'income_expense_chart.dart';
import 'production_report_section.dart';
import 'sales_breakdown_section.dart';
import 'seller_debts_section.dart';
import 'seller_performance_section.dart';
import 'supplier_debts_section.dart';

/// Income against expenses, with the resulting profit, for a chosen range.
class AdminFinanceTab extends StatefulWidget {
  const AdminFinanceTab({super.key, required this.api, this.bakery});

  final BakeryApi api;
  final Bakery? bakery;

  @override
  State<AdminFinanceTab> createState() => _AdminFinanceTabState();
}

enum _Range {
  today('امروز'),
  week('۷ روز اخیر'),
  month('۳۰ روز اخیر'),
  // «گزارش تاریخ تا تاریخ». The three presets answer «how is it going»;
  // this answers a question with a date in it — a delivery period, the
  // days before a payroll, the fortnight somebody is arguing about.
  custom('بازهٔ دلخواه');

  const _Range(this.label);

  final String label;
}

class _AdminFinanceTabState extends State<AdminFinanceTab> {
  _Range _range = _Range.today;
  late Future<Map<String, dynamic>> _report;

  /// Only set while [_Range.custom] is chosen. Kept when the person
  /// switches to a preset and back, so picking two dates again to correct
  /// one of them is not the price of a glance at the week.
  DateTime? _customFrom;
  DateTime? _customTo;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  Future<Map<String, dynamic>> _load() {
    final now = DateTime.now();

    final from = switch (_range) {
      _Range.today => now,
      _Range.week => now.subtract(const Duration(days: 6)),
      _Range.month => now.subtract(const Duration(days: 29)),
      _Range.custom => _customFrom ?? now,
    };

    final to = _range == _Range.custom ? (_customTo ?? now) : now;

    // Sent as Gregorian; the API takes either calendar and tells them apart
    // by the year, so no conversion is needed on this side.
    return widget.api.financialReport(
      from: _toApiDate(from),
      to: _toApiDate(to),
    );
  }

  /// Exactly the range the report above is showing.
  ///
  /// Not [_apiRange], which widens «امروز» to the week around it so the
  /// chart has more than one bar to draw. The sections below are figures
  /// rather than a trend, and a «تولید» total covering a different week
  /// from the «درآمد» above it would be read as disagreeing with it.
  ({String from, String to}) _reportRange() {
    final now = DateTime.now();

    final from = switch (_range) {
      _Range.today => now,
      _Range.week => now.subtract(const Duration(days: 6)),
      _Range.month => now.subtract(const Duration(days: 29)),
      _Range.custom => _customFrom ?? now,
    };

    return (
      from: _toApiDate(from),
      to: _toApiDate(_range == _Range.custom ? (_customTo ?? now) : now),
    );
  }

  /// The range the report is showing, as the API takes it.
  ({String from, String to, String granularity}) _apiRange() {
    final now = DateTime.now();

    return switch (_range) {
      // A day has one bar, which says nothing; the week around it does.
      _Range.today => (
          from: _toApiDate(now.subtract(const Duration(days: 6))),
          to: _toApiDate(now),
          granularity: 'day',
        ),
      _Range.week => (
          from: _toApiDate(now.subtract(const Duration(days: 6))),
          to: _toApiDate(now),
          granularity: 'day',
        ),
      _Range.month => (
          from: _toApiDate(now.subtract(const Duration(days: 29))),
          to: _toApiDate(now),
          granularity: 'day',
        ),
      _Range.custom => (
          from: _toApiDate(_customFrom ?? now),
          to: _toApiDate(_customTo ?? now),
          // A day per bar reads on a fortnight and turns to noise on a
          // year, so long spans are grouped by month.
          granularity:
              (_customTo ?? now).difference(_customFrom ?? now).inDays > 92
                  ? 'month'
                  : 'day',
        ),
    };
  }

  String _toApiDate(DateTime value) =>
      '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';

  void _reload() => setState(() => _report = _load());

  /// Asks for the two ends of the span, «از» then «تا».
  ///
  /// Returns false if the person backed out of either dialog, so the
  /// segmented button can stay where it was rather than land on a custom
  /// range that was never chosen.
  Future<bool> _askForDates() async {
    final now = DateTime.now();

    final from = await pickJalaliDay(
      context,
      title: 'از تاریخ',
      initial: _customFrom ?? now.subtract(const Duration(days: 29)),
      last: now,
    );

    if (from == null || !mounted) return false;

    final to = await pickJalaliDay(
      context,
      title: 'تا تاریخ',
      initial: _customTo != null && _customTo!.isAfter(from) ? _customTo! : now,
      // Not before the day already chosen, so the range cannot come out
      // backwards and quietly report nothing.
      first: from,
      last: now,
    );

    if (to == null) return false;

    setState(() {
      _customFrom = from;
      _customTo = to;
    });

    return true;
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<Map<String, dynamic>>(
        future: _report,
        builder: (context, snapshot) {
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: [
              SegmentedButton<_Range>(
                segments: [
                  for (final range in _Range.values)
                    ButtonSegment(value: range, label: Text(range.label)),
                ],
                selected: {_range},
                onSelectionChanged: (selection) async {
                  final chosen = selection.first;

                  if (chosen == _Range.custom) {
                    if (!await _askForDates()) return;
                  }

                  if (!mounted) return;
                  setState(() => _range = chosen);
                  _reload();
                },
                showSelectedIcon: false,
              ),
              // Which days these figures are about. Without it the custom
              // range is four numbers concerning an unknown fortnight —
              // and the preset labels say it for themselves.
              if (_range == _Range.custom && _customFrom != null)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Row(
                    children: [
                      Icon(
                        Icons.event_rounded,
                        size: IconSize.inline,
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          '${JalaliFormat.date(_customFrom)}  تا  '
                          '${JalaliFormat.date(_customTo)}',
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onSurfaceVariant,
                                  ),
                        ),
                      ),
                      TextButton(
                        onPressed: () async {
                          if (await _askForDates()) _reload();
                        },
                        child: const Text('تغییر'),
                      ),
                    ],
                  ),
                ),

              const SizedBox(height: 20),

              if (snapshot.connectionState == ConnectionState.waiting)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 60),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (snapshot.hasError)
                ErrorBox(message: '${snapshot.error}', onRetry: _reload)
              else
                ..._buildReport(context, snapshot.data!),

              // Two figures do not show a week carrying a bad day; a pair
              // of bars a day does.
              const SizedBox(height: 22),
              () {
                final range = _apiRange();

                return IncomeExpenseChart(
                  api: widget.api,
                  from: range.from,
                  to: range.to,
                  granularity: range.granularity,
                );
              }(),

              // How the takings were paid for. «فروش ۱۲٬۰۰۰٬۰۰۰» is one
              // figure covering two facts: cash in the drawer and credit
              // owed. Read as one, it says the shop has money it has not
              // been given.
              const SizedBox(height: 22),
              () {
                final range = _reportRange();

                return SalesBreakdownSection(
                  api: widget.api,
                  from: range.from,
                  to: range.to,
                  currency: widget.bakery?.currency ?? Currency.toman,
                );
              }(),

              // What the shop actually made. Every figure on this page was
              // money and none of it said how many sacks were kneaded or
              // how much bread came off the oven — in a bakery.
              const SizedBox(height: 22),
              () {
                final range = _reportRange();

                return ProductionReportSection(
                  api: widget.api,
                  from: range.from,
                  to: range.to,
                );
              }(),

              // Where the flour went. Baked and sold-on are the two halves
              // the quota is judged on, and a single «مصرف» figure hides
              // which is which.
              const SizedBox(height: 22),
              () {
                final range = _apiRange();

                return ConsumptionReportSection(
                  api: widget.api,
                  from: range.from,
                  to: range.to,
                  granularity: range.granularity,
                );
              }(),

              // What the shop has actually collected, before what it is
              // still owed — the money that is really in hand.
              const SizedBox(height: 22),
              BankBalancesSection(api: widget.api),

              // What each seller sold, before what any of them owes. The
              // debts list drops anybody at zero, so on its own it said
              // that sellers are people who owe money — the one who sells
              // all day and settles the same evening was not on any screen
              // in this app.
              const SizedBox(height: 22),
              SellerPerformanceSection(api: widget.api),

              // What the sellers still hold sits under the report: it is
              // money the shop has earned but not yet taken in.
              const SizedBox(height: 22),
              SellerDebtsSection(api: widget.api),

              // Money the shop has earned but the buyer has not paid yet.
              const SizedBox(height: 22),
              CustomerDebtsSection(api: widget.api),

              // The other side of the same question. What the schools owe
              // the shop has always been on this page; what the shop owes
              // the mill has never been anywhere.
              const SizedBox(height: 22),
              SupplierDebtsSection(api: widget.api),

              // Who has to be called today, and about what.
              const SizedBox(height: 22),
              FollowUpsSection(api: widget.api),

              // Last, because it answers the widest question: everything
              // above is this month's movement, this is where the shop
              // stands.
              const SizedBox(height: 22),
              BalanceSheetSection(api: widget.api),
            ],
          );
        },
      ),
    );
  }

  List<Widget> _buildReport(BuildContext context, Map<String, dynamic> data) {
    final income = keyedGroup(data['income']);
    final expenses = keyedGroup(data['expenses']);
    final profit = keyedGroup(data['profit']);
    final outstanding = keyedGroup(data['outstanding_salaries']);
    final byCategory = rowList(expenses['by_category']);
    final split = keyedGroup(data['profit_split']);
    final holders = rowList(split['holders']);

    final isPositive = profit['is_positive'] == true;
    final profitColor = isPositive ? AppColors.moneyIn : AppColors.moneyOut;

    return [
      Card(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
          child: Column(
            children: [
              Text(
                'سود خالص',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
              const SizedBox(height: 10),
              Text(
                '${profit['formatted'] ?? '—'}',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: profitColor,
                    ),
              ),
              const SizedBox(height: 6),
              Text(
                'حاشیه سود ${profit['margin_percent'] ?? 0}٪',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
            ],
          ),
        ),
      ),
      const SizedBox(height: 22),

      AdminSection(
        title: 'درآمد',
        icon: Icons.trending_up_rounded,
        children: [
          AdminRow(
            label: 'مجموع درآمد',
            value: '${income['total_formatted'] ?? income['sales_formatted'] ?? '—'}',
            icon: Icons.payments_rounded,
            color: AppColors.moneyIn,
            emphasise: true,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'فروش نان',
            value: '${income['bread_formatted'] ?? '—'}',
            icon: Icons.bakery_dining_rounded,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'فروش آرد',
            value: '${income['flour_formatted'] ?? '—'}',
            icon: Icons.inventory_2_rounded,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'درآمد متفرقه',
            value: '${income['other_formatted'] ?? '—'}',
            icon: Icons.account_balance_wallet_rounded,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'تعداد فروش',
            value: '${income['sales_count'] ?? 0} نان  •  '
                '${income['flour_sales_count'] ?? 0} آرد',
            icon: Icons.receipt_long_rounded,
          ),
        ],
      ),
      const SizedBox(height: 22),

      AdminSection(
        title: 'هزینه‌ها',
        icon: Icons.trending_down_rounded,
        children: [
          AdminRow(
            label: 'مجموع هزینه‌ها',
            value: '${expenses['total_formatted'] ?? '—'}',
            icon: Icons.receipt_long_rounded,
            color: AppColors.moneyOut,
            emphasise: true,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'هزینه‌های ثبت‌شده',
            value: '${expenses['recorded_formatted'] ?? '—'}',
            icon: Icons.shopping_cart_rounded,
          ),
          const Divider(height: 1),
          AdminRow(
            label: 'حقوق پرداخت‌شده',
            value: '${expenses['salaries_paid_formatted'] ?? '—'}',
            icon: Icons.badge_rounded,
          ),
        ],
      ),

      if (byCategory.isNotEmpty) ...[
        const SizedBox(height: 22),
        AdminSection(
          title: 'تفکیک هزینه',
          icon: Icons.pie_chart_rounded,
          children: [
            for (var i = 0; i < byCategory.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              AdminRow(
                label: '${byCategory[i]['label']}',
                value: '${byCategory[i]['amount_formatted']}',
              ),
            ],
          ],
        ),
      ],

      // Only shown once partners have been registered, so a single-owner
      // bakery is not given an empty section.
      if (holders.isNotEmpty) ...[
        const SizedBox(height: 22),
        AdminSection(
          title: 'تقسیم سود بین شرکا (دانگ)',
          icon: Icons.groups_rounded,
          children: [
            for (var i = 0; i < holders.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              AdminRow(
                label: '${holders[i]['name']}'
                    '  •  ${holders[i]['dang_label']}',
                value: '${holders[i]['amount_formatted']}',
                icon: Icons.person_rounded,
              ),
              if ('${holders[i]['paid']}' != '0')
                AdminRow(
                  label: 'پرداخت‌شده / مانده',
                  value: '${holders[i]['paid_formatted']}'
                      '  •  ${holders[i]['remaining_formatted']}',
                ),
            ],
          ],
        ),
      ],

      const SizedBox(height: 22),
      AdminSection(
        title: 'حقوق پرداخت‌نشده',
        icon: Icons.pending_actions_rounded,
        children: [
          AdminRow(
            label: '${outstanding['count'] ?? 0} مورد در انتظار',
            value: '${outstanding['formatted'] ?? '—'}',
            icon: Icons.schedule_rounded,
            color: AppColors.attention,
          ),
        ],
      ),
    ];
  }
}
