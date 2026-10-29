import 'package:bakery_app/screens/admin/seller_daily_report_screen.dart';
import 'package:bakery_app/screens/admin/seller_debts_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  bool fail = false;

  @override
  Future<List<Map<String, dynamic>>> sellerAccounts() async => [
        {'id': 9, 'name': 'علی', 'still_owed': 60000, 'still_owed_formatted': '۶۰٬۰۰۰ تومان'},
      ];

  @override
  Future<Map<String, dynamic>> sellerDailyReport(int sellerId,
      {String? from, String? until}) async {
    if (fail) throw ApiException('ارتباط برقرار نشد');
    return {
      'from': '2026-10-29', 'to': '2026-10-29',
      'from_label': '۷ آبان', 'to_label': '۷ آبان',
      'summary': {'still_owed_formatted': '۶۰٬۰۰۰ تومان', 'on_account_formatted': '۴۰٬۰۰۰ تومان'},
      'card_source': 'فروش کارتخوان ثبت‌شده داخل اپ',
      'scope_note': 'سهمیه و چانه مربوط به کل نانوایی‌اند',
      'quota_note': 'اختلاف سهمیه، بدهی مالی فروشنده نیست',
      'days': [
        {
          'date_label': '۷ آبان ۱۴۰۵', 'quota_bags': 10, 'quota_card_target': 640,
          'bakery_chane_count': 700, 'bakery_card_bread_count': 600,
          'seller_card_bread_count': 400, 'seller_card_amount_formatted': '۲٬۰۰۰٬۰۰۰ تومان',
          'home_bread_count': 10, 'credit_bread_count': 20, 'waste_bread_count': 5,
          'handed_over_formatted': '۴۰٬۰۰۰ تومان', 'has_sales': true, 'lines': [],
        },
      ],
      'open_days': [], 'handovers': [],
    };
  }
}

void main() {
  Future<void> size(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(600, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
  }

  testWidgets('ریز بدهی از فهرست فروشنده باز می‌شود و زمینهٔ سهمیه جداست', (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: SellerDebtsSection(api: _Api())))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ریز بدهی، سهم آرد و کارتخوان'));
    await tester.pumpAndSettle();
    expect(find.text('ماندهٔ بدهی فروشنده'), findsOneWidget);
    expect(find.text('فروش کارتخوان ثبت‌شده داخل اپ'), findsOneWidget);
    await tester.ensureVisible(find.text('۷ آبان ۱۴۰۵'));
    await tester.tap(find.text('۷ آبان ۱۴۰۵'));
    await tester.pumpAndSettle();
    expect(find.text('سهم آرد نانوایی: 10 کیسه'), findsOneWidget);
    expect(find.text('640 نان'), findsOneWidget);
    expect(find.text('700'), findsOneWidget);
    expect(find.text('400 نان — ۲٬۰۰۰٬۰۰۰ تومان'), findsOneWidget);
    expect(find.text('۶۰٬۰۰۰ تومان'), findsOneWidget);
    await tester.ensureVisible(find.byTooltip('بازه گزارش'));
    await tester.tap(find.byTooltip('بازه گزارش'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('۳۰ روز اخیر'));
    await tester.pumpAndSettle();
    expect(find.text('ماندهٔ بدهی فروشنده'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('خطای گزارش با تلاش دوباره قابل بازیابی است', (tester) async {
    await size(tester);
    final api = _Api()..fail = true;
    await tester.pumpWidget(MaterialApp(home: SellerDailyReportScreen(api: api, sellerId: 9, sellerName: 'علی')));
    await tester.pumpAndSettle();
    expect(find.text('ارتباط برقرار نشد'), findsOneWidget);
    api.fail = false;
    await tester.tap(find.text('تلاش دوباره'));
    await tester.pumpAndSettle();
    expect(find.text('ماندهٔ بدهی فروشنده'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
