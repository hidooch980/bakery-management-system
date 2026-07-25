import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/chane_board.dart';
import 'package:bakery_app/models/customer.dart';
import 'package:bakery_app/models/user.dart';

void main() {
  Map<String, dynamic> boardJson({
    int normal = 300,
    int nanino = 100,
    double normalWeight = 255,
    double naninoWeight = 100,
  }) =>
      {
        'date_display': '1405/05/04',
        'waiting': {'chane_count': 420, 'batches': 3},
        'today': {
          'normal_count': normal,
          'nanino_count': nanino,
          'normal_weight_kg': normalWeight,
          'nanino_weight_kg': naninoWeight,
        },
        'queues': {'pending_dough_batches': 2, 'pending_dough_bags': 24},
      };

  group('ChaneBoard', () {
    test('parses the board payload', () {
      final board = ChaneBoard.fromJson(boardJson());

      expect(board.dateDisplay, '1405/05/04');
      expect(board.waitingChane, 420);
      expect(board.waitingBatches, 3);
      expect(board.normalCount, 300);
      expect(board.naninoCount, 100);
      expect(board.pendingDoughBags, 24);
    });

    test('totals both systems together', () {
      final board = ChaneBoard.fromJson(boardJson());

      expect(board.totalCount, 400);
      expect(board.totalWeightKg, 355);
    });

    test('splits the share between the two systems', () {
      final board = ChaneBoard.fromJson(boardJson());

      expect(board.normalShare, closeTo(0.75, 0.001));
      expect(board.naninoShare, closeTo(0.25, 0.001));
      // The two shares always account for the whole.
      expect(board.normalShare + board.naninoShare, closeTo(1.0, 0.001));
    });

    test('reports a zero share rather than dividing by zero', () {
      final board = ChaneBoard.fromJson(boardJson(
        normal: 0,
        nanino: 0,
        normalWeight: 0,
        naninoWeight: 0,
      ));

      expect(board.totalCount, 0);
      expect(board.normalShare, 0);
      expect(board.naninoShare, 0);
    });

    test('names the system that produced more', () {
      final board = ChaneBoard.fromJson(boardJson(normal: 300, nanino: 100));

      expect(board.leader, ChaneSystem.normal);
      expect(board.countDifference, 200);
    });

    test('names nanino when it leads', () {
      final board = ChaneBoard.fromJson(boardJson(normal: 40, nanino: 90));

      expect(board.leader, ChaneSystem.nanino);
      expect(board.countDifference, 50);
    });

    test('reports no leader when the two are level', () {
      final board = ChaneBoard.fromJson(boardJson(normal: 100, nanino: 100));

      expect(board.leader, isNull);
      expect(board.countDifference, 0);
    });

    test('expresses normal output as a multiple of nanino', () {
      final board = ChaneBoard.fromJson(boardJson(normal: 300, nanino: 100));

      expect(board.normalToNaninoRatio, closeTo(3.0, 0.001));
    });

    test('has no ratio when there is no nanino output to compare', () {
      final board = ChaneBoard.fromJson(boardJson(normal: 300, nanino: 0));

      expect(board.normalToNaninoRatio, isNull);
    });

    test('reports the weight difference between the systems', () {
      final board = ChaneBoard.fromJson(
        boardJson(normalWeight: 255, naninoWeight: 100),
      );

      expect(board.weightDifferenceKg, closeTo(155, 0.001));
    });

    test('parses numbers the API sent as strings', () {
      final board = ChaneBoard.fromJson({
        'waiting': {'chane_count': '55', 'batches': '2'},
        'today': {'normal_count': '10', 'normal_weight_kg': '8.50'},
        'queues': {},
      });

      expect(board.waitingChane, 55);
      expect(board.normalCount, 10);
      expect(board.normalWeightKg, 8.5);
    });
  });

  group('UserRole', () {
    test('recognises the shater role', () {
      expect(UserRole.fromApi('shater'), UserRole.shater);
      expect(UserRole.shater.label, 'شاطر');
    });

    test('every role still has a label', () {
      for (final role in UserRole.values) {
        expect(role.label, isNotEmpty);
      }
    });
  });

  group('Customer', () {
    test('parses a school record', () {
      final customer = Customer.fromJson({
        'id': 3,
        'name': 'دبستان شهید بهشتی',
        'type': 'school',
        'type_label': 'مدرسه',
        'phone': '05433333333',
      });

      expect(customer.id, 3);
      expect(customer.name, 'دبستان شهید بهشتی');
      expect(customer.isSchool, isTrue);
      expect(customer.typeLabel, 'مدرسه');
    });

    test('falls back gracefully on a missing type', () {
      final customer = Customer.fromJson({'id': 1, 'name': 'x'});

      expect(customer.type, 'other');
      expect(customer.isSchool, isFalse);
    });
  });
}
