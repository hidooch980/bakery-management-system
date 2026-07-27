import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/bakery.dart';

void main() {
  group('Bakery tray settings', () {
    test('reads the shop tray size and the yield of one bag', () {
      final bakery = Bakery.fromJson({
        'name': 'نانوایی تست',
        'chane_per_tray': 30,
        'formula': {
          'per_bag': {'normal_chane_count': 76},
        },
      });

      expect(bakery.trayStep, 30);
      expect(bakery.normalChanePerBag, 76);
    });

    test('falls back to counting up by one without a tray size', () {
      final bakery = Bakery.fromJson({'name': 'نانوایی تست'});

      // Zero would add empty trays forever, so one is the floor.
      expect(bakery.trayStep, 1);
    });

    test('scales the expected yield by the bags kneaded', () {
      final bakery = Bakery.fromJson({
        'name': 'نانوایی تست',
        'formula': {
          'per_bag': {'normal_chane_count': 76},
        },
      });

      expect(bakery.expectedChaneFor(5), 380);
    });

    test('claims no expected yield when the formula is incomplete', () {
      final bakery = Bakery.fromJson({'name': 'نانوایی تست'});

      // A guess here would read as a real target on the entry screen.
      expect(bakery.expectedChaneFor(5), isNull);
    });

    test('survives a formula sent without a per-bag section', () {
      final bakery = Bakery.fromJson({
        'name': 'نانوایی تست',
        'formula': {'flour_bag_weight_kg': 40},
      });

      expect(bakery.normalChanePerBag, isNull);
      expect(bakery.expectedChaneFor(5), isNull);
    });
  });
}
