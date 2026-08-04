import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/balance_sheet.dart';

void main() {
  group('BalanceSheet', () {
    test('reads both sides and what is left over', () {
      final sheet = BalanceSheet.fromJson({
        'assets': [
          {
            'key': 'bank',
            'label': 'موجودی حساب‌های بانکی',
            'amount_formatted': '۵۰٬۰۰۰٬۰۰۰ ریال',
          },
          {
            'key': 'inventory',
            'label': 'ارزش انبار',
            'amount_formatted': '۳۰٬۰۰۰٬۰۰۰ ریال',
            'note': 'به قیمت خرید',
          },
        ],
        'liabilities': [
          {
            'key': 'loans',
            'label': 'مانده وام‌ها',
            'amount_formatted': '۲۰٬۰۰۰٬۰۰۰ ریال',
          },
        ],
        'asset_total_formatted': '۸۰٬۰۰۰٬۰۰۰ ریال',
        'liability_total_formatted': '۲۰٬۰۰۰٬۰۰۰ ریال',
        'equity_formatted': '۶۰٬۰۰۰٬۰۰۰ ریال',
        'is_solvent': true,
        'as_of': '۱۴۰۵/۰۵/۱۴',
      });

      expect(sheet.assets, hasLength(2));
      expect(sheet.assets[1].note, 'به قیمت خرید');
      expect(sheet.liabilities, hasLength(1));
      expect(sheet.equityFormatted, '۶۰٬۰۰۰٬۰۰۰ ریال');
      expect(sheet.isSolvent, isTrue);
      expect(sheet.isEmpty, isFalse);
    });

    test('owing more than is held is carried as a plain answer', () {
      final sheet = BalanceSheet.fromJson({
        'assets': [
          {'key': 'bank', 'label': 'بانک', 'amount_formatted': '۵'},
        ],
        'liabilities': [
          {'key': 'loans', 'label': 'وام', 'amount_formatted': '۴۰'},
        ],
        'is_solvent': false,
      });

      // The screen says "کسری سرمایه" off this, rather than leaving it to
      // be read off a minus sign.
      expect(sheet.isSolvent, isFalse);
    });

    test('a shop that has recorded nothing reads as empty', () {
      expect(BalanceSheet.fromJson(const {}).isEmpty, isTrue);
    });

    test('names the oven and the van rather than only totalling them', () {
      final sheet = BalanceSheet.fromJson({
        'assets': [],
        'liabilities': [],
        'fixed_assets': [
          {
            'title': 'تنور دوار',
            'category_label': 'تجهیزات',
            'value_formatted': '۴۰٬۰۰۰٬۰۰۰ ریال',
            'purchased_on_display': '۱۴۰۳/۰۲/۱۰',
          },
        ],
      });

      expect(sheet.fixedAssets, hasLength(1));
      expect(sheet.fixedAssets.first.title, 'تنور دوار');
      expect(sheet.fixedAssets.first.purchasedOn, '۱۴۰۳/۰۲/۱۰');
    });

    test('an overdue loan says so', () {
      final sheet = BalanceSheet.fromJson({
        'loans': [
          {
            'title': 'وام تجهیزات',
            'lender': 'بانک سپه',
            'remaining_formatted': '۵۰٬۰۰۰٬۰۰۰ ریال',
            'progress_percent': 16.7,
            'next_due_on_display': '۱۴۰۵/۰۴/۱۰',
            'is_overdue': true,
          },
        ],
      });

      expect(sheet.loans.first.isOverdue, isTrue);
      expect(sheet.loans.first.progressPercent, 16.7);
      expect(sheet.loans.first.lender, 'بانک سپه');
    });

    test('a missing solvency answer is not read as insolvent', () {
      // Absent should not accuse the shop of owing more than it holds.
      expect(BalanceSheet.fromJson(const {}).isSolvent, isTrue);
    });

    test('anything in the lists that is not a line is skipped', () {
      final sheet = BalanceSheet.fromJson({
        'assets': [
          {'key': 'bank', 'label': 'بانک', 'amount_formatted': '۱'},
          'not a line',
          null,
        ],
      });

      expect(sheet.assets, hasLength(1));
    });
  });
}
