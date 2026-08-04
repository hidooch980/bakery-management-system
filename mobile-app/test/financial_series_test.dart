import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/financial_series.dart';

void main() {
  group('FinancialSeries', () {
    test('reads the run and what it comes to', () {
      final series = FinancialSeries.fromJson({
        'granularity_label': 'روزانه',
        'rows': [
          {
            'label': '۱۴۰۵/۰۵/۱۰',
            'income': 500000,
            'expense': 200000,
            'income_formatted': '۵۰۰٬۰۰۰ تومان',
            'expense_formatted': '۲۰۰٬۰۰۰ تومان',
            'profit_formatted': '۳۰۰٬۰۰۰ تومان',
          },
          {
            'label': '۱۴۰۵/۰۵/۱۱',
            'income': 300000,
            'expense': 400000,
            'income_formatted': '۳۰۰٬۰۰۰ تومان',
            'expense_formatted': '۴۰۰٬۰۰۰ تومان',
            'profit_formatted': '−۱۰۰٬۰۰۰ تومان',
          },
        ],
        'totals': {'income': 800000, 'expense': 600000},
      });

      expect(series.points, hasLength(2));
      expect(series.income, 800000);
      expect(series.expense, 600000);
      expect(series.profit, 200000);
      expect(series.granularityLabel, 'روزانه');
    });

    test('a day that spent more than it took reads as a loss', () {
      final point = FinancialPoint.fromJson({
        'label': 'یک روز',
        'income': 100,
        'expense': 250,
      });

      expect(point.profit, -150);
    });

    test('the tallest bar is whichever of the two is higher', () {
      final series = FinancialSeries.fromJson({
        'rows': [
          {'label': 'الف', 'income': 100, 'expense': 900},
          {'label': 'ب', 'income': 400, 'expense': 200},
        ],
        'totals': {'income': 500, 'expense': 1100},
      });

      expect(series.peak, 900);
    });

    test('a range with nothing in it still has an axis', () {
      // Zero would divide the chart's grid by nothing.
      final series = FinancialSeries.fromJson({
        'rows': [
          {'label': 'الف', 'income': 0, 'expense': 0},
        ],
        'totals': {'income': 0, 'expense': 0},
      });

      expect(series.peak, greaterThan(0));
      expect(series.hasNoMovement, isTrue);
    });

    test('an empty answer reads as empty rather than breaking', () {
      final series = FinancialSeries.fromJson(const {});

      expect(series.isEmpty, isTrue);
      expect(series.income, 0);
      expect(series.profit, 0);
    });

    test('figures the API sent as strings are still numbers', () {
      final series = FinancialSeries.fromJson({
        'rows': [
          {'label': 'الف', 'income': '1500.50', 'expense': '500'},
        ],
        'totals': {'income': '1500.50', 'expense': '500'},
      });

      expect(series.income, 1500.5);
      expect(series.points.first.profit, 1000.5);
    });
  });
}
