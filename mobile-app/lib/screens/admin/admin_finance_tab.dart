import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../models/bakery.dart';
import '../../services/bakery_api.dart';
import '../../widgets/jalali_date_range.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../../widgets/admin_detail_group.dart';
import 'admin_home_screen.dart';
import 'balance_sheet_section.dart';
import 'bank_balances_section.dart';
import 'bank_loans_screen.dart';
import 'cash_count_sheet.dart';
import 'consumption_report_section.dart';
import 'customer_debts_section.dart';
import 'follow_ups_section.dart';
import 'income_expense_chart.dart';
import 'production_report_section.dart';
import 'profit_and_loss_section.dart';
import 'share_split_section.dart';
import 'sales_breakdown_section.dart';
import 'seller_debts_section.dart';
import 'seller_performance_section.dart';
import 'supplier_debts_section.dart';

/// درآمد، هزینه و سود در بازه انتخاب‌شده.
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
  // بازه دلخواه برای گزارش یک دوره مشخص، در کنار بازه‌های آماده.
  custom('بازهٔ دلخواه');

  const _Range(this.label);

  final String label;
}

class _AdminFinanceTabState extends State<AdminFinanceTab> {
  _Range _range = _Range.today;
  late Future<Map<String, dynamic>> _report;

  /// تاریخ‌های دلخواه هنگام تغییر بازه حفظ می‌شوند تا انتخاب دوباره لازم نباشد.
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

