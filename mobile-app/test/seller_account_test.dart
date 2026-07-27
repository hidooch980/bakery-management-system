import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/seller_account.dart';

void main() {
  group('SellerAccount', () {
    test('reads every part of what the seller owes', () {
      final account = SellerAccount.fromJson({
        'cash': 480000,
        'cash_formatted': '۴۸۰٬۰۰۰ تومان',
        'difference': -20000,
        'difference_formatted': '−۲۰٬۰۰۰ تومان',
        'shortfall': 50000,
        'shortfall_formatted': '۵۰٬۰۰۰ تومان',
        'shortfall_count': 10,
        'credit': 300000,
        'credit_formatted': '۳۰۰٬۰۰۰ تومان',
        'total': 850000,
        'total_formatted': '۸۵۰٬۰۰۰ تومان',
        'entries': 3,
        'credit_sales': [
          {
            'id': 4,
            'customer': 'دبستان',
            'bread_count': 30,
            'amount_formatted': '۱۵۰٬۰۰۰ تومان',
            'date_display': '۱۴۰۵/۰۵/۰۳',
          },
        ],
      });

      expect(account.cash, 480000);
      expect(account.shortfallCount, 10);
      expect(account.credit, 300000);
      expect(account.total, 850000);
      expect(account.creditSales.single.customer, 'دبستان');
    });

    test('an account with nothing owed is clear', () {
      final account = SellerAccount.fromJson({'total': 0});

      // The card hides itself on this, so it must not be a false positive.
      expect(account.isClear, isTrue);
      expect(account.hasCredit, isFalse);
      expect(account.hasShortfall, isFalse);
      expect(account.hasDifference, isFalse);
    });

    test('a money gap counts whichever way it runs', () {
      expect(SellerAccount.fromJson({'difference': -20000}).hasDifference, isTrue);
      expect(SellerAccount.fromJson({'difference': 20000}).hasDifference, isTrue);
      expect(SellerAccount.fromJson({'difference': 0}).hasDifference, isFalse);
    });

    test('parses figures the API sent as strings', () {
      final account = SellerAccount.fromJson({
        'total': '850000.00',
        'shortfall_count': '10',
      });

      expect(account.total, 850000);
      expect(account.shortfallCount, 10);
    });

    test('degrades to an empty account when fields are missing', () {
      final account = SellerAccount.fromJson({});

      expect(account.total, 0);
      expect(account.isClear, isTrue);
      expect(account.creditSales, isEmpty);
    });

    test('a credit sale without a named buyer still parses', () {
      final account = SellerAccount.fromJson({
        'total': 100,
        'credit_sales': [
          {'id': 1, 'bread_count': 10, 'amount_formatted': '۱۰۰ تومان'},
        ],
      });

      expect(account.creditSales.single.customer, isNull);
      expect(account.creditSales.single.breadCount, 10);
    });
  });
}
