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
        {'cash', 'card', 'credit', 'home', 'schools', 'charity', 'other'},
      );
    });

    test('only charity is bread given away', () {
      // A giveaway asks for no money, so it must not be treated as a sale
      // that came up short.
      expect(PaymentType.charity.isGiveaway, isTrue);
      expect(
        PaymentType.values.where((t) => t.isGiveaway),
        [PaymentType.charity],
      );
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
}
