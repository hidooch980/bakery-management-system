import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

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
  month('۳۰ روز اخیر');

  const _Range(this.label);

  final String label;
}

class _AdminFinanceTabState extends State<AdminFinanceTab> {
  _Range _range = _Range.today;
  late Future<Map<String, dynamic>> _report;

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
    };

    // The API accepts Jalali dates, which is what the app speaks.
    return widget.api.financialReport(
      from: _toApiDate(from),
      to: _toApiDate(now),
    );
  }

  String _toApiDate(DateTime value) =>
      '${value.year}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';

  void _reload() => setState(() => _report = _load());

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
                onSelectionChanged: (selection) {
                  setState(() => _range = selection.first);
                  _reload();
                },
                showSelectedIcon: false,
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
            ],
          );
        },
      ),
    );
  }

  List<Widget> _buildReport(BuildContext context, Map<String, dynamic> data) {
    final income = data['income'] as Map<String, dynamic>? ?? const {};
    final expenses = data['expenses'] as Map<String, dynamic>? ?? const {};
    final profit = data['profit'] as Map<String, dynamic>? ?? const {};
    final outstanding = data['outstanding_salaries'] as Map<String, dynamic>? ?? const {};
    final byCategory = (expenses['by_category'] as List?) ?? const [];
    final split = data['profit_split'] as Map<String, dynamic>? ?? const {};
    final holders = (split['holders'] as List?) ?? const [];

    final isPositive = profit['is_positive'] == true;
    final profitColor = isPositive ? const Color(0xFF2E9E6B) : const Color(0xFFD1495B);

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
            color: const Color(0xFF2E9E6B),
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
            color: const Color(0xFFD1495B),
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
                label: '${(byCategory[i] as Map)['label']}',
                value: '${(byCategory[i] as Map)['amount_formatted']}',
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
                label: '${(holders[i] as Map)['name']}'
                    '  •  ${(holders[i] as Map)['dang_label']}',
                value: '${(holders[i] as Map)['amount_formatted']}',
                icon: Icons.person_rounded,
              ),
              if ('${(holders[i] as Map)['paid']}' != '0')
                AdminRow(
                  label: 'پرداخت‌شده / مانده',
                  value: '${(holders[i] as Map)['paid_formatted']}'
                      '  •  ${(holders[i] as Map)['remaining_formatted']}',
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
            color: const Color(0xFFE8952D),
          ),
        ],
      ),
    ];
  }
}
