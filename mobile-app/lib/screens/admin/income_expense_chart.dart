import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';

import '../../models/financial_series.dart';
import '../../services/bakery_api.dart';
import 'admin_home_screen.dart';
import '../../theme/app_theme.dart';

/// Money in against money out, side by side.
///
/// The report already said what came in and what went out over the chosen
/// range, but as two figures — and two figures do not show that a shop is
/// spending more than it takes on Thursdays, or that a good week is carrying
/// a bad one. A pair of bars a day does.
class IncomeExpenseChart extends StatefulWidget {
  const IncomeExpenseChart({
    super.key,
    required this.api,
    required this.from,
    required this.to,
    required this.granularity,
  });

  final BakeryApi api;

  /// The same range the report above is showing, so the two agree.
  final String from;
  final String to;

  /// 'day' for a short range, 'month' for a long one — a bar per day over a
  /// year would be unreadable.
  final String granularity;

  @override
  State<IncomeExpenseChart> createState() => _IncomeExpenseChartState();
}

class _IncomeExpenseChartState extends State<IncomeExpenseChart> {
  late Future<FinancialSeries> _series;

  static const _incomeColour = AppColors.moneyIn;
  static const _expenseColour = AppColors.moneyOut;

  @override
  void initState() {
    super.initState();
    _series = _load();
  }

  @override
  void didUpdateWidget(IncomeExpenseChart oldWidget) {
    super.didUpdateWidget(oldWidget);

    // The range above changed, so this has to follow it rather than keep
    // showing last week beside this week's totals.
    if (oldWidget.from != widget.from ||
        oldWidget.to != widget.to ||
        oldWidget.granularity != widget.granularity) {
      setState(() => _series = _load());
    }
  }

  Future<FinancialSeries> _load() => widget.api.financialSeries(
        from: widget.from,
        to: widget.to,
        granularity: widget.granularity,
      );

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<FinancialSeries>(
      future: _series,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'درآمد و هزینه',
            icon: Icons.bar_chart_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // A chart is the least important thing on the page; if it cannot be
        // drawn it stays away rather than putting an error among figures.
        if (snapshot.hasError) {
          return const SizedBox.shrink();
        }

        final series = snapshot.data!;

        if (series.isEmpty || series.hasNoMovement) {
          return const AdminSection(
            title: 'درآمد و هزینه',
            icon: Icons.bar_chart_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'در این بازه گردشی نبوده است'),
            ],
          );
        }

        return AdminSection(
          title: 'درآمد و هزینه',
          icon: Icons.bar_chart_rounded,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 4),
              child: _Legend(
                incomeColour: _incomeColour,
                expenseColour: _expenseColour,
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 14, 12),
              child: SizedBox(
                height: 180,
                child: _Bars(
                  series: series,
                  incomeColour: _incomeColour,
                  expenseColour: _expenseColour,
                ),
              ),
            ),
            const Divider(height: 1),
            AdminRow(
              label: 'جمع درآمد',
              value: series.points.isEmpty
                  ? '—'
                  : _sumFormatted(series, (p) => p.incomeFormatted, series.income),
              color: _incomeColour,
            ),
            // What the takings were made of. One bar for «درآمد» could not
            // tell a month of baking from a month of selling the flour on,
            // and those are different shops.
            if (series.incomeBread > 0)
              AdminRow(
                label: '— از فروش نان',
                value: _sumFormatted(series, (p) => p.incomeFormatted, series.incomeBread),
              ),
            if (series.incomeFlour > 0)
              AdminRow(
                label: '— از فروش آرد',
                value: _sumFormatted(series, (p) => p.incomeFormatted, series.incomeFlour),
              ),
            if (series.incomeOther > 0)
              AdminRow(
                label: '— درآمد متفرقه',
                value: _sumFormatted(series, (p) => p.incomeFormatted, series.incomeOther),
              ),

            AdminRow(
              label: 'جمع هزینه',
              value: _sumFormatted(series, (p) => p.expenseFormatted, series.expense),
              color: _expenseColour,
            ),
            if (series.expenseSalaries > 0)
              AdminRow(
                label: '— از آن، حقوق',
                value: _sumFormatted(series, (p) => p.expenseFormatted, series.expenseSalaries),
              ),

            AdminRow(
              label: series.profit >= 0 ? 'سود' : 'زیان',
              value: _sumFormatted(series, (p) => p.profitFormatted, series.profit),
              color: series.profit >= 0 ? _incomeColour : _expenseColour,
              emphasise: true,
            ),
          ],
        );
      },
    );
  }

  /// The server formats each row in the shop's own unit; the totals come as
  /// bare numbers, so one row's formatting is borrowed for the separator and
  /// the unit rather than guessing at either.
  String _sumFormatted(
    FinancialSeries series,
    String Function(FinancialPoint) sample,
    double total,
  ) {
    final example = series.points.isEmpty ? '' : sample(series.points.first);
    final unit = example.replaceAll(RegExp(r'[\d,٬.\-−\s]'), '');

    final rounded = total.abs().round().toString().replaceAllMapped(
          RegExp(r'(\d)(?=(\d{3})+$)'),
          (m) => '${m[1]},',
        );

    return '${total < 0 ? '−' : ''}$rounded${unit.isEmpty ? '' : ' $unit'}';
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.incomeColour, required this.expenseColour});

  final Color incomeColour;
  final Color expenseColour;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _Dot(colour: incomeColour, label: 'درآمد'),
        const SizedBox(width: 16),
        _Dot(colour: expenseColour, label: 'هزینه'),
      ],
    );
  }
}

