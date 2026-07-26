import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/models/flour_sale.dart';

void main() {
  group('FlourUnit', () {
    test('reads the unit the API sent', () {
      expect(FlourUnit.fromApi('bag'), FlourUnit.bag);
      expect(FlourUnit.fromApi('kg'), FlourUnit.kg);
    });

    test('falls back to kilos on an unknown unit', () {
      expect(FlourUnit.fromApi('tonne'), FlourUnit.kg);
      expect(FlourUnit.fromApi(null), FlourUnit.kg);
    });

    test('every unit has a Persian label', () {
      for (final unit in FlourUnit.values) {
        expect(unit.label, isNotEmpty);
      }
    });
  });

  group('FlourSale', () {
    test('parses a sack sale', () {
      final sale = FlourSale.fromJson({
        'id': 3,
        'unit': 'bag',
        'quantity': 2,
        'weight_kg': 80,
        'amount': 2400000,
        'payment_type': 'cash',
        'quantity_label': '۲ کیسه (۸۰ کیلوگرم)',
        'amount_formatted': '۲٬۴۰۰٬۰۰۰ تومان',
      });

      expect(sale.unit, FlourUnit.bag);
      expect(sale.weightKg, 80);
      expect(sale.paymentType, PaymentType.cash);
    });

    test('parses numbers the API sent as strings', () {
      final sale = FlourSale.fromJson({
        'id': '4',
        'unit': 'kg',
        'quantity': '12.500',
        'weight_kg': '12.500',
        'amount': '375000.00',
        'payment_type': 'credit',
      });

      expect(sale.quantity, 12.5);
      expect(sale.amount, 375000);
    });

    test('picks up the customer name on a credit sale', () {
      final sale = FlourSale.fromJson({
        'id': 5,
        'unit': 'kg',
        'quantity': 10,
        'weight_kg': 10,
        'amount': 300000,
        'payment_type': 'credit',
        'customer': {'id': 2, 'name': 'نانوایی مرکزی'},
      });

      expect(sale.customerName, 'نانوایی مرکزی');
    });

    test('survives a missing customer', () {
      final sale = FlourSale.fromJson({
        'id': 6,
        'unit': 'kg',
        'quantity': 1,
        'weight_kg': 1,
        'amount': 0,
        'payment_type': 'cash',
      });

      expect(sale.customerName, isNull);
    });
  });

  group('FlourSaleOptions', () {
    final options = FlourSaleOptions.fromJson({
      'bag_weight_kg': 40,
      'available_kg': 1000,
      'available_bags': 25,
      'currency_label': 'تومان',
      'units': [
        {'key': 'kg', 'unit_price': 30000},
        {'key': 'bag', 'unit_price': 1200000},
      ],
    });

    test('reads the rate for each unit', () {
      expect(options.priceFor(FlourUnit.kg), 30000);
      expect(options.priceFor(FlourUnit.bag), 1200000);
    });

    test('reports what is available in the selected unit', () {
      expect(options.availableIn(FlourUnit.kg), 1000);
      expect(options.availableIn(FlourUnit.bag), 25);
    });

    test('degrades to zero when a unit is missing from the response', () {
      final sparse = FlourSaleOptions.fromJson({
        'bag_weight_kg': 40,
        'available_kg': 10,
        'available_bags': 0.25,
        'units': [
          {'key': 'kg', 'unit_price': 30000},
        ],
      });

      expect(sparse.priceFor(FlourUnit.bag), 0);
    });

    test('survives a response with no units at all', () {
      final empty = FlourSaleOptions.fromJson({});

      expect(empty.priceFor(FlourUnit.kg), 0);
      expect(empty.availableKg, 0);
    });
  });
}
