import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/models/payroll.dart';

/// A person shown to the shop is shown by name, and when the name is not
/// there the row still says which record it is.
///
/// This has now been the same bug three times — the attendance sheet, the
/// staff picker, the payroll row — because each model let `name` be blank
/// and each screen printed it straight. The models answer it once here;
/// the screens ask them rather than the field.
void main() {
  group('a staff picker entry', () {
    test('is the name when there is one', () {
      expect(
        const StaffName(id: 7, name: 'علی رضایی').displayName,
        'علی رضایی',
      );
    });

    test('names the record when there is not', () {
      // A blank line in a dropdown cannot be chosen on purpose: it reads
      // as a gap in the list rather than as one person's missing field.
      expect(const StaffName(id: 7, name: '').displayName, 'کارمند #7');
      expect(const StaffName(id: 7, name: '  ').displayName, 'کارمند #7');
    });
  });

  group('a payroll row', () {
    Employee person(String name) => Employee(
          id: 4,
          name: name,
          monthlySalary: 0,
          monthlySalaryFormatted: '',
        );

    test('is the name when there is one', () {
      expect(person('حسن شاطر').displayName, 'حسن شاطر');
    });

    test('names the record when there is not', () {
      // The row carries a salary figure. Printed against nobody it is
      // either unreadable or read as the wrong person's pay.
      expect(person('').displayName, 'کارمند #4');
    });
  });
}
