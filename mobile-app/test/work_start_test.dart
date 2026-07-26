import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/work_start.dart';

void main() {
  group('WorkStartType', () {
    test('reads the type the API sent', () {
      expect(WorkStartType.fromApi('chane'), WorkStartType.chane);
      expect(WorkStartType.fromApi('baking'), WorkStartType.baking);
    });

    test('falls back to chane on an unknown value', () {
      expect(WorkStartType.fromApi('cleaning'), WorkStartType.chane);
      expect(WorkStartType.fromApi(null), WorkStartType.chane);
    });
  });

  group('WorkStartItem', () {
    Map<String, dynamic> baseJson({
      bool started = false,
      bool isLate = false,
      bool overdue = false,
      bool isHoliday = false,
      int? minutesRemaining,
    }) =>
        {
          'type': 'chane',
          'label': 'شروع چانه‌گیری',
          'deadline': '05:40',
          'started': started,
          'is_late': isLate,
          'late_minutes': isLate ? 20 : 0,
          'overdue': overdue,
          'is_holiday': isHoliday,
          'minutes_remaining': minutesRemaining,
        };

    test('is not approaching when already started', () {
      final item = WorkStartItem.fromJson(baseJson(started: true));

      expect(item.isApproaching, isFalse);
    });

    test('is approaching inside the 20-minute window', () {
      final item = WorkStartItem.fromJson(baseJson(minutesRemaining: 10));

      expect(item.isApproaching, isTrue);
    });

    test('is not approaching well before the deadline', () {
      final item = WorkStartItem.fromJson(baseJson(minutesRemaining: 45));

      expect(item.isApproaching, isFalse);
    });

    test('is not approaching once the deadline has passed', () {
      final item = WorkStartItem.fromJson(baseJson(minutesRemaining: -5));

      expect(item.isApproaching, isFalse);
    });

    test('is not approaching on a holiday', () {
      final item = WorkStartItem.fromJson(
        baseJson(isHoliday: true, minutesRemaining: 10),
      );

      expect(item.isApproaching, isFalse);
    });

    test('parses a late tick with its warning', () {
      final item = WorkStartItem.fromJson({
        ...baseJson(started: true, isLate: true),
        'started_at': '06:00',
        'started_by': 'رضا',
        'warning': 'اخطار: شروع چانه‌گیری با ۲۰ دقیقه تأخیر ثبت شد.',
      });

      expect(item.started, isTrue);
      expect(item.isLate, isTrue);
      expect(item.lateMinutes, 20);
      expect(item.startedBy, 'رضا');
      expect(item.warning, contains('تأخیر'));
    });

    test('parses figures the API sent as strings', () {
      final item = WorkStartItem.fromJson({
        ...baseJson(isLate: true),
        'late_minutes': '15',
        'minutes_remaining': '-5',
      });

      expect(item.lateMinutes, 15);
      expect(item.minutesRemaining, -5);
    });
  });

  group('WorkStartBoard', () {
    Map<String, dynamic> boardJson() => {
          'date_display': '۱۴۰۵/۰۵/۰۴',
          'is_holiday': false,
          'items': [
            {
              'type': 'chane',
              'label': 'شروع چانه‌گیری',
              'deadline': '05:40',
              'started': true,
              'is_late': false,
              'late_minutes': 0,
              'overdue': false,
              'is_holiday': false,
            },
            {
              'type': 'baking',
              'label': 'شروع پخت',
              'deadline': '06:00',
              'started': false,
              'is_late': false,
              'late_minutes': 0,
              'overdue': true,
              'is_holiday': false,
            },
          ],
        };

    test('finds an item by its type', () {
      final board = WorkStartBoard.fromJson(boardJson());

      expect(board.of(WorkStartType.chane)?.started, isTrue);
      expect(board.of(WorkStartType.baking)?.overdue, isTrue);
    });

    test('problems lists anything late or overdue', () {
      final board = WorkStartBoard.fromJson(boardJson());

      expect(board.problems, hasLength(1));
      expect(board.problems.first.type, WorkStartType.baking);
    });

    test('degrades to an empty list when items are missing', () {
      final board = WorkStartBoard.fromJson({
        'date_display': '۱۴۰۵/۰۵/۰۴',
        'is_holiday': false,
      });

      expect(board.items, isEmpty);
      expect(board.of(WorkStartType.chane), isNull);
    });
  });

  group('LateTariff and LateMonthSummary', () {
    test('parses the published tariff', () {
      final tariff = LateTariff.fromJson({
        'free_days': 3,
        'summary': 'تا ۳ روز تأخیر در ماه فقط اخطار است.',
        'tier1_amount_formatted': '۲٬۰۰۰٬۰۰۰ ریال',
        'tier2_amount_formatted': '۵٬۰۰۰٬۰۰۰ ریال',
      });

      expect(tariff.freeDays, 3);
      expect(tariff.tier1Formatted, contains('۲٬۰۰۰٬۰۰۰'));
      expect(tariff.tier2Formatted, contains('۵٬۰۰۰٬۰۰۰'));
    });

    test('parses the month-to-date summary', () {
      final summary = LateMonthSummary.fromJson({
        'period_label': 'مرداد ۱۴۰۵',
        'late_days': 2,
        'warnings_remaining': 1,
        'penalty_total_formatted': '۰ تومان',
        'next_day_amount_formatted': '۲۰۰٬۰۰۰ تومان',
      });

      expect(summary.lateDays, 2);
      expect(summary.warningsRemaining, 1);
      expect(summary.nextDayAmountFormatted, contains('۲۰۰٬۰۰۰'));
    });

    Map<String, dynamic> boardJson() => {
          'date_display': '۱۴۰۵/۰۵/۰۴',
          'is_holiday': false,
          'items': const [],
        };

    test('the board carries the tariff and summary when present', () {
      final board = WorkStartBoard.fromJson({
        ...boardJson(),
        'tariff': {'free_days': 3, 'summary': 's'},
        'month_summary': {'period_label': 'p', 'late_days': 1},
      });

      expect(board.tariff?.freeDays, 3);
      expect(board.monthSummary?.lateDays, 1);
    });

    test('the board tolerates a missing tariff', () {
      final board = WorkStartBoard.fromJson(boardJson());

      expect(board.tariff, isNull);
      expect(board.monthSummary, isNull);
    });
  });
}