class _Dot extends StatelessWidget {
  const _Dot({required this.colour, required this.label});

  final Color colour;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(color: colour, shape: BoxShape.circle),
        ),
        const SizedBox(width: 6),
        Text(label, style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}

class _Bars extends StatelessWidget {
  const _Bars({
    required this.series,
    required this.incomeColour,
    required this.expenseColour,
  });

  final FinancialSeries series;
  final Color incomeColour;
  final Color expenseColour;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final points = series.points;

    // Only every other label when the bars are tight, or the dates overlap
    // into an unreadable smear.
    final labelStep = points.length > 8 ? (points.length / 5).ceil() : 1;

    return BarChart(
      BarChartData(
        maxY: series.peak * 1.15,
        alignment: BarChartAlignment.spaceAround,
        borderData: FlBorderData(show: false),
        gridData: FlGridData(
          show: true,
          drawVerticalLine: false,
          horizontalInterval: series.peak / 2,
          getDrawingHorizontalLine: (_) => FlLine(
            color: scheme.outlineVariant.withValues(alpha: 0.4),
            strokeWidth: 1,
          ),
        ),
        titlesData: FlTitlesData(
          leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
          bottomTitles: AxisTitles(
            sideTitles: SideTitles(
              showTitles: true,
              reservedSize: 34,
              getTitlesWidget: (value, meta) {
                final index = value.toInt();

                if (index < 0 || index >= points.length) {
                  return const SizedBox.shrink();
                }

                if (index % labelStep != 0) return const SizedBox.shrink();

                // The day of a Shamsi date is enough on a crowded axis; the
                // tooltip carries the whole thing.
                final label = points[index].label;
                final short = label.split('/').last;

                return Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    short,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          fontSize: 10,
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                );
              },
            ),
          ),
        ),
        barTouchData: BarTouchData(
          touchTooltipData: BarTouchTooltipData(
            getTooltipItem: (group, groupIndex, rod, rodIndex) {
              final point = points[group.x];
              final isIncome = rodIndex == 0;

              return BarTooltipItem(
                '${point.label}\n'
                '${isIncome ? point.incomeFormatted : point.expenseFormatted}',
                TextStyle(
                  color: isIncome ? incomeColour : expenseColour,
                  fontWeight: FontWeight.w700,
                  fontSize: 12,
                ),
              );
            },
          ),
        ),
        barGroups: [
          for (var i = 0; i < points.length; i++)
            BarChartGroupData(
              x: i,
              barsSpace: 3,
              barRods: [
                BarChartRodData(
                  toY: points[i].income,
                  color: incomeColour,
                  width: points.length > 10 ? 5 : 9,
                  borderRadius: BorderRadius.circular(Corner.hair),
                ),
                BarChartRodData(
                  toY: points[i].expense,
                  color: expenseColour,
                  width: points.length > 10 ? 5 : 9,
                  borderRadius: BorderRadius.circular(Corner.hair),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
