import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/bakery.dart';
import 'package:bakery_app/utils/formatters.dart';

void main() {
  group('JalaliFormat', () {
    // 2026-07-25 Gregorian is 1405/05/03 in the Jalali calendar.
    final gregorian = DateTime(2026, 7, 25, 8, 30);

    test('formats a date in the Jalali calendar', () {
      expect(JalaliFormat.date(gregorian), '1405/05/03');
    });

    test('formats date and time together', () {
      expect(JalaliFormat.dateTime(gregorian), '1405/05/03 — 08:30');
    });

    test('formats time on its own', () {
      expect(JalaliFormat.time(gregorian), '08:30');
    });

    test('formats a compact date for chart axes', () {
      expect(JalaliFormat.shortDate(gregorian), '05/03');
    });

    test('formats a long date with weekday and month name', () {
      expect(JalaliFormat.longDate(gregorian), contains('مرداد'));
      expect(JalaliFormat.longDate(gregorian), contains('1405'));
    });

    test('formats a month label', () {
      expect(JalaliFormat.monthLabel(gregorian), 'مرداد 1405');
    });

    test('shows a dash instead of crashing on null', () {
      expect(JalaliFormat.date(null), '—');
      expect(JalaliFormat.dateTime(null), '—');
      expect(JalaliFormat.time(null), '—');
      expect(JalaliFormat.monthLabel(null), '—');
    });

    test('parses a Jalali string back to the right Gregorian date', () {
      final parsed = JalaliFormat.parse('1405/05/03');

      expect(parsed, isNotNull);
      expect(parsed!.year, 2026);
      expect(parsed.month, 7);
      expect(parsed.day, 25);
    });

    test('parses Persian digits and alternate separators', () {
      expect(JalaliFormat.date(JalaliFormat.parse('۱۴۰۵/۰۵/۰۳')), '1405/05/03');
      expect(JalaliFormat.date(JalaliFormat.parse('1405-05-03')), '1405/05/03');
      expect(JalaliFormat.date(JalaliFormat.parse('1405.05.03')), '1405/05/03');
    });

    test('rejects malformed input rather than guessing', () {
      expect(JalaliFormat.parse(null), isNull);
      expect(JalaliFormat.parse(''), isNull);
      expect(JalaliFormat.parse('فردا'), isNull);
      expect(JalaliFormat.parse('1405/13/01'), isNull);
      expect(JalaliFormat.parse('1405/05'), isNull);
    });

    test('round-trips through the API format', () {
      final now = DateTime(2026, 3, 21);
      expect(JalaliFormat.date(JalaliFormat.parse(JalaliFormat.toApi(now))),
          JalaliFormat.date(now));
    });
  });

  group('Currency', () {
    test('maps API values and falls back to Toman', () {
      expect(Currency.fromApi('toman'), Currency.toman);
      expect(Currency.fromApi('rial'), Currency.rial);
      expect(Currency.fromApi('dollar'), Currency.toman);
      expect(Currency.fromApi(null), Currency.toman);
    });

    test('one Toman is ten Rial', () {
      expect(Currency.rial.multiplier, 10);
      expect(Currency.toman.multiplier, 1);
    });
  });

  group('MoneyFormat', () {
    test('formats stored Toman in the configured unit', () {
      expect(MoneyFormat.format(1000), '1/000 تومان');
      expect(MoneyFormat.format(1000, currency: Currency.rial), '10/000 ریال');
    });

    test('groups with a slash, the way the shop writes money', () {
      expect(
        MoneyFormat.format(10000000, currency: Currency.rial),
        '100/000/000 ریال',
      );
    });

    test('treats null as zero', () {
      expect(MoneyFormat.format(null), '0 تومان');
    });

    test('converts a typed amount back to Toman', () {
      // A Rial shop types 10,000; the API must receive 1,000 Toman.
      expect(MoneyFormat.toToman(10000, currency: Currency.rial), 1000);
      expect(MoneyFormat.toToman(1000), 1000);
    });

    test('plain output omits the unit', () {
      expect(MoneyFormat.plain(1500), '1/500');
      expect(MoneyFormat.plain(1500, currency: Currency.rial), '15/000');
    });

    test('reads back what the field shows', () {
      expect(MoneyFormat.parseInput('100/000/000'), 100000000);
      expect(MoneyFormat.parseInput('۱۲/۵۰۰'), 12500);
      expect(MoneyFormat.parseInput('1/250.5'), 1250.5);
      expect(MoneyFormat.parseInput(''), isNull);
      expect(MoneyFormat.parseInput(null), isNull);
    });
  });

  group('GroupedAmountInputFormatter', () {
    TextEditingValue typed(String text) => TextEditingValue(
          text: text,
          selection: TextSelection.collapsed(offset: text.length),
        );

    const formatter = GroupedAmountInputFormatter();

    TextEditingValue apply(String text) =>
        formatter.formatEditUpdate(const TextEditingValue(), typed(text));

    test('groups the whole part as it is typed', () {
      expect(apply('100000000').text, '100/000/000');
      expect(apply('1500').text, '1/500');
      expect(apply('12').text, '12');
    });

    test('leaves the decimal tail alone', () {
      expect(apply('1250.75').text, '1/250.75');
    });

    test('accepts Persian digits from the phone keyboard', () {
      expect(apply('۱۲۳۴۵۶').text, '123/456');
    });

    test('keeps the caret at the end while typing', () {
      final result = apply('100000');
      expect(result.selection.baseOffset, result.text.length);
    });

    test('clears to empty rather than showing a stray separator', () {
      expect(apply('').text, '');
    });
  });

  group('Bakery currency', () {
    test('parses the currency field from the API', () {
      final bakery = Bakery.fromJson({'name': 'x', 'currency': 'rial'});

      expect(bakery.currency, Currency.rial);
      expect(bakery.money(1000), '10/000 ریال');
    });

    test('defaults to Toman when the field is absent', () {
      expect(Bakery.fromJson({'name': 'x'}).currency, Currency.toman);
    });
  });
}
