import 'package:flutter/material.dart';

import '../../models/flour_sale.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

typedef _FlourSalesToday = ({
  List<FlourSale> sales,
  int count,
  double totalWeightKg,
  String totalFormatted,
});

typedef _WarehouseData = ({
  List<Map<String, dynamic>> items,
  Map<String, dynamic>? quota,
  _FlourSalesToday? flour,
});

/// Stock levels, today's flour sales, and the quota for the current period.
class AdminWarehouseTab extends StatefulWidget {
  const AdminWarehouseTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminWarehouseTab> createState() => _AdminWarehouseTabState();
}

class _AdminWarehouseTabState extends State<AdminWarehouseTab> {
  late Future<_WarehouseData> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_WarehouseData> _load() async {
    final results = await Future.wait([
      widget.api.inventory(),
      widget.api.currentFlourAllocation(),
    ]);

    // Today's flour sales explain movement in the balance above, but the
    // rest of the page must still render if the call fails.
    _FlourSalesToday? flour;
    try {
      flour = await widget.api.todayFlourSales();
    } on ApiException {
      flour = null;
    }

    return (
      items: results[0] as List<Map<String, dynamic>>,
      quota: results[1] as Map<String, dynamic>?,
      flour: flour,
    );
  }

  void _reload() => setState(() => _data = _load());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<_WarehouseData>(
        future: _data,
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

          final items = snapshot.data!.items;
          final quota = snapshot.data!.quota;
          final flour = snapshot.data!.flour;

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: [
              AdminSection(
                title: 'موجودی انبار',
                icon: Icons.warehouse_rounded,
                children: [
                  for (var i = 0; i < items.length; i++) ...[
                    if (i > 0) const Divider(height: 1),
                    AdminRow(
                      label: '${items[i]['name']}',
                      value: _balanceLabel(items[i]),
                      icon: _iconFor('${items[i]['key']}'),
                      // A low balance is the one thing worth colouring.
                      color: items[i]['is_low'] == true
                          ? const Color(0xFFD1495B)
                          : null,
                      emphasise: true,
                    ),
                  ],
                ],
              ),

              if (flour != null) ...[
                const SizedBox(height: 22),
                AdminSection(
                  title: 'فروش آرد امروز',
                  icon: Icons.local_shipping_rounded,
                  children: [
                    AdminRow(
                      label: 'مجموع فروش',
                      value: flour.count == 0
                          ? 'موردی ثبت نشده'
                          : '${flour.totalWeightKg.toStringAsFixed(1)} کیلوگرم'
                              '  •  ${flour.totalFormatted}',
                      icon: Icons.inventory_2_rounded,
                      color: const Color(0xFFE8952D),
                      emphasise: true,
                    ),
                    for (final sale in flour.sales) ...[
                      const Divider(height: 1),
                      AdminRow(
                        label: sale.quantityLabel,
                        value: sale.amountFormatted,
                        icon: sale.unit == FlourUnit.bag
                            ? Icons.shopping_bag_rounded
                            : Icons.scale_rounded,
                      ),
                    ],
                  ],
                ),
              ],

              const SizedBox(height: 22),

              if (quota == null)
                const EmptyState(
                  icon: Icons.calendar_today_outlined,
                  title: 'سهمیه‌ای تعریف نشده',
                  subtitle: 'سهمیه ماهانه آرد را از پنل مدیریت ثبت کنید.',
                )
              else
                ..._buildQuota(context, quota),
            ],
          );
        },
      ),
    );
  }

  List<Widget> _buildQuota(BuildContext context, Map<String, dynamic> quota) {
    final periods = (quota['periods'] as List?) ?? const [];

    return [
      AdminSection(
        title: 'سهمیه آرد — ${quota['month_label'] ?? ''}',
        icon: Icons.calendar_month_rounded,
        children: [
          AdminRow(
            label: 'کل سهمیه ماه',
            value: '${_fmt(quota['total_kg'])} کیلوگرم',
            icon: Icons.scale_rounded,
            emphasise: true,
          ),
        ],
      ),
      const SizedBox(height: 14),
      for (final period in periods.cast<Map<String, dynamic>>())
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _PeriodCard(period: period),
        ),
    ];
  }

  static IconData _iconFor(String key) => switch (key) {
        'flour' => Icons.grain_rounded,
        'salt' => Icons.scatter_plot_rounded,
        'dough' => Icons.bakery_dining_rounded,
        _ => Icons.inventory_rounded,
      };

  static String _fmt(dynamic value) {
    final number = value is num ? value : (num.tryParse('$value') ?? 0);

    return number.toStringAsFixed(number == number.roundToDouble() ? 0 : 2);
  }

  /// "۴ کیسه  •  ۱۰۰ کیلوگرم" — the bag count leads, the weight follows.
  ///
  /// Only flour comes in fixed sacks. Salt arrives in sacks of no set size
  /// and dough is never bagged, so the server sends no bag count for them
  /// and they show their weight alone.
  static String _balanceLabel(Map<String, dynamic> item) {
    final weight = '${_fmt(item['balance'])} ${item['unit']}';
    final bags = item['balance_bags'];

    if (bags == null) return weight;

    return '${_fmt(bags)} کیسه  •  $weight';
  }
}

/// One of the three delivery periods, with a usage bar.
class _PeriodCard extends StatelessWidget {
  const _PeriodCard({required this.period});

  final Map<String, dynamic> period;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final percent = (period['usage_percent'] as num?)?.toDouble() ?? 0;
    final isCurrent = period['is_current'] == true;
    final isOver = period['is_over'] == true;

    final color = isOver
        ? const Color(0xFFD1495B)
        : percent > 80
            ? const Color(0xFFE8952D)
            : const Color(0xFF2E9E6B);

    return Card(
      // The period in progress is the one that matters most.
      shape: isCurrent
          ? RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
              side: BorderSide(color: scheme.primary, width: 2),
            )
          : null,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${period['label']}',
                    style: Theme.of(context)
                        .textTheme
                        .titleSmall
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                if (isCurrent)
                  Chip(
                    label: const Text('جاری'),
                    visualDensity: VisualDensity.compact,
                    backgroundColor: scheme.primary.withValues(alpha: 0.15),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${period['starts_on_display']} تا ${period['ends_on_display']}',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: (percent / 100).clamp(0.0, 1.0),
                minHeight: 10,
                color: color,
                backgroundColor: color.withValues(alpha: 0.15),
              ),
            ),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'مصرف ${period['used_kg']} از ${period['allocated_kg']} کیلوگرم',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                Text(
                  isOver
                      ? 'بیش از سهمیه'
                      : 'باقی‌مانده ${period['remaining_kg']} کیلوگرم',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: color,
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
