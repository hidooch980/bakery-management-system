/// Money in and money out for one stretch of time — a day, a week, a month.
class FinancialPoint {
  const FinancialPoint({
    required this.label,
    required this.income,
    required this.expense,
    required this.incomeFormatted,
    required this.expenseFormatted,
    required this.profitFormatted,
  });

  /// What the shop calls this stretch: a Shamsi date, or a month's name.
  final String label;

  final double income;
  final double expense;

  final String incomeFormatted;
  final String expenseFormatted;
  final String profitFormatted;

  double get profit => income - expense;

  static double _toDouble(dynamic value) => switch (value) {
        num n => n.toDouble(),
        String s => double.tryParse(s) ?? 0,
        _ => 0,
      };

  factory FinancialPoint.fromJson(Map<String, dynamic> json) => FinancialPoint(
        label: json['label'] as String? ?? '',
        income: _toDouble(json['income']),
        expense: _toDouble(json['expense']),
        incomeFormatted: json['income_formatted'] as String? ?? '',
        expenseFormatted: json['expense_formatted'] as String? ?? '',
        profitFormatted: json['profit_formatted'] as String? ?? '',
      );
}

/// The whole run, with what it comes to.
class FinancialSeries {
  const FinancialSeries({
    required this.points,
    required this.income,
    required this.expense,
    this.granularityLabel = '',
  });

  final List<FinancialPoint> points;
  final double income;
  final double expense;
  final String granularityLabel;

  double get profit => income - expense;

  /// The tallest bar the chart has to fit. Never zero, so a shop with no
  /// takings yet still gets an axis rather than a division by nothing.
  double get peak {
    final highest = points.fold<double>(
      0,
      (top, p) => [top, p.income, p.expense].reduce((a, b) => a > b ? a : b),
    );

    return highest > 0 ? highest : 1;
  }

  bool get isEmpty => points.isEmpty;

  /// True when nothing at all moved — worth saying plainly rather than
  /// drawing an empty chart the reader has to interpret.
  bool get hasNoMovement => income == 0 && expense == 0;

  factory FinancialSeries.fromJson(Map<String, dynamic> json) {
    final totals = (json['totals'] as Map<String, dynamic>?) ?? const {};

    return FinancialSeries(
      points: ((json['rows'] as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(FinancialPoint.fromJson)
          .toList(),
      income: FinancialPoint._toDouble(totals['income']),
      expense: FinancialPoint._toDouble(totals['expense']),
      granularityLabel: json['granularity_label'] as String? ?? '',
    );
  }
}
