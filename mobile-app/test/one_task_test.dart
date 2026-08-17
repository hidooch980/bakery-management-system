import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/widgets/one_task.dart';

/// The pieces the production roles now do all their work through.
///
/// Every one of these is a claim the screens above them rely on: that the
/// button refuses to fire without an answer, that the keypad builds a
/// number the way a person expects, that a count far off the batch's yield
/// is coloured rather than silently accepted.
Future<void> _pump(WidgetTester tester, Widget child) async {
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.dark(),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: child),
      ),
    ),
  );
}

void main() {
  group('the question frame', () {
    testWidgets('shows the question and the button', (tester) async {
      await _pump(
        tester,
        OneTaskScaffold(
          question: 'چند کیسه خمیر گرفتی؟',
          actionLabel: 'ثبت کن',
          onAction: () {},
          child: const SizedBox.shrink(),
        ),
      );

      expect(find.text('چند کیسه خمیر گرفتی؟'), findsOneWidget);
      expect(find.text('ثبت کن'), findsOneWidget);
    });

    testWidgets('a question with no answer cannot be submitted', (tester) async {
      await _pump(
        tester,
        const OneTaskScaffold(
          question: 'چند چانه شد؟',
          actionLabel: 'ثبت کن',
          child: SizedBox.shrink(),
        ),
      );

      final button = tester.widget<FilledButton>(find.byType(FilledButton));

      // Null onPressed is how the screens refuse an empty answer, so a
      // disabled button here is the guard, not a cosmetic state.
      expect(button.onPressed, isNull);
    });

    testWidgets('a single step says nothing about steps', (tester) async {
      await _pump(
        tester,
        OneTaskScaffold(
          question: 'چند کیسه؟',
          step: 1,
          of: 1,
          actionLabel: 'ثبت',
          onAction: () {},
          child: const SizedBox.shrink(),
        ),
      );

      expect(find.text('1 از 1'), findsNothing);
    });

    testWidgets('two steps are counted', (tester) async {
      await _pump(
        tester,
        OneTaskScaffold(
          question: 'چند چانه؟',
          step: 2,
          of: 2,
          actionLabel: 'ثبت',
          onAction: () {},
          child: const SizedBox.shrink(),
        ),
      );

      expect(find.text('2 از 2'), findsOneWidget);
    });
  });

  group('the counter', () {
    testWidgets('opens on what it was given', (tester) async {
      await _pump(
        tester,
        OneTaskCounter(value: 13, unit: 'کیسه', onChanged: (_) {}),
      );

      expect(find.text('13'), findsOneWidget);
      expect(find.text('کیسه'), findsOneWidget);
    });

    testWidgets('steps up and down by one', (tester) async {
      var value = 13;

      await _pump(
        tester,
        StatefulBuilder(
          builder: (_, setState) => OneTaskCounter(
            value: value,
            onChanged: (v) => setState(() => value = v),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump();
      expect(value, 14);

      await tester.tap(find.byIcon(Icons.remove_rounded));
      await tester.pump();
      expect(value, 13);
    });

    testWidgets('will not go below its floor', (tester) async {
      var value = 1;

      await _pump(
        tester,
        StatefulBuilder(
          builder: (_, setState) => OneTaskCounter(
            value: value,
            onChanged: (v) => setState(() => value = v),
          ),
        ),
      );

      // Disabled rather than clamped on the way through: a button that
      // looks pressable and does nothing is worse than one that does not.
      final minus = tester.widget<OutlinedButton>(
        find.ancestor(
          of: find.byIcon(Icons.remove_rounded),
          matching: find.byType(OutlinedButton),
        ),
      );

      expect(minus.onPressed, isNull);
      expect(value, 1);
    });
  });

  group('the keypad', () {
    testWidgets('builds a number digit by digit', (tester) async {
      var value = 0;

      await _pump(
        tester,
        StatefulBuilder(
          builder: (_, setState) => OneTaskKeypad(
            value: value,
            onChanged: (v) => setState(() => value = v),
          ),
        ),
      );

      for (final key in ['۹', '۸', '۹']) {
        await tester.tap(find.text(key));
        await tester.pump();
      }

      expect(value, 989);
    });

    testWidgets('backspace takes the last digit off', (tester) async {
      var value = 989;

      await _pump(
        tester,
        StatefulBuilder(
          builder: (_, setState) => OneTaskKeypad(
            value: value,
            onChanged: (v) => setState(() => value = v),
          ),
        ),
      );

      await tester.tap(find.text('⌫'));
      await tester.pump();

      expect(value, 98);
    });

    testWidgets('an empty answer reads as a dash, not a zero', (tester) async {
      await _pump(tester, OneTaskKeypad(value: 0, onChanged: (_) {}));

      // A zero would be an answer. Nothing typed yet is not.
      expect(find.text('—'), findsOneWidget);
    });

    testWidgets('refuses a digit that would overrun the ceiling', (tester) async {
      var value = 99;

      await _pump(
        tester,
        StatefulBuilder(
          builder: (_, setState) => OneTaskKeypad(
            value: value,
            max: 999,
            onChanged: (v) => setState(() => value = v),
          ),
        ),
      );

      await tester.tap(find.text('۹'));
      await tester.pump();
      expect(value, 999);

      await tester.tap(find.text('۱'));
      await tester.pump();

      // 9991 is over the ceiling, so nothing happens rather than the
      // number silently wrapping or truncating.
      expect(value, 999);
    });

    testWidgets('a count that looks wrong is coloured, not refused', (tester) async {
      await _pump(
        tester,
        OneTaskKeypad(value: 400, looksWrong: true, onChanged: (_) {}),
      );

      final figure = tester.widget<Text>(find.text('400'));

      expect(figure.style?.color, AppColors.warning);
    });
  });

  group('what it says afterwards', () {
    testWidgets('gives the figures back', (tester) async {
      await _pump(
        tester,
        OneTaskDone(
          headline: 'ثبت شد',
          summary: const ['13 کیسه خمیر', 'ساعت 04:12'],
          actionLabel: 'یک دستهٔ دیگر',
          onAction: () {},
        ),
      );

      expect(find.text('ثبت شد'), findsOneWidget);
      expect(find.text('13 کیسه خمیر'), findsOneWidget);
      expect(find.text('ساعت 04:12'), findsOneWidget);
    });
  });

  group('the repeat question', () {
    testWidgets('asks rather than reports', (tester) async {
      var confirmed = false;
      var cancelled = false;

      await _pump(
        tester,
        OneTaskRepeatWarning(
          message: 'همین ۱۰ دقیقه پیش ۱۳ کیسه ثبت شد.',
          onCancel: () => cancelled = true,
          onConfirm: () => confirmed = true,
        ),
      );

      expect(find.text('دستهٔ تازه‌ای است؟'), findsOneWidget);

      await tester.tap(find.text('بله، تازه است'));
      expect(confirmed, isTrue);

      await tester.tap(find.text('نه، اشتباه شد'));
      expect(cancelled, isTrue);
    });
  });
}
