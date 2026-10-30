import 'package:bakery_app/models/flour_sale.dart';
import 'package:bakery_app/screens/admin/admin_warehouse_tab.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api({this.oldServer = false})
      : super(ApiClient(baseUrl: 'https://server.test/api/v1'));

  final bool oldServer;

  Map<String, dynamic> period(String label, num bags, num daily, int days) => {
        'label': label,
        'allocated_bags': bags,
        'starts_on_display': '۱۴۰۵/۰۸/۰۵',
        'ends_on_display': '۱۴۰۵/۰۸/۱۴',
        'is_current': label == 'دوره اول',
        if (!oldServer) 'daily_pace_bags': daily,
        if (!oldServer) 'working_days': days,
      };

  @override
  Future<List<Map<String, dynamic>>> inventory() async => [];

  @override
  Future<({List<FlourSale> sales, int count, double totalBags, String totalFormatted})>
      todayFlourSales() async =>
          (sales: <FlourSale>[], count: 0, totalBags: 0.0, totalFormatted: '۰ تومان');

  @override
  Future<Map<String, dynamic>?> currentFlourAllocation() async => {
        'month_label': 'آبان ۱۴۰۵',
        'total_bags': 347,
        if (!oldServer) 'daily_period': {
          'date': '2026-10-30',
          'date_label': '۱۴۰۵/۰۸/۰۸',
          'period_label': 'دوره اول',
          'allocated_bags': 11.57,
          'allocated_kg': 462.8,
          'used_bags': 2,
          'used_kg': 80,
          'remaining_bags': 9.57,
          'chane_count': 140,
          'card_bread_count': 100,
        },
        'periods': [period('دوره اول', 115.7, 11.57, 10)],
        'whole_period': period('کل ماه', 347, 12.85, 27),
      };
}

void main() {
  Future<void> show(WidgetTester tester, {bool oldServer = false}) async {
    await tester.binding.setSurfaceSize(const Size(390, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: AdminWarehouseTab(api: _Api(oldServer: oldServer))),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('سهمیه آرد'));
    await tester.tap(find.text('سهمیه آرد'));
    await tester.pumpAndSettle();
  }

  testWidgets('باز کردن سهمیه در انبار سهم روزانه دوره و جمع ماه را نشان می‌دهد',
      (tester) async {
    await show(tester);
    expect(find.text('سهم روزانه آرد'), findsNWidgets(2));
    expect(find.text('11.57 کیسه در روز کاری'), findsOneWidget);
    expect(find.text('12.85 کیسه در روز کاری'), findsOneWidget);
    expect(find.textContaining('10 روز کاری'), findsOneWidget);
    expect(find.textContaining('27 روز کاری'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('سرور قدیمی سهم روزانه را پنهان یا صفر فرض نمی‌کند', (tester) async {
    await show(tester, oldServer: true);
    expect(find.text('سهم روزانه آرد'), findsNWidgets(2));
    expect(find.textContaining('اطلاعات سهم روزانه از سرور دریافت نشد'), findsNWidgets(2));
    expect(find.text('0 کیسه در روز کاری'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('دوره روزانه کارت مستقل با مصرف و چانه و کارتخوان همان روز دارد',
      (tester) async {
    await show(tester);
    expect(find.text('دوره روزانه'), findsOneWidget);
    expect(find.text('۱۴۰۵/۰۸/۰۸ • دوره اول'), findsOneWidget);
    expect(find.text('11.57 کیسه • 462.80 کیلوگرم'), findsOneWidget);
    expect(find.text('2 کیسه • 80 کیلوگرم'), findsOneWidget);
    expect(find.text('140 چانه'), findsOneWidget);
    expect(find.text('100 نان'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
