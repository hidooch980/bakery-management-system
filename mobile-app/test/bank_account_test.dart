import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/bank_account.dart';

void main() {
  group('BankAccount', () {
    test('reads an account the server described in full', () {
      final account = BankAccount.fromJson({
        'id': 3,
        'title': 'اصلی',
        'label': 'اصلی — ملی',
        'bank_name': 'ملی',
        'balance': 4500000,
        'balance_formatted': '۴٬۵۰۰٬۰۰۰ تومان',
        'is_default': true,
        'is_active': true,
        'is_overdrawn': false,
      });

      expect(account.id, 3);
      expect(account.label, 'اصلی — ملی');
      expect(account.balance, 4500000);
      expect(account.balanceFormatted, '۴٬۵۰۰٬۰۰۰ تومان');
      expect(account.isDefault, isTrue);
    });

    test('parses figures the API sent as strings', () {
      // Laravel serialises a decimal cast as a string.
      final account = BankAccount.fromJson({
        'id': 1,
        'title': 'اصلی',
        'balance': '250000.50',
        'balance_formatted': '۲۵۰٬۰۰۰ تومان',
        'is_active': 1,
      });

      expect(account.balance, 250000.5);
      expect(account.isActive, isTrue);
    });

    test('an overdrawn account says so', () {
      final account = BankAccount.fromJson({
        'id': 1,
        'title': 'اصلی',
        'balance': -80000,
        'balance_formatted': '−۸۰٬۰۰۰ تومان',
        'is_overdrawn': true,
      });

      expect(account.isOverdrawn, isTrue);
      expect(account.balance, lessThan(0));
    });

    test('degrades to an empty account when fields are missing', () {
      final account = BankAccount.fromJson(const {});

      expect(account.id, 0);
      expect(account.title, '');
      expect(account.balance, 0);
      expect(account.isDefault, isFalse);
    });
  });

  group('BankBalances', () {
    test('reads every account and what they come to', () {
      final balances = BankBalances.fromJson({
        'accounts': [
          {'id': 1, 'title': 'ملی', 'balance': 1000, 'balance_formatted': '۱٬۰۰۰', 'is_active': true},
          {'id': 2, 'title': 'صادرات', 'balance': 500, 'balance_formatted': '۵۰۰', 'is_active': true},
        ],
        'total_balance': 1500,
        'total_balance_formatted': '۱٬۵۰۰ تومان',
      });

      expect(balances.accounts, hasLength(2));
      expect(balances.total, 1500);
      expect(balances.totalFormatted, '۱٬۵۰۰ تومان');
      expect(balances.isEmpty, isFalse);
    });

    test('a shop with no account registered reads as empty', () {
      expect(BankBalances.fromJson(const {}).isEmpty, isTrue);
      expect(BankBalances.fromJson(const {'accounts': []}).isEmpty, isTrue);
    });

    test('skips anything in the list that is not an account', () {
      final balances = BankBalances.fromJson({
        'accounts': [
          {'id': 1, 'title': 'ملی', 'balance': 1000, 'balance_formatted': '۱٬۰۰۰'},
          'not an account',
          null,
        ],
        'total_balance': 1000,
      });

      expect(balances.accounts, hasLength(1));
    });
  });

  group('BankStatement', () {
    test('reads an account with its movements', () {
      final statement = BankStatement.fromJson({
        'account': {
          'id': 1,
          'title': 'ملی',
          'balance': 900,
          'balance_formatted': '۹۰۰',
        },
        'transactions': [
          {
            'id': 5,
            'direction': 'in',
            'amount_formatted': '۱٬۰۰۰',
            'reason_label': 'فروش نان',
            'occurred_on_display': '۱۴۰۵/۰۵/۱۲',
            'user': 'مدیر',
          },
          {
            'id': 6,
            'direction': 'out',
            'amount_formatted': '۱۰۰',
            'reason_label': 'هزینه',
          },
        ],
      });

      expect(statement.account.title, 'ملی');
      expect(statement.transactions, hasLength(2));
      expect(statement.transactions.first.isIncoming, isTrue);
      expect(statement.transactions.last.isIncoming, isFalse);
      expect(statement.transactions.first.user, 'مدیر');
    });

    test('an account with no movements yet still reads', () {
      final statement = BankStatement.fromJson({
        'account': {'id': 1, 'title': 'ملی', 'balance_formatted': '۰'},
        'transactions': [],
      });

      expect(statement.transactions, isEmpty);
      expect(statement.account.title, 'ملی');
    });

    test('anything but in counts as money leaving', () {
      // The screen colours and points the arrow off this one answer, so a
      // word it does not recognise must not read as money coming in.
      final move = BankTransaction.fromJson({'id': 1, 'direction': 'خروج'});

      expect(move.isIncoming, isFalse);
    });
  });
}
