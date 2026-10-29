import 'package:bakery_app/widgets/daily_flour_quota.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  Future<void> show(WidgetTester tester, Map<String, dynamic> period) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Directionality(
          textDirection: TextDirection.rtl,
          child: DailyFlourQuota(period: period),
        ),
      ),
    ));
  }

  testWidgets('سهم روزانه به کیسه با مبنای روز کاری نمایش داده می‌شود',
      (tester) async {
    await show(tester, {'daily_pace_bags': 12.5, 'working_days': 8});
    expect(find.text('سهم روزانه آرد'), findsOneWidget);
    expect(find.text('12.5 کیسه در روز کاری'), findsOneWidget);
    expect(find.textContaining('8 روز کاری'), findsOneWidget);
  });

  testWidgets('صفر واقعی سهمیه با پاسخ قدیمی یا نامعتبر اشتباه نمی‌شود',
      (tester) async {
    await show(tester, {'daily_pace_bags': 0, 'working_days': 10});
    expect(find.text('0 کیسه در روز کاری'), findsOneWidget);
    for (final data in <Map<String, dynamic>>[
      {},
      {'daily_pace_bags': null, 'working_days': 10},
      {'daily_pace_bags': 'NaN', 'working_days': 10},
    ]) {
      await show(tester, data);
      expect(find.text('سهم روزانه آرد'), findsOneWidget);
      expect(find.textContaining('اطلاعات سهم روزانه از سرور دریافت نشد'), findsOneWidget);
      expect(find.text('0 کیسه در روز کاری'), findsNothing);
    }
  });

  testWidgets('بازه کاملاً تعطیل سهم روزانه گمراه‌کننده ندارد', (tester) async {
    await show(tester, {'daily_pace_bags': 0, 'working_days': 0});
    expect(find.text('روز کاری در این بازه ثبت نشده است.'), findsOneWidget);
    expect(find.text('0 کیسه در روز کاری'), findsNothing);
  });

  testWidgets('اعداد صحیح بدون اعشار اضافی و در صفحه باریک نمایش داده می‌شوند',
      (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await show(tester, {'daily_pace_bags': 10.0, 'working_days': 10});
    expect(find.text('10 کیسه در روز کاری'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
