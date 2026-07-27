import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/entries.dart';

void main() {
  group('SalePaymentLine', () {
    test('sends the payment type and loaf count the API expects', () {
      const line = SalePaymentLine(
        paymentType: PaymentType.cash,
        breadCount: 60,
        amount: 300000,
      );

      expect(line.toJson(), {
        'payment_type': 'cash',
        'bread_count': 60,
        'amount': 300000,
      });
    });

    test('leaves out an amount that was never set', () {
      const line = SalePaymentLine(
        paymentType: PaymentType.card,
        breadCount: 40,
      );

      // Sending a null amount would read as "took no money" rather than
      // "amount unknown", so the key is omitted entirely.
      expect(line.toJson().containsKey('amount'), isFalse);
      expect(line.toJson()['bread_count'], 40);
    });

    test('names the buyer when the line has one', () {
      const line = SalePaymentLine(
        paymentType: PaymentType.credit,
        breadCount: 40,
        amount: 200000,
        customerId: 7,
      );

      expect(line.toJson()['customer_id'], 7);
    });

    test('omits the buyer when the line has none', () {
      const line = SalePaymentLine(
        paymentType: PaymentType.cash,
        breadCount: 10,
      );

      expect(line.toJson().containsKey('customer_id'), isFalse);
    });
  });

  group('PaymentType', () {
    test('credit and schools are the types that need a named buyer', () {
      expect(PaymentType.credit.needsCustomer, isTrue);
      expect(PaymentType.schools.needsCustomer, isTrue);
    });

    test('a walk-in payment needs no buyer', () {
      expect(PaymentType.cash.needsCustomer, isFalse);
      expect(PaymentType.card.needsCustomer, isFalse);
      expect(PaymentType.home.needsCustomer, isFalse);
      expect(PaymentType.other.needsCustomer, isFalse);
    });
  });
}
