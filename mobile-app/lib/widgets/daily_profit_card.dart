import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../services/bakery_api.dart';
import '../theme/app_theme.dart';
import '../utils/json.dart';

/// «سود روزانه»: today's sales − flour − a day's wages − other spending, and
/// the week behind it as bars (green a profit, red a loss).
class DailyProfitCard extends StatefulWidget {
  const DailyProfitCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<DailyProfitCard> createState() => _DailyProfitCardState();
}

class _DailyProfitCardState extends State<DailyProfitCard> {
  Map<String, dynamic>? _data;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.dailyProfit();
      if (mounted) setState(() => _data = data);
    } catch (_) {
      // The rest of the dashboard does not depend on this card.
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    if (data == null) return const SizedBox.shrink();

    final days = rowList(data['days']);
    if (days.isEmpty) return const SizedBox.shrink();

    final today = days.last;
    final profit = (today['profit'] as num?)?.toDouble() ?? 0;
    final scheme = Theme.of(context).colorScheme;
    final text = Theme.of(context).textTheme;
    final values = [for (final d in days) (d['profit'] as num?)?.toDouble() ?? 0];
    final peak = values.fold<double>(1, (m, v) => v.abs() > m ? v.abs() : m);

    Widget line(String label, String value, {Color? color}) => Padding(
          padding: const EdgeInsets.only(bottom: 3),
          child: Row(children: [
            Expanded(child: Text(label, style: text.bodySmall)),
            Text(value,
                style: text.bodySmall
                    ?.copyWith(fontWeight: FontWeight.w700, color: color)),
          ]),
        );

    return Container(
      key: const Key('daily-profit-card'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(Corner.control),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            const Icon(Icons.trending_up_rounded, size: IconSize.button),
            const SizedBox(width: 8),
            Expanded(
              child: Text('سود امروز',
                  style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ),
            Text(
              '${today['profit_formatted'] ?? ''}',
              style: text.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
                color: profit >= 0 ? AppColors.moneyIn : AppColors.moneyOut,
              ),
            ),
          ]),
          const SizedBox(height: 8),
          line('فروش', '${today['income_formatted'] ?? ''}', color: AppColors.moneyIn),
          line('آرد مصرف‌شده', '− ${today['flour_formatted'] ?? ''}'),
          line('حقوق یک روز', '− ${today['wages_formatted'] ?? ''}'),
          line('سایر هزینه‌ها', '− ${today['expenses_formatted'] ?? ''}'),
          const SizedBox(height: 10),
          Text('۷ روز اخیر', style: text.bodySmall),
          const SizedBox(height: 6),
          SizedBox(
            height: 120,
            child: Directionality(
              // Oldest on the right, as a Persian reader expects time to run.
              textDirection: TextDirection.rtl,
              child: BarChart(
                BarChartData(
                  maxY: peak * 1.1,
                  minY: values.any((v) => v < 0) ? -peak * 1.1 : 0,
                  alignment: BarChartAlignment.spaceAround,
                  borderData: FlBorderData(show: false),
                  gridData: const FlGridData(show: false),
                  barTouchData: BarTouchData(
                    touchTooltipData: BarTouchTooltipData(
                      getTooltipItem: (group, _, __, ___) => BarTooltipItem(
                        '${days[group.x]['date_label']}\n${days[group.x]['profit_formatted']}',
                        TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: scheme.onInverseSurface),
                      ),
                    ),
                  ),
                  titlesData: FlTitlesData(
                    leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
                    bottomTitles: AxisTitles(
                      sideTitles: SideTitles(
                        showTitles: true,
                        reservedSize: 22,
                        getTitlesWidget: (value, meta) {
                          final i = value.toInt();
                          if (i < 0 || i >= days.length) return const SizedBox.shrink();
                          final label = '${days[i]['date_label'] ?? ''}'.split('/').last;
                          return Text(label, style: text.bodySmall?.copyWith(fontSize: 10));
                        },
                      ),
                    ),
                  ),
                  barGroups: [
                    for (var i = 0; i < values.length; i++)
                      BarChartGroupData(x: i, barRods: [
                        BarChartRodData(
                          toY: values[i],
                          width: 14,
                          color: values[i] >= 0 ? AppColors.moneyIn : AppColors.moneyOut,
                          borderRadius: BorderRadius.circular(3),
                        ),
                      ]),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 4),
          Text('${data['rule'] ?? ''}',
              style: text.bodySmall?.copyWith(color: scheme.onSurfaceVariant, fontSize: 10)),
        ],
      ),
    );
  }
}
