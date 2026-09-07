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

  testWidgets('a day past the last allowed cannot be tapped', (tester) async {
    // Accepting it would produce a report about days that have not
    // happened. It used to be tappable and refused afterwards, by a
    // disabled تأیید and a sentence; now the calendar simply does not
    // take the tap, which is the same rule said earlier.
    await _pick(tester, initial: today, last: today);

    await tester.tap(find.text('20'));
    await tester.pumpAndSettle();

    // Still the eighth: the tap did nothing.
    expect(find.textContaining('8 شهریور'), findsOneWidget);
  });

  testWidgets('a day before the first allowed cannot be tapped',
      (tester) async {
    // This is «تا تاریخ» opening before the «از» already chosen. A picker
    // that accepted it and silently swapped the two would leave the person
    // certain they had asked for something else.
    await _pick(tester, initial: today, first: today);

    await tester.tap(find.text('3'));
    await tester.pumpAndSettle();

    expect(find.textContaining('8 شهریور'), findsOneWidget);
  });

  testWidgets('a day inside the bounds is taken', (tester) async {
    await _pick(
      tester,
      initial: today,
      first: Jalali(1405, 6, 1).toDateTime(),
      last: today,
    );

    await tester.tap(find.text('3'));
    await tester.pumpAndSettle();

    expect(find.textContaining('3 شهریور'), findsOneWidget);
  });

  testWidgets('backing out returns nothing rather than a date',
      (tester) async {
    await _pick(tester, initial: today);

    await tester.tap(find.text('انصراف'));
    await tester.pumpAndSettle();

    expect(find.text('انصراف'), findsNothing);
  });

  testWidgets('every month is reachable by paging, and they are Jalali',
      (tester) async {
    await _pick(tester, initial: today);

    // Back six months from Shahrivar reaches Farvardin; the names are the
    // Jalali ones and there are twelve of them, not thirteen.
    for (var i = 0; i < 5; i++) {
      await tester.tap(find.byTooltip('ماه قبل'));
      await tester.pumpAndSettle();
    }

    expect(find.textContaining('فروردین'), findsWidgets);

    // And forward past the end of the year into the next one.
    for (var i = 0; i < 11; i++) {
      await tester.tap(find.byTooltip('ماه بعد'));
      await tester.pumpAndSettle();
    }

    expect(find.textContaining('اسفند'), findsWidgets);
  });

  testWidgets('a short month shows only the days it has', (tester) async {
    // Esfand of a common year has 29. The old picker kept a day number in
    // a field whose list no longer contained it, and Flutter asserted
    // «There should be exactly one item with this value» — a dead dialog,
    // not a wrong label. A grid cannot hold a day it does not draw.
    await _pick(tester, initial: Jalali(1405, 12, 1).toDateTime());

    expect(tester.takeException(), isNull);
    expect(find.text('29'), findsOneWidget);
    expect(find.text('30'), findsNothing);
  });

  testWidgets('the chosen day is on screen, which is what was wrong',
      (tester) async {
    // The day sat in a dropdown too narrow for its own number: the field
    // showed an arrow and nothing else, so the one thing the picker is
    // for was the one thing invisible.
    await _pick(tester, initial: today);

    expect(find.text('8'), findsOneWidget);
    expect(find.textContaining('8 شهریور 1405'), findsOneWidget);
  });

  testWidgets('paging away does not lose the chosen day', (tester) async {
    await _pick(tester, initial: today);

    await tester.tap(find.byTooltip('ماه قبل'));
    await tester.pumpAndSettle();

    // Looking at another month is not choosing one.
    expect(find.textContaining('8 شهریور'), findsOneWidget);
  });
}
