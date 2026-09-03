import 'package:bakery_app/models/bakery.dart';
import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/widgets/seller_ask.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The seller's «یک کار» asks the question the day almost always answers.
///
/// The seller's job is not «how many did you sell» — my first drawing of
/// this screen was that, and it was wrong. What the shop needs is how one
/// batch *divided*: cash, card, schools, home, charity, and whatever is
/// left as a shortfall on his own account. One number cannot say that.
///
/// It is very nearly always all cash, though, and the old sheet already
/// assumed so — it pre-filled cash with the whole batch and then asked the
/// seller to scroll past five more fields to agree. So the screen states
/// the assumption and offers two answers, and the second one opens the
/// same sheet as before.
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
    required VoidCallback onAllCash,
    required VoidCallback onSplit,
    bool saving = false,
    ChaneEntry? chane,
  }) {
    return tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SellerAsk(
            chane: chane ?? batch(),
            bakery: null,
            saving: saving,
            onAllCash: onAllCash,
            onSplit: onSplit,
          ),
        ),
      ),
    );
  }

  testWidgets('it states the assumption rather than asking for a number',
      (tester) async {
    await pump(tester, onAllCash: () {}, onSplit: () {});

    expect(find.text('همه‌اش نقدی بود؟'), findsOneWidget);
    expect(find.text('755'), findsOneWidget);
  });

  testWidgets('the yellow button answers the common day', (tester) async {
    var confirmed = 0;

    await pump(tester, onAllCash: () => confirmed++, onSplit: () {});
    await tester.tap(find.text('بله — همه نقدی'));

    expect(confirmed, 1);
  });

  /// The exception path must stay one tap away. A shortfall lands on the
  /// seller's own account, so a screen that made it hard to reach would be
  /// quietly charging him for bread he did not take.
  testWidgets('saying otherwise opens the full sheet', (tester) async {
    var split = 0;

    await pump(tester, onAllCash: () {}, onSplit: () => split++);
    await tester.tap(find.text('نه، فرق داشت'));

    expect(split, 1);
  });

  testWidgets('it names what the other path is for', (tester) async {
    await pump(tester, onAllCash: () {}, onSplit: () {});

    expect(find.textContaining('کارتخوان'), findsOneWidget);
    expect(find.textContaining('کسری'), findsOneWidget);
  });

  /// Two taps on a slow connection would be two sales for one batch, and
  /// the second would be a duplicate the shop has to unpick by hand.
  testWidgets('neither button answers twice while a sale is in flight',
      (tester) async {
    var confirmed = 0;
    var split = 0;

    await pump(
      tester,
      saving: true,
      onAllCash: () => confirmed++,
      onSplit: () => split++,
    );

    await tester.tap(find.byType(FilledButton));
    await tester.tap(find.byType(OutlinedButton));

    expect(confirmed, 0);
    expect(split, 0);
  });

  testWidgets('it reads the batch it was given, not a remembered one',
      (tester) async {
    await pump(
      tester,
      chane: batch(count: 412),
      onAllCash: () {},
      onSplit: () {},
    );

    expect(find.text('412'), findsOneWidget);
    expect(find.text('755'), findsNothing);
  });
}
