import 'package:bakery_app/models/chane_board.dart';
import 'package:bakery_app/widgets/seller_daily_production_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  ChaneBoard board(int bags, {String date = '۱۴۰۵/۰۸/۰۴'}) =>
      ChaneBoard.fromJson({
        'date_display': date,
        'dough_today': {'bags': bags},
        // تعداد چانه و صف خمیر نباید جای جمع کیسه‌های امروز خوانده شوند.
        'today': {'normal_count': 900},
        'queues': {'pending_dough_bags': 2},
      });

  Future<void> show(WidgetTester tester, ChaneBoard? production,
      {VoidCallback? onRetry}) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: Directionality(
          textDirection: TextDirection.rtl,
          child: SingleChildScrollView(
            child: SellerDailyProductionCard(
              board: production,
              onRetry: onRetry ?? () {},
            ),
          ),
        ),
      ),
    ));
  }

  testWidgets('کیسه امروز و تاریخ سرور با مبنای خمیر ثبت‌شده نمایش داده می‌شوند',
      (tester) async {
    await show(tester, board(12));
    expect(find.text('پخت روزانه'), findsOneWidget);
    expect(find.text('12 کیسه'), findsOneWidget);
    expect(find.text('۱۴۰۵/۰۸/۰۴'), findsOneWidget);
    expect(find.textContaining('خمیر ثبت‌شده امروز'), findsOneWidget);
    expect(find.text('2 کیسه'), findsNothing);
    expect(find.text('900 کیسه'), findsNothing);
  });

  testWidgets('روز بدون خمیر صفر دارد و خطای دریافت با صفر اشتباه نمی‌شود',
      (tester) async {
    await show(tester, board(0));
    expect(find.text('0 کیسه'), findsOneWidget);
    expect(find.text('دریافت دوباره'), findsNothing);

    var retried = false;
    await show(tester, null, onRetry: () => retried = true);
    expect(find.text('0 کیسه'), findsNothing);
    expect(find.text('اطلاعات پخت امروز دریافت نشد.'), findsOneWidget);
    await tester.tap(find.text('دریافت دوباره'));
    expect(retried, isTrue);
  });

  testWidgets('با پاسخ تازه تعداد کیسه و تاریخ هم‌زمان عوض می‌شوند',
      (tester) async {
    await show(tester, board(12));
    await show(tester, board(3, date: '۱۴۰۵/۰۸/۰۵'));
    expect(find.text('12 کیسه'), findsNothing);
    expect(find.text('۱۴۰۵/۰۸/۰۴'), findsNothing);
    expect(find.text('3 کیسه'), findsOneWidget);
    expect(find.text('۱۴۰۵/۰۸/۰۵'), findsOneWidget);
  });

  testWidgets('کارت در صفحه باریک گوشی سرریز ندارد', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await show(tester, board(1234));
    expect(tester.takeException(), isNull);
    expect(find.text('1234 کیسه'), findsOneWidget);
  });
}
