import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/entries.dart';

void main() {
  group('StaffAttendance', () {
    test('reads someone who has not arrived yet', () {
      final person = StaffAttendance.fromJson({
        'id': 7,
        'name': 'حسن شاطر',
        'role': 'shater',
        'checked_in': false,
      });

      expect(person.checkedIn, isFalse);
      expect(person.checkedInAt, isNull);
      expect(person.recordedByAnother, isFalse);
    });

    test('reads someone the seller already ticked in', () {
      final person = StaffAttendance.fromJson({
        'id': 7,
        'name': 'حسن شاطر',
        'checked_in': true,
        'checked_in_at': '06:40',
        'recorded_by_another': true,
      });

      expect(person.checkedIn, isTrue);
      expect(person.checkedInAt, '06:40');
      // A tick entered on someone's behalf is a different fact from one
      // they made themselves, and the sheet has to keep them apart.
      expect(person.recordedByAnother, isTrue);
    });

    test('treats a missing recorded_by_another as their own tick', () {
      final person = StaffAttendance.fromJson({
        'id': 3,
        'name': 'رضا',
        'checked_in': true,
      });

      expect(person.recordedByAnother, isFalse);
    });

    test('survives a row with nothing but an id', () {
      final person = StaffAttendance.fromJson({'id': 1});

      expect(person.name, '');
      expect(person.checkedIn, isFalse);
    });
  });
}