    // تاریخ میلادی ارسال می‌شود؛ سرور تقویم را از سال تشخیص می‌دهد.
    return widget.api.financialReport(
      from: _toApiDate(from),
      to: _toApiDate(to),
    );
  }

  /// بازه دقیق گزارش؛ جمع تولید و فروش با همین بازه محاسبه می‌شود.
  /// نمودار برای نمایش روند می‌تواند بازه گسترده‌تری داشته باشد.
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

  /// بازه نمودار در قالب مورد انتظار سرور.
  ({String from, String to, String granularity}) _apiRange() {
    final now = DateTime.now();

    return switch (_range) {
      // برای روند امروز، هفته اخیر نمایش داده می‌شود تا نمودار تنها یک ستون نباشد.
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
          // بازه‌های بلند به‌صورت ماهانه نمایش داده می‌شوند تا نمودار خوانا بماند.
          granularity:
              (_customTo ?? now).difference(_customFrom ?? now).inDays > 92
                  ? 'month'
                  : 'day',
        ),
    };
  }

  String _toApiDate(DateTime value) =>
      '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';

  void _reload() => setState(() {
        _report = _load();
      });

  /// ابتدا تاریخ شروع و سپس پایان پرسیده می‌شود. لغو هر مرحله بازه فعلی را حفظ می‌کند.
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
      // پایان نمی‌تواند پیش از شروع باشد.
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
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final range in _Range.values)
                  ChoiceChip(
                      label: Text(range.label),
                      selected: _range == range,
                      onSelected: (selected) async {
                        if (!selected || range == _range) {
                          return;
                        }
                        if (range == _Range.custom && !await _askForDates()) {
                          return;
                        }
                        if (!mounted) {
                          return;
                        }
                        setState(() => _range = range);
                        _reload();
                      }),
              ]),
              // بازه دلخواه کنار ارقام نمایش داده می‌شود تا زمان گزارش روشن باشد.
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

              const SizedBox(height: 16),
              AdminDetailGroup(
                  title: 'حساب‌ها و پرداخت‌ها',
                  subtitle: 'حساب بانکی، صندوق و اقساط وام',
                  icon: Icons.account_balance_wallet_outlined,
                  builder: (_) => Column(children: [
                        BankBalancesSection(api: widget.api),
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                            onPressed: () => Navigator.push(
                                context,
                                MaterialPageRoute<void>(
                                    builder: (_) =>
                                        BankLoansScreen(api: widget.api))),
                            icon: const Icon(Icons.account_balance),
                            label: const Text('اقساط وام بانکی و ثبت پرداخت')),
                        OutlinedButton.icon(
                            onPressed: () async {
                              if (await showCashCountSheet(
                                          context, widget.api) ==
                                      true &&
                                  mounted) {
                                setState(() {});
                              }
                            },
                            icon: const Icon(Icons.calculate_outlined),
                            label: const Text('شمارش صندوق')),
                      ])),
              AdminDetailGroup(
                  title: 'روند درآمد و هزینه',
                  subtitle: 'نمودار تغییرات در بازه انتخاب‌شده',
                  icon: Icons.show_chart,
                  builder: (_) {
                    final range = _apiRange();
                    return IncomeExpenseChart(
                        api: widget.api,
                        from: range.from,
                        to: range.to,
                        granularity: range.granularity);
                  }),
              AdminDetailGroup(
                  title: 'فروش و تولید',
                  subtitle: 'تفکیک فروش، تولید نان و مصرف آرد',
                  icon: Icons.bakery_dining_outlined,
                  builder: (_) {
                    final range = _reportRange();
                    final chartRange = _apiRange();
                    return Column(children: [
                      SalesBreakdownSection(
                          api: widget.api,
                          from: range.from,
                          to: range.to,
                          currency: widget.bakery?.currency ?? Currency.toman),
                      const SizedBox(height: 16),
                      ProductionReportSection(
                          api: widget.api, from: range.from, to: range.to),
                      const SizedBox(height: 16),
                      ConsumptionReportSection(
                          api: widget.api,
                          from: chartRange.from,
                          to: chartRange.to,
                          granularity: chartRange.granularity),
                    ]);
                  }),
              AdminDetailGroup(
                  title: 'بدهی‌ها و پیگیری',
                  subtitle: 'فروشندگان، مشتریان و تأمین‌کنندگان',
                  icon: Icons.assignment_outlined,
                  builder: (_) => Column(children: [
                        SellerDebtsSection(api: widget.api),
                        const SizedBox(height: 16),
                        CustomerDebtsSection(api: widget.api),
                        const SizedBox(height: 16),
                        SupplierDebtsSection(api: widget.api),
                        const SizedBox(height: 16),
                        FollowUpsSection(api: widget.api)
                      ])),
              AdminDetailGroup(
                  title: 'عملکرد فروشندگان',
                  subtitle: 'فروش و تسویه هر فروشنده',
                  icon: Icons.storefront_outlined,
                  builder: (_) => SellerPerformanceSection(api: widget.api)),
              AdminDetailGroup(
                  title: 'سود و سهم شرکا',
                  subtitle: 'صورت سود و زیان و پرداخت سهم',
                  icon: Icons.pie_chart_outline,
                  builder: (_) {
                    final range = _reportRange();
                    return Column(children: [
                      ProfitAndLossSection(
                          api: widget.api, from: range.from, to: range.to),
                      const SizedBox(height: 16),
                      ShareSplitSection(
                          api: widget.api, from: range.from, to: range.to)
                    ]);
                  }),
              AdminDetailGroup(
                  title: 'تراز مالی',
                  subtitle: 'دارایی‌ها، بدهی‌ها و سرمایه نانوایی',
                  icon: Icons.balance,
                  builder: (_) => BalanceSheetSection(api: widget.api)),
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
      const SizedBox(height: 12),
      Card(
          child: Column(children: [
        AdminRow(
            label: 'درآمد',
            value:
                '${income['total_formatted'] ?? income['sales_formatted'] ?? '—'}',
            icon: Icons.trending_up,
            color: AppColors.moneyIn),
        const Divider(height: 1),
        AdminRow(
            label: 'هزینه',
            value: '${expenses['total_formatted'] ?? '—'}',
            icon: Icons.trending_down,
            color: AppColors.moneyOut),
      ])),
      AdminDetailGroup(
          title: 'ریز درآمد، هزینه و حقوق',
          subtitle: 'جزئیات گزارش بازه انتخاب‌شده',
          icon: Icons.receipt_long_outlined,
          builder: (_) => Column(children: [
                const SizedBox(height: 22),
                AdminSection(
                  title: 'درآمد',
                  icon: Icons.trending_up_rounded,
                  children: [
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
              ])),
    ];
  }
}
