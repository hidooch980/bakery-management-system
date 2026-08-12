import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/settlement_request.dart';

void main() {
  _runningAccountTests();

  group('SettlementRequest', () {
    test('reads a request still waiting on the admin', () {
      final request = SettlementRequest.fromJson({
        'id': 3,
        'amount_formatted': '۸۵۰٬۰۰۰ تومان',
        'status': 'pending',
        'status_label': 'در انتظار تأیید مدیر',
        'requested_on_display': '۱۴۰۵/۰۵/۰۳ ۱۴:۲۰',
      });

      expect(request.isPending, isTrue);
      expect(request.isRejected, isFalse);
      expect(request.amountFormatted, '۸۵۰٬۰۰۰ تومان');
    });

    test('carries the reason a request was turned down', () {
      final request = SettlementRequest.fromJson({
        'id': 4,
        'status': 'rejected',
        'status_label': 'رد شده',
        'rejection_reason': 'مبلغ تحویلی کمتر بود',
      });

      // Without the reason the seller only sees that nothing cleared.
      expect(request.isRejected, isTrue);
      expect(request.isPending, isFalse);
      expect(request.rejectionReason, 'مبلغ تحویلی کمتر بود');
    });

    test('names the admin who agreed to it', () {
      final request = SettlementRequest.fromJson({
        'id': 5,
        'status': 'confirmed',
        'status_label': 'تأیید شده',
        'confirmed_by': 'مدیر',
      });

      expect(request.isPending, isFalse);
      expect(request.confirmedBy, 'مدیر');
    });

    test('degrades to a pending request when fields are missing', () {
      final request = SettlementRequest.fromJson({});

      expect(request.isPending, isTrue);
      expect(request.rejectionReason, isNull);
    });
  });

  group('SettleableLine', () {
    test('reads one open debt the seller could hand over', () {
      final line = SettleableLine.fromJson({
        'id': 12,
        'amount': 300000,
        'amount_formatted': '۳۰۰/۰۰۰ تومان',
        'payment_type': 'cash',
        'payment_label': 'نقدی',
        'sold_on_display': '۱۴۰۵/۰۵/۰۳ ۰۹:۱۰',
        'customer': 'مدرسه شهید بهشتی',
      });

      expect(line.id, 12);
      expect(line.amount, 300000);
      expect(line.paymentLabel, 'نقدی');
      expect(line.customer, 'مدرسه شهید بهشتی');
    });

    test('keeps the amount as a number so ticked lines can be totalled', () {
      // The picker sums the selection itself rather than parsing the
      // formatted string back apart.
      final lines = [
        SettleableLine.fromJson({'id': 1, 'amount': 300000}),
        SettleableLine.fromJson({'id': 2, 'amount': 200000}),
      ];

      expect(lines.fold<double>(0, (sum, l) => sum + l.amount), 500000);
    });

    test('survives a line with nothing but an id', () {
      final line = SettleableLine.fromJson({'id': 9});

      expect(line.amount, 0);
      expect(line.customer, isNull);
    });
  });
}

void _runningAccountTests() {
  group('SellerRunningAccount', () {
    test('reads the balance the seller pays against', () {
      final account = SellerRunningAccount.fromJson({
        'debt': 800000,
        'credit': 200000,
        'balance': 600000,
        'debt_formatted': '۸۰۰/۰۰۰ تومان',
        'credit_formatted': '۲۰۰/۰۰۰ تومان',
        'balance_formatted': '۶۰۰/۰۰۰ تومان',
      });

      // Balance is after the credit the shop already holds, so the seller
      // is never asked for money it has.
      expect(account.debt, 800000);
      expect(account.credit, 200000);
      expect(account.balance, 600000);
      expect(account.hasCredit, isTrue);
      expect(account.hasNothingToSettle, isFalse);
    });

    test('says when there is nothing to hand over', () {
      final account = SellerRunningAccount.fromJson({
        'debt': 0,
        'credit': 0,
        'balance': 0,
      });

      expect(account.hasNothingToSettle, isTrue);
      expect(account.hasCredit, isFalse);
    });

    test('keeps uncollected credit apart from the balance', () {
      // Money still with the customer: the seller cannot hand over what
      // they never collected, so it must not swell what they owe.
      final account = SellerRunningAccount.fromJson({
        'debt': 300000,
        'credit': 0,
        'balance': 300000,
        'uncollected_credit': 500000,
        'uncollected_credit_formatted': '۵۰۰/۰۰۰ تومان',
      });

      expect(account.balance, 300000);
      expect(account.uncollectedCredit, 500000);
    });

    test('survives a response with nothing in it', () {
      final account = SellerRunningAccount.fromJson({});

      expect(account.balance, 0);
      expect(account.hasNothingToSettle, isTrue);
    });
  });
}
