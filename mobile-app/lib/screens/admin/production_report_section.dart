import 'package:fl_chart/fl_chart.dart';
import '../../utils/json.dart';
import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';

/// What the shop actually made, over the range the report above is showing.
///
/// The bakery's own work had no report on the phone at all. Every figure
/// there was money — what came in, what went out, who owes what — and none
/// of it said how many sacks were kneaded or how much bread came off the
/// oven. `/reports/production` has answered that since the reports were
/// written and `BakeryApi.productionReport` was defined and never called
/// by anything.
///
/// Both systems are counted, because the shop bakes on both and a figure
/// that quietly means «normal only» is the kind that gets compared against
/// a total and found wrong.
class ProductionReportSection extends StatefulWidget {
  const ProductionReportSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
  });

  final BakeryApi api;

  /// The same range as the money report, so the two are read together.
  final String from;
  final String to;

  @override
  State<ProductionReportSection> createState() =>
      _ProductionReportSectionState();
}

class _ProductionReportSectionState extends State<ProductionReportSection> {
  late Future<Map<String, dynamic>> _report;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  @override
  void didUpdateWidget(ProductionReportSection oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.from != widget.from || oldWidget.to != widget.to) {
      setState(() => _report = _load());
    }
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.productionReport(from: widget.from, to: widget.to);

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'تولید',
            icon: Icons.bakery_dining_outlined,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // A section that cannot be read stays away rather than putting an
        // error where a figure should be, the same as every other one here.
        if (snapshot.hasError) return const SizedBox.shrink();

        final data = snapshot.data!;
        final normal = _int(data['total_chane_count']);
        final nanino = _int(data['total_nanino_count']);
        final daily = rowList(data['daily'])
            .whereType<Map<String, dynamic>>()
            .toList();

        return AdminSection(
          title: 'تولید',
          icon: Icons.bakery_dining_outlined,
          children: [
            AdminRow(
              label: 'آرد خمیر شده',
              value: '${_num(data['total_dough_bags'])} کیسه',
              emphasise: true,
            ),
            AdminRow(
              label: 'دفعات خمیر',
              value: '${_num(data['total_dough_entries'])} نوبت',
            ),

            // Named rather than «نان», because the total below is the two
            // added together and an unlabelled figure beside it would read
            // as the whole.
            AdminRow(label: 'چانه معمولی', value: '${_num(normal)} عدد'),
            if (nanino > 0)
              AdminRow(label: 'نانینو', value: '${_num(nanino)} عدد'),
            AdminRow(
              label: 'مجموع نان',
              value: '${_num(normal + nanino)} عدد',
              emphasise: true,
              color: AppColors.moneyIn,
            ),

            AdminRow(
              label: 'وزن چانه معمولی',
              value: '${_num(data['total_normal_weight_kg'])} کیلوگرم',
            ),
            if (_double(data['total_spray_flour_kg']) > 0)
              AdminRow(
                label: 'آرد پاشیدنی',
                value: '${_num(data['total_spray_flour_kg'])} کیلوگرم',
              ),

            // The daily shape, where there is more than one day of it. One
            // bar is not a trend and takes the space of the figures above.
            if (daily.length > 1) _DailyBread(days: daily),
          ],
        );
      },
    );
  }
}

/// Bread a day, over the range.
///
/// The totals above say how much; this says whether it is steady. A shop
/// that baked its month in four days has a different problem from one that
/// baked it evenly, and the totals cannot tell them apart.
class _DailyBread extends StatelessWidget {
  const _DailyBread({required this.days});

  final List<Map<String, dynamic>> days;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    final counts = days
        .map((d) => _double(d['total_bread_count']))
        .toList(growable: false);

    final peak = counts.fold<double>(0, (top, c) => c > top ? c : top);

    // Nothing baked at all is worth one sentence rather than a flat axis
    // the reader has to interpret.
    if (peak <= 0) {
      return const AdminRow(label: 'روزهای پخت', value: 'در این بازه نانی پخته نشد');
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 4, 18, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'نان هر روز',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.textTheme.bodySmall?.color,
            ),
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 120,
            child: BarChart(
              BarChartData(
                maxY: peak * 1.15,
                alignment: BarChartAlignment.spaceAround,
                borderData: FlBorderData(show: false),
                gridData: const FlGridData(show: false),
                titlesData: FlTitlesData(
                  show: true,
                  topTitles: const AxisTitles(),
                  rightTitles: const AxisTitles(),
                  leftTitles: const AxisTitles(),
                  bottomTitles: AxisTitles(
                    sideTitles: SideTitles(
                      showTitles: true,
                      reservedSize: 22,
                      // Every label would overlap past a fortnight, so
                      // only the ends are named — enough to say which way
                      // the range runs.
                      getTitlesWidget: (value, meta) {
                        final index = value.toInt();

                        if (index != 0 && index != days.length - 1) {
                          return const SizedBox.shrink();
                        }

                        return Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            '${days[index]['date_display'] ?? ''}',
                            style: theme.textTheme.labelSmall,
                          ),
                        );
                      },
                    ),
                  ),
                ),
                barTouchData: BarTouchData(
                  touchTooltipData: BarTouchTooltipData(
                    getTooltipItem: (group, _, rod, __) => BarTooltipItem(
                      '${days[group.x]['date_display'] ?? ''}\n'
                      '${_num(rod.toY)} عدد',
                      // Coloured explicitly rather than falling back to a
                      // bare TextStyle: the default is black, and this
                      // shop's phones are on the dark theme, so the
                      // tooltip would have been there and unreadable.
                      theme.textTheme.bodySmall?.copyWith(
                            color: theme.colorScheme.onSurface,
                          ) ??
                          TextStyle(color: theme.colorScheme.onSurface),
                    ),
                  ),
                ),
                barGroups: [
                  for (var i = 0; i < counts.length; i++)
                    BarChartGroupData(
                      x: i,
                      barRods: [
                        BarChartRodData(
                          toY: counts[i],
                          width: 6,
                          color: AppColors.signalFor(theme.brightness),
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ],
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

int _int(dynamic value) =>
    value is num ? value.toInt() : int.tryParse('$value') ?? 0;

double _double(dynamic value) =>
    value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

/// A figure without a pointless trailing zero: «۴٬۶۰۰», not «۴٬۶۰۰٫۰۰».
String _num(dynamic value) {
  final number = _double(value);

  return number == number.roundToDouble()
      ? number.toStringAsFixed(0)
      : number.toStringAsFixed(2);
}
