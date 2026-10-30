import 'package:bakery_app/widgets/daily_flour_period_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  Future<void> show(WidgetTester tester, Map<String, dynamic>? data) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SingleChildScrollView(
        child: DailyFlourPeriodCard(period: data),
      )),
    ));
  }

  testWidgets('پاسخ قدیمی کارت روزانه را با صفر ساختگی نشان نمی‌دهد', (tester) async {
    await show(tester, null);
    expect(find.text('دوره روزانه'), findsOneWidget);
    expect(find.textContaining('اطلاعات دوره روزانه از سرور دریافت نشد'), findsOneWidget);
    expect(find.textContaining('0 کیسه'), findsNothing);
  });

  testWidgets('تعطیلی مصرف واقعی و مانده منفی را پنهان نمی‌کند', (tester) async {
    await show(tester, {
      'date_label': '۱۴۰۵/۰۸/۰۸',
      'is_holiday': true,
      'allocated_bags': 0,
      'allocated_kg': 0,
      'used_bags': 1,
      'used_kg': 40,
      'remaining_bags': -1,
    });
    expect(find.textContaining('تعطیلی ثبت‌شده'), findsOneWidget);
    expect(find.text('1 کیسه • 40 کیلوگرم'), findsOneWidget);
    expect(find.text('-1 کیسه'), findsOneWidget);
    expect(find.text('۱۴۰۵/۰۸/۰۸ • '), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
