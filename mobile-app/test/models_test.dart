import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/models/user.dart';
import 'package:bakery_app/theme/app_theme.dart';

void main() {
  group('UserRole', () {
    test('maps backend role strings to the enum', () {
      expect(UserRole.fromApi('admin'), UserRole.admin);
      expect(UserRole.fromApi('dough_maker'), UserRole.doughMaker);
      expect(UserRole.fromApi('chane_gir'), UserRole.chaneGir);
      expect(UserRole.fromApi('seller'), UserRole.seller);
      expect(UserRole.fromApi('nonsense'), UserRole.unknown);
      expect(UserRole.fromApi(null), UserRole.unknown);
    });

    test('exposes a Persian label for every role', () {
      for (final role in UserRole.values) {
        expect(role.label, isNotEmpty);
      }
    });
  });

  group('AppUser', () {
    test('parses the API payload including roles and permissions', () {
      final user = AppUser.fromJson({
        'id': 7,
        'name': 'رضا',
        'email': 'reza@bakery.test',
        'phone': '09120000000',
        'roles': ['dough_maker'],
        'permissions': ['record-dough', 'record-attendance'],
      });

      expect(user.id, 7);
      expect(user.role, UserRole.doughMaker);
      expect(user.can('record-dough'), isTrue);
      expect(user.can('manage-users'), isFalse);
    });

    test('falls back to unknown when no role is attached', () {
      final user = AppUser.fromJson({'id': 1, 'name': 'x', 'roles': []});
      expect(user.role, UserRole.unknown);
    });
  });

  group('DoughEntry', () {
    test('parses the payload and reports pending status', () {
      final entry = DoughEntry.fromJson({
        'id': 2,
        'bag_count': 12,
        'status': 'pending',
        'note': 'شیفت صبح',
        'user': {'name': 'رضا'},
        'created_at': '2026-07-25T08:30:00.000000Z',
      });

      expect(entry.bagCount, 12);
      expect(entry.isPending, isTrue);
      expect(entry.userName, 'رضا');
      expect(entry.createdAt, isNotNull);
    });
  });

  group('ChaneEntry', () {
    test('parses string decimals and sums the total weight', () {
      final entry = ChaneEntry.fromJson({
        'id': 3,
        'dough_entry_id': 1,
        'chane_count': 420,
        'normal_weight_kg': '180.50',
        'nanino_weight_kg': '95.25',
        'spray_flour_kg': '6.75',
        'status': 'pending',
      });

      expect(entry.normalWeightKg, 180.50);
      expect(entry.naninoWeightKg, 95.25);
      expect(entry.sprayFlourKg, 6.75);
      expect(entry.isPending, isTrue);
    });

    test('the authoritative weight is the normal chane alone', () {
      final entry = ChaneEntry.fromJson({
        'id': 3,
        'dough_entry_id': 1,
        'chane_count': 420,
        'normal_weight_kg': '180.50',
        'nanino_weight_kg': '95.25',
        'spray_flour_kg': '6.75',
        'status': 'pending',
      });

      // Sales, stock and reporting all use this figure...
      expect(entry.weightKg, 180.50);
      // ...while nanino only appears in the comparison view.
      expect(entry.displayTotalWeightKg, closeTo(275.75, 0.001));
    });

    test('treats a sold entry as not pending', () {
      final entry = ChaneEntry.fromJson({
        'id': 4,
        'dough_entry_id': 1,
        'chane_count': 10,
        'normal_weight_kg': 1,
        'nanino_weight_kg': 1,
        'spray_flour_kg': 1,
        'status': 'sold',
      });

      expect(entry.isPending, isFalse);
    });
  });

  group('PaymentType', () {
    test('covers every payment method the backend accepts', () {
      expect(
        PaymentType.values.map((t) => t.apiValue).toSet(),
        {
          'cash',
          'card',
          'credit',
          'home',
          'schools',
          'charity',
          'shortfall',
          'other',
        },
      );
    });

    test('bread given away is donated or taken home', () {
      // A giveaway asks for no money, so it must not be treated as a sale
      // that came up short, and it never lands on the seller's account.
      expect(
        PaymentType.values.where((t) => t.isGiveaway),
        [PaymentType.home, PaymentType.charity],
      );
    });

    test('a shortfall asks for no money but is not a giveaway', () {
      // The loaves left and nothing came back for them, so the seller
      // answers for it — unlike bread that was deliberately given away.
      expect(PaymentType.shortfall.isShortfall, isTrue);
      expect(PaymentType.shortfall.isGiveaway, isFalse);
      expect(PaymentType.shortfall.expectsNoAmount, isTrue);
      expect(PaymentType.charity.expectsNoAmount, isTrue);
      expect(PaymentType.cash.expectsNoAmount, isFalse);
    });

    test('falls back to other for an unrecognised value', () {
      expect(PaymentType.fromApi('bitcoin'), PaymentType.other);
    });

    test('every payment type has a Persian label', () {
      for (final type in PaymentType.values) {
        expect(type.label, isNotEmpty);
      }
    });
  });

  group('Sale', () {
    test('parses an amount supplied as a string', () {
      final sale = Sale.fromJson({
        'id': 9,
        'chane_entry_id': 3,
        'payment_type': 'card',
        'amount': '850000.00',
      });

      expect(sale.paymentType, PaymentType.card);
      expect(sale.amount, 850000.0);
    });

    test('accepts a null amount', () {
      final sale = Sale.fromJson({
        'id': 10,
        'chane_entry_id': 3,
        'payment_type': 'credit',
        'amount': null,
      });

      expect(sale.amount, isNull);
    });
  });

  group('AppTheme', () {
    test('builds a usable light and dark theme', () {
      expect(AppTheme.light().brightness, Brightness.light);
      expect(AppTheme.dark().brightness, Brightness.dark);
    });

    test('uses Material 3 in both themes', () {
      expect(AppTheme.light().useMaterial3, isTrue);
      expect(AppTheme.dark().useMaterial3, isTrue);
    });

    test('keeps tap targets large enough for the shop floor', () {
      final button = AppTheme.light().filledButtonTheme.style;
      final size = button?.minimumSize?.resolve({});

      expect(size, isNotNull);
      expect(size!.height, greaterThanOrEqualTo(48));
    });
  });

  group('what the counter offers', () {
    test('credit is not offered', () {
      // Taken off at the owner's word on 1405/06/03: bread let out on
      // trust is a debt the shop then has to chase, and he would rather
      // it were not a choice at the door.
      expect(PaymentType.choices, isNot(contains(PaymentType.credit)));
    });

    test('schools is still offered, and that is not an oversight', () {
      // Also a debt type, but a standing arrangement with a named
      // institution rather than a judgement a seller makes at the door.
      expect(PaymentType.choices, contains(PaymentType.schools));
    });

    test('an older credit sale still reads as words', () {
      // 179 loaves of credit in the current period alone, and 500,000
      // Rial of it uncollected. Dropping it from the enum would render
      // every one of those rows as «سایر».
      expect(PaymentType.fromApi('credit'), PaymentType.credit);
      expect(PaymentType.fromApi('credit').label, 'نسیه');
    });

    test('a type this build has never heard of does not crash a screen', () {
      expect(PaymentType.fromApi('something_new'), PaymentType.other);
    });
  });
}
