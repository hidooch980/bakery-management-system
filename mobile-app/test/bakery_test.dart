import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/bakery.dart';

void main() {
  group('Bakery.fromJson', () {
    test('parses the reference settings the API returns as strings', () {
      final bakery = Bakery.fromJson({
        'name': 'نانوایی ملازهی',
        'address': 'تهران',
        'phone': '02155555555',
        'normal_chane_weight_kg': '0.430',
        'nanino_chane_weight_kg': '0.380',
        'bread_price': '3000.00',
      });

      expect(bakery.name, 'نانوایی ملازهی');
      expect(bakery.normalChaneWeightKg, 0.430);
      expect(bakery.naninoChaneWeightKg, 0.380);
      expect(bakery.breadPrice, 3000);
    });

    test('accepts numeric values as well as strings', () {
      final bakery = Bakery.fromJson({
        'name': 'x',
        'normal_chane_weight_kg': 0.5,
        'bread_price': 2500,
      });

      expect(bakery.normalChaneWeightKg, 0.5);
      expect(bakery.breadPrice, 2500);
    });

    test('leaves unset settings null', () {
      final bakery = Bakery.fromJson({'name': 'x'});

      expect(bakery.normalChaneWeightKg, isNull);
      expect(bakery.naninoChaneWeightKg, isNull);
      expect(bakery.breadPrice, isNull);
      expect(bakery.hasChaneWeights, isFalse);
      expect(bakery.hasBreadPrice, isFalse);
    });
  });

  group('Bakery helpers', () {
    test('hasChaneWeights is true when either weight is configured', () {
      const onlyNormal = Bakery(name: 'x', normalChaneWeightKg: 0.43);
      const onlyNanino = Bakery(name: 'x', naninoChaneWeightKg: 0.38);
      const zeroed = Bakery(name: 'x', normalChaneWeightKg: 0);

      expect(onlyNormal.hasChaneWeights, isTrue);
      expect(onlyNanino.hasChaneWeights, isTrue);
      expect(zeroed.hasChaneWeights, isFalse);
    });

    test('hasBreadPrice ignores a zero price', () {
      expect(const Bakery(name: 'x', breadPrice: 3000).hasBreadPrice, isTrue);
      expect(const Bakery(name: 'x', breadPrice: 0).hasBreadPrice, isFalse);
    });
  });

  group('derived values', () {
    test('total chane weight scales with the configured per-chane weight', () {
      const bakery = Bakery(
        name: 'x',
        normalChaneWeightKg: 0.430,
        naninoChaneWeightKg: 0.380,
      );

      const count = 420;

      expect(count * bakery.normalChaneWeightKg!, closeTo(180.6, 0.001));
      expect(count * bakery.naninoChaneWeightKg!, closeTo(159.6, 0.001));
    });

    test('suggested sale amount is count times bread price', () {
      const bakery = Bakery(name: 'x', breadPrice: 3000);

      expect(420 * bakery.breadPrice!, 1260000);
    });
  });
}
