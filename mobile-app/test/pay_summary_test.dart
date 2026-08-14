import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/quota_and_advance.dart';

/// What a member of staff is told about their own pay.
///
/// The card leads with one figure, and which one it leads with depends on
/// these flags. Getting that wrong shows a forecast where the shop has
/// already accepted a debt, or the other way round.
void main() {
  PaySummary parse(Map<String, dynamic> overrides) => PaySummary.fromJson({
        'period_label': 'مرداد ۱۴۰۵',
        'monthly_salary_formatted': '۱۵۰٬۰۰۰٬۰۰۰ ریال',
        'advance_outstanding': 0,
        'advance_outstanding_formatted': '۰ ریال',
        'unpaid_payslips_total_formatted': '۰ ریال',
        'unpaid_payslips_count': 0,
        'remaining_formatted': '۱۵۰٬۰۰۰٬۰۰۰ ریال',
        'carries_over': false,
        'has_pending_request': false,
        'summary': 'تا امروز از حقوق این ماه چیزی نگرفته‌اید.',
        ...overrides,
      });

  group('PaySummary', () {
    test('reads the whole payload', () {
      final pay = parse({});

      expect(pay.periodLabel, 'مرداد ۱۴۰۵');
      expect(pay.monthlySalaryFormatted, '۱۵۰٬۰۰۰٬۰۰۰ ریال');
      expect(pay.remainingFormatted, '۱۵۰٬۰۰۰٬۰۰۰ ریال');
      expect(pay.summary, isNotEmpty);
    });

    test('knows when an advance is still outstanding', () {
      expect(parse({}).owesAdvance, isFalse);
      expect(parse({'advance_outstanding': 4000000}).owesAdvance, isTrue);
    });

    test('knows when a payslip is issued and unpaid', () {
      expect(parse({}).hasUnpaidPayslips, isFalse);
      expect(parse({'unpaid_payslips_count': 1}).hasUnpaidPayslips, isTrue);
    });

    test('a wage nobody has entered stays null rather than becoming zero', () {
      // Zero would read as "you are paid nothing", which is a different
      // statement from "nobody has entered this yet".
      final pay = parse({
        'monthly_salary_formatted': null,
        'remaining_formatted': null,
      });

      expect(pay.monthlySalaryFormatted, isNull);
      expect(pay.remainingFormatted, isNull);
    });

    test('survives a payload with nothing in it', () {
      // An older server, or a response cut short. The card must draw
      // something rather than throw on a missing key.
      final pay = PaySummary.fromJson(const <String, dynamic>{});

      expect(pay.periodLabel, '');
      expect(pay.advanceOutstanding, 0);
      expect(pay.unpaidPayslipsCount, 0);
      expect(pay.monthlySalaryFormatted, isNull);
      expect(pay.owesAdvance, isFalse);
      expect(pay.hasUnpaidPayslips, isFalse);
    });

    test('carries the overdraw flag through', () {
      expect(parse({'carries_over': true}).carriesOver, isTrue);
    });

    test('carries the pending-request flag through', () {
      expect(parse({'has_pending_request': true}).hasPendingRequest, isTrue);
    });
  });
}
