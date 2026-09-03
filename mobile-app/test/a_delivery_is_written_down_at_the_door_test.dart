import 'package:bakery_app/models/purchase.dart';
import 'package:flutter_test/flutter_test.dart';

/// What the delivery form sends up, and what it reads back.
///
/// The quantity is typed once and lands in the column the *good* dictates:
/// a sacked good is counted in sacks and the server derives the weight, an
/// unsacked one is weighed. Sending a sack count as a weight is the shape
/// of the bug that made twelve sacks of flour twelve kilograms, so it is
/// worth a test that does not need a screen to run.
void main() {
  const flour = PurchasableGood(
    key: 'flour',
    name: 'آرد',
    unit: 'کیلوگرم',
    bagWeightKg: 40,
  );

  const salt = PurchasableGood(
    key: 'salt',
    name: 'نمک',
    unit: 'کیلوگرم',
    bagWeightKg: 0,
  );

  group('what a line sends up', () {
    test('a sacked good is counted in sacks and never weighed here', () {
      final line = PurchaseLineDraft.forGood(flour, 20, 20000)!;

      expect(line.toJson()['bags'], 20);
      // The weight is the server's to derive, from the sack size it holds.
      // Sending both is how the two come to disagree.
      expect(line.toJson().containsKey('quantity_kg'), isFalse);
      expect(line.toJson()['unit_price'], 20000);
    });

    test('a good with no fixed sack is weighed', () {
      final line = PurchaseLineDraft.forGood(salt, 50, 5000)!;

      expect(line.toJson()['quantity_kg'], 50);
      expect(line.toJson().containsKey('bags'), isFalse);
    });

    test('an untouched row is dropped rather than sent', () {
      expect(PurchaseLineDraft.forGood(flour, 0, 20000), isNull);
      expect(PurchaseLineDraft.forGood(flour, 20, 0), isNull);
    });

    test('a charge carries its title and its money and no goods', () {
      final line = PurchaseLineDraft.forCharge('حمل', 1500000)!;

      expect(line.toJson()['title'], 'حمل');
      expect(line.toJson()['amount'], 1500000);
      expect(line.toJson().containsKey('item'), isFalse);
    });

    test('a charge with no name or no money is dropped', () {
      expect(PurchaseLineDraft.forCharge('  ', 1500000), isNull);
      expect(PurchaseLineDraft.forCharge('حمل', 0), isNull);
    });
  });

  group('what the form is given', () {
    test('the options arrive in one shape', () {
      final options = PurchaseOptions.fromJson(const {
        'suppliers': [
          {'id': 1, 'name': 'کارخانه آرد زاهدان', 'kind': 'کارخانه آرد'},
        ],
        'items': [
          {'key': 'flour', 'name': 'آرد', 'unit': 'کیلوگرم', 'bag_weight_kg': 40},
          {'key': 'salt', 'name': 'نمک', 'unit': 'کیلوگرم', 'bag_weight_kg': 0},
        ],
        'accounts': [
          {'id': 3, 'title': 'حساب اصلی', 'is_default': true},
        ],
        'currency_label': 'ریال',
      });

      expect(options.suppliers.single.name, 'کارخانه آرد زاهدان');
      expect(options.goods.first.isSacked, isTrue);
      // Zero is the signal to ask for kilograms and offer no sack count.
      expect(options.goods.last.isSacked, isFalse);
      expect(options.accounts.single.isDefault, isTrue);
      expect(options.currencyLabel, 'ریال');
    });

    test('a delivery reads back with its lines', () {
      final purchase = Purchase.fromJson(const {
        'id': 7,
        'supplier_name': 'کارخانه آرد زاهدان',
        'purchased_on_display': '۱۴۰۵/۰۶/۱۲',
        'amount_formatted': '۱۶۰،۰۰۰،۰۰۰ ریال',
        'outstanding_formatted': '۱۲۰،۰۰۰،۰۰۰ ریال',
        'is_settled': false,
        'invoice_no': 'A-1',
        'items': [
          {
            'label': 'آرد',
            'quantity_label': '۲۰ کیسه  •  ۸۰۰ کیلوگرم',
            'amount_formatted': '۱۶۰،۰۰۰،۰۰۰ ریال',
          },
        ],
      });

      expect(purchase.supplierName, 'کارخانه آرد زاهدان');
      expect(purchase.isSettled, isFalse);
      expect(purchase.lines.single.quantityLabel, '۲۰ کیسه  •  ۸۰۰ کیلوگرم');
    });
  });

  group('what the shop owes', () {
    test('a positive balance is a debt and a negative one is credit', () {
      final owed = SupplierBalance.fromJson(const {
        'id': 1,
        'name': 'کارخانه',
        'balance': 12000000,
        'balance_formatted': '۱۲،۰۰۰،۰۰۰ تومان',
        'invoices': 3,
        'unpaid_invoices': 1,
      });

      expect(owed.weOwe, isTrue);
      expect(owed.unpaidInvoices, 1);

      // A mill holding the shop's money is a fact worth showing, not one
      // to clamp to zero.
      final ahead = SupplierBalance.fromJson(const {
        'id': 2,
        'name': 'بنکدار',
        'balance': -500000,
        'balance_formatted': '−۵۰۰،۰۰۰ تومان',
        'invoices': 1,
        'unpaid_invoices': 0,
      });

      expect(ahead.weOwe, isFalse);
      expect(ahead.balance, lessThan(0));
    });

    test('a balance sent as a string still reads as a number', () {
      // Laravel hands decimals back as strings often enough that every
      // model in this app parses both.
      final row = SupplierBalance.fromJson(const {
        'id': 1,
        'name': 'کارخانه',
        'balance': '12000000.00',
        'balance_formatted': '',
        'invoices': 1,
        'unpaid_invoices': 1,
      });

      expect(row.balance, 12000000);
    });
  });
}
