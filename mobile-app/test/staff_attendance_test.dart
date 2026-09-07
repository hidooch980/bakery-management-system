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

  group('what the roster row says', () {
    // The raw name is allowed to be blank — the test above pins that. What
    // is not allowed is a row on the seller's screen with a tick button and
    // no name beside it: nobody can tick in somebody they cannot identify,
    // and a blank line reads as a bug in the list rather than a missing
    // field on one person.
    test('is the name when the server sent one', () {
      final person =
          StaffAttendance.fromJson({'id': 7, 'name': 'حسن شاطر'});

      expect(person.displayName, 'حسن شاطر');
    });

    test('names the record when the name is missing or blank', () {
      expect(StaffAttendance.fromJson({'id': 7}).displayName, 'کارمند #7');
      expect(
        StaffAttendance.fromJson({'id': 7, 'name': '   '}).displayName,
        'کارمند #7',
      );
    });

    test('is never empty, whatever the row held', () {
      for (final row in <Map<String, dynamic>>[
        {},
        {'id': 0},
        {'id': 4, 'name': ''},
        {'name': null},
      ]) {
        expect(StaffAttendance.fromJson(row).displayName, isNotEmpty);
      }
    });
  });
}
