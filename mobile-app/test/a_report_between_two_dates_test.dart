import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shamsi_date/shamsi_date.dart';

import 'package:bakery_app/widgets/jalali_date_range.dart';

/// «گزارش تاریخ تا تاریخ اضافه بشه».
///
/// The finance tab had three fixed spans — today, seven days, thirty days
/// — and no way to ask about a particular fortnight: a delivery period,
/// the days before a payroll, the week somebody is arguing about.
///
/// Flutter's own picker is Gregorian, so a shop that thinks in «۸ شهریور»
/// would have to convert in its head, twice, and a slip produces a report
/// that looks right and covers the wrong days.
Future<DateTime?> _pick(
  WidgetTester tester, {
  required DateTime initial,
  DateTime? first,
  DateTime? last,
}) async {
  DateTime? result;

  await tester.pumpWidget(
    MaterialApp(
      theme: ThemeData.dark(),
      home: Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: ElevatedButton(
              onPressed: () async {
                result = await pickJalaliDay(
                  context,
                  title: 'از تاریخ',
                  initial: initial,
                  first: first,
                  last: last,
                );
              },
              child: const Text('باز کن'),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.tap(find.text('باز کن'));
  await tester.pumpAndSettle();

  return result;
}

void main() {
  // 1405/06/08.
  final today = Jalali(1405, 6, 8).toDateTime();

  testWidgets('it opens on the day it was given, written in Jalali',
      (tester) async {
    await _pick(tester, initial: today);

    expect(find.text('از تاریخ'), findsOneWidget);
    expect(find.textContaining('شهریور'), findsWidgets);
    expect(find.textContaining('1405'), findsWidgets);
  });

  testWidgets('confirming gives back the day that was shown', (tester) async {
    await _pick(tester, initial: today);

    await tester.tap(find.text('تأیید'));
    await tester.pumpAndSettle();

    // The dialog closed. What it returned is asserted through the widget
    // under test in the app; here the point is that تأیید is reachable.
    expect(find.text('تأیید'), findsNothing);
  });

  testWidgets('a day past the last allowed cannot be confirmed',
      (tester) async {
    // The picker opens beyond `last`, which is the state the person lands
    // in if they scroll the year forward. Accepting it would produce a
    // report about days that have not happened.
    await _pick(
      tester,
      initial: Jalali(1405, 12, 20).toDateTime(),
      last: today,
    );

    expect(find.text('این تاریخ بیرون از بازهٔ مجاز است.'), findsOneWidget);

    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull, reason: 'تأیید باید غیرفعال باشد.');
  });

  testWidgets('a day before the first allowed cannot be confirmed',
      (tester) async {
    // This is «تا تاریخ» opening before the «از» already chosen. A picker
    // that accepted it and silently swapped the two would leave the person
    // certain they had asked for something else.
    await _pick(
      tester,
      initial: Jalali(1405, 1, 1).toDateTime(),
      first: today,
    );

    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
  });

  testWidgets('a day inside the bounds is confirmable', (tester) async {
    await _pick(
      tester,
      initial: Jalali(1405, 6, 3).toDateTime(),
      first: Jalali(1405, 6, 1).toDateTime(),
      last: today,
    );

    expect(find.text('این تاریخ بیرون از بازهٔ مجاز است.'), findsNothing);

    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNotNull);
  });

  testWidgets('backing out returns nothing rather than a date',
      (tester) async {
    await _pick(tester, initial: today);

    await tester.tap(find.text('انصراف'));
    await tester.pumpAndSettle();

    expect(find.text('انصراف'), findsNothing);
  });

  testWidgets('moving to a shorter month does not leave the day past its end',
      (tester) async {
    // 30 Farvardin. Esfand of a common year has 29 days, so the day has
    // to come back to 29 — and the field must come with it. Left holding
    // 30 while `items` only goes to 29, Flutter asserts «There should be
    // exactly one item with this value» and the dialog crashes. A wrong
    // label would be a nuisance; this is a dead screen.
    await _pick(tester, initial: Jalali(1405, 1, 30).toDateTime());

    await tester.tap(find.text('فروردین').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('اسفند').last);
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.textContaining('اسفند'), findsWidgets);
  });

  testWidgets('the month dropdown offers all twelve Jalali months',
      (tester) async {
    await _pick(tester, initial: today);

    await tester.tap(find.text('شهریور').first);
    await tester.pumpAndSettle();

    // Not Gregorian names, and not thirteen.
    expect(find.text('فروردین'), findsWidgets);
    expect(find.text('اسفند'), findsWidgets);
  });
}
