import 'package:bakery_app/models/quota_and_advance.dart';
import 'package:bakery_app/screens/admin/diesel_ledger_screen.dart';
import 'package:bakery_app/screens/admin/diesel_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  bool fail = false;
  final months = <String?>[];

  @override
  Future<({DieselQuota? quota, List<DieselDelivery> deliveries})> dieselQuota() async =>
      (quota: null, deliveries: <DieselDelivery>[]);

  @override
  Future<Map<String, dynamic>> dieselLedger({String? month}) async {
    months.add(month);
    if (fail) throw ApiException('ارتباط برقرار نشد');
    final prior = month == '1405/07';
    return {
      'month_label': prior ? 'مهر ۱۴۰۵' : 'آبان ۱۴۰۵',
      'from_label': '۵ ماه', 'to_label': '۴ ماه بعد',
      'previous_month': prior ? '1405/06' : '1405/07',
      'next_month': prior ? '1405/08' : null,
      'quota_recorded': true, 'quota_records_count': 1,
      'quota_opening_litres': 600, 'quota_litres': 400,
      'delivered_litres': 100, 'quota_closing_litres': 900,
      'tank_opening_litres': 650, 'consumed_litres': 50,
      'tank_closing_litres': 700, 'quota_opening_source': 'انتقال خودکار از گردش قبل',
      'note': 'مصرف و ماندهٔ باک تخمینی است.',
      'lines': [{'id': 10, 'type': 'delivery', 'label': 'تحویل سوخت',
        'date_label': '۶ آبان', 'litres': 100, 'detail': 'حواله ۱۲۳', 'user': 'علی'}],
    };
  }
}

void main() {
  Future<void> size(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
  }

  testWidgets('گردش حتی بدون سهمیه باز می‌شود و دو مانده و ریز تحویل را نشان می‌دهد', (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: DieselSection(api: _Api())))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('گردش ماهانه و انتقال مانده'));
    await tester.pumpAndSettle();
    expect(find.text('آبان ۱۴۰۵'), findsOneWidget);
    expect(find.text('600 لیتر'), findsOneWidget);
    expect(find.text('900 لیتر'), findsOneWidget);
    expect(find.text('650 لیتر'), findsOneWidget);
    expect(find.text('700 لیتر'), findsOneWidget);
    expect(find.textContaining('حواله ۱۲۳'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('ماه قبل و بازگشت به ماه جاری داده همان ماه را درخواست می‌کند', (tester) async {
    await size(tester);
    final api = _Api();
    await tester.pumpWidget(MaterialApp(home: DieselLedgerScreen(api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('ماه قبل'));
    await tester.pumpAndSettle();
    expect(api.months.last, '1405/07');
    expect(find.text('مهر ۱۴۰۵'), findsOneWidget);
    await tester.tap(find.byTooltip('ماه بعد'));
    await tester.pumpAndSettle();
    expect(api.months.last, '1405/08');
    expect(find.text('آبان ۱۴۰۵'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('خطای ارتباط با تلاش دوباره به گزارش تبدیل می‌شود', (tester) async {
    await size(tester);
    final api = _Api()..fail = true;
    await tester.pumpWidget(MaterialApp(home: DieselLedgerScreen(api: api)));
    await tester.pumpAndSettle();
    expect(find.text('ارتباط برقرار نشد'), findsOneWidget);
    api.fail = false;
    await tester.tap(find.text('تلاش دوباره'));
    await tester.pumpAndSettle();
    expect(find.text('آبان ۱۴۰۵'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
