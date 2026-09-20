import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/widgets/seller_ask.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The seller's «یک کار» names the batch and sends him to account for it.
///
/// The seller's job is not «how many did you sell». What the shop needs is
/// how one batch *divided*: card, schools, home, charity, and whatever is
/// left as a shortfall on his own account. One number cannot say that.
///
/// This screen used to answer it in one tap — «بله — همه نقدی» posted the
/// whole batch as cash. Cash came off what a seller may put on a sale on
/// 1405/06/29, at the owner's word, and a one-tap shortcut for it would
/// have made that removal cosmetic. So the question is gone and the sheet
/// is the only way through.
void main() {
  ChaneEntry batch({int count = 755}) => ChaneEntry(
        id: 1,
        doughEntryId: 1,
        chaneCount: count,
        normalWeightKg: 640,
        naninoWeightKg: 0,
        sprayFlourKg: 0,
        status: 'pending',
      );

  Future<void> pump(
    WidgetTester tester, {
    required VoidCallback onSplit,
    ChaneEntry? chane,
  }) {
    return tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SellerAsk(
            chane: chane ?? batch(),
            bakery: null,
            onSplit: onSplit,
          ),
        ),
      ),
    );
  }

  testWidgets('it names the batch rather than asking for a number',
      (tester) async {
    await pump(tester, onSplit: () {});

    expect(find.text('این چانه کجا رفت؟'), findsOneWidget);
    expect(find.text('755'), findsOneWidget);
  });

  testWidgets('there is no one-tap way to call the batch cash',
      (tester) async {
    // The whole of the change in one assertion. A button that posted the
    // batch as cash would have left the removal cosmetic.
    await pump(tester, onSplit: () {});

    expect(find.text('بله — همه نقدی'), findsNothing);
    expect(find.textContaining('نقدی'), findsNothing);
  });

  /// The exception path must stay one tap away. A shortfall lands on the
  /// seller's own account, so a screen that made it hard to reach would be
  /// quietly charging him for bread he did not take.
  testWidgets('saying otherwise opens the full sheet', (tester) async {
    var split = 0;

    await pump(tester, onSplit: () => split++);
    await tester.tap(find.text('ثبت فروش'));

    expect(split, 1);
  });

  testWidgets('it names what the sheet is for', (tester) async {
    await pump(tester, onSplit: () {});

    expect(find.textContaining('کارتخوان'), findsOneWidget);
    expect(find.textContaining('کسری'), findsOneWidget);
  });

  testWidgets('it reads the batch it was given, not a remembered one',
      (tester) async {
    await pump(
      tester,
      chane: batch(count: 412),
      onSplit: () {},
    );

    expect(find.text('412'), findsOneWidget);
    expect(find.text('755'), findsNothing);
  });
}
