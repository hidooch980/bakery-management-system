import 'package:bakery_app/screens/admin/partner_flour_corrections_screen.dart';
import 'package:bakery_app/screens/admin/seller_work_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/manager_corrections_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends ManagerCorrectionsApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  Map<String, dynamic>? saved;
  int calls = 0;
  bool fail = false;
  @override
  Future<void> correctWork(int seller, String kind, int? id,
      Map<String, dynamic> data, String attemptKey) async {
    calls++;
    if (fail) throw ApiException('این رکورد تغییر کرده است', statusCode: 409);
    saved = data;
  }

  @override
  Future<void> correctPartnerFlour(int id, Map<String, dynamic> data) =>
      correctWork(0, '', id, data, '');
}

const _attendance = <String, dynamic>{
  'id': 1,
  'date': '2026-10-20',
  'in_time': '07:00',
  'out_time': '14:00',
  'version': 'نسخه حضور'
};
const _sale = <String, dynamic>{
  'id': 3,
  'bread_count': 100,
  'amount': 1000,
  'amount_formatted': '۱۰۰۰ تومان',
  'takes_money': true,
  'version': 'نسخه فروش'
};
const _flour = <String, dynamic>{
  'id': 7,
  'customer_id': 4,
  'partner_name': 'همکار نمونه',
  'bags': 5,
  'returned_bags': 2,
  'direction': 'borrowed',
  'date': '2026-10-20',
  'date_label': '۱۴۰۵/۰۷/۲۸',
  'version': 'نسخه آرد'
};

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  Future<void> open(WidgetTester tester, Widget dialog) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: Builder(
                builder: (context) => TextButton(
                    onPressed: () => showDialog<bool>(
                        context: context, builder: (_) => dialog),
                    child: const Text('باز کردن'))))));
    await tester.tap(find.text('باز کردن'));
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester, String label, String value) =>
      tester.enterText(find.widgetWithText(TextFormField, label), value);
  Future<void> review(WidgetTester tester) async {
    await tester.ensureVisible(find.text('بررسی اصلاح'));
    await tester.tap(find.text('بررسی اصلاح'));
    await tester.pumpAndSettle();
  }

  SellerWorkEditDialog sale(_Api api) => SellerWorkEditDialog(
      api: api, sellerId: 11, kind: 'sales', row: _sale, currency: 'تومان');
  PartnerFlourEditDialog flour(_Api api) =>
      PartnerFlourEditDialog(api: api, row: _flour, partners: const [
        {'id': 4, 'name': 'همکار نمونه'}
      ]);

  testWidgets('اصلاح فروش بدون دلیل ارسال نمی‌شود', (tester) async {
    final api = _Api();
    await open(tester, sale(api));
    await review(tester);
    expect(api.calls, 0);
    expect(find.text('دلیل اصلاح را بنویسید'), findsOneWidget);
  });

  testWidgets('پیش‌نمایش و بازگشت از اصلاح فروش هیچ نوشتنی ندارد',
      (tester) async {
    final api = _Api();
    await open(tester, sale(api));
    await fill(tester, 'دلیل اصلاح (الزامی)', 'تعداد اشتباه بود');
    await review(tester);
    expect(api.calls, 0);
    await tester.tap(find.text('بازگشت'));
    await tester.pumpAndSettle();
    expect(api.calls, 0);
    expect(find.byType(SellerWorkEditDialog), findsOneWidget);
  });

  testWidgets('فروش پس از تأیید نسخه و عدد فارسی درست را می‌فرستد',
      (tester) async {
    final api = _Api();
    await open(tester, sale(api));
    await fill(tester, 'تعداد نان', '۸۰');
    await fill(tester, 'مبلغ تومان', '۸۰۰');
    await fill(tester, 'دلیل اصلاح (الزامی)', 'تعداد اشتباه بود');
    await review(tester);
    await tester.tap(find.text('ثبت اصلاح'));
    await tester.pumpAndSettle();
    expect(api.saved!['bread_count'], 80);
    expect(api.saved!['amount'], 800);
    expect(api.saved!['version'], 'نسخه فروش');
    expect(find.byType(SellerWorkEditDialog), findsNothing);
  });

  testWidgets('ساعت فارسی حضور با دلیل و نسخه ثبت می‌شود', (tester) async {
    final api = _Api();
    await open(
        tester,
        SellerWorkEditDialog(
            api: api,
            sellerId: 11,
            kind: 'attendance',
            row: _attendance,
            currency: ''));
    await fill(tester, 'ساعت ورود', '۰۸:۳۰');
    await fill(tester, 'دلیل اصلاح (الزامی)', 'ساعت ورود اشتباه بود');
    await review(tester);
    await tester.tap(find.text('ثبت اصلاح'));
    await tester.pumpAndSettle();
    expect(api.saved!['in_time'], '08:30');
    expect(api.saved!['version'], 'نسخه حضور');
  });

  testWidgets('تعارض سرور فرم و دلیل را برای بررسی نگه می‌دارد',
      (tester) async {
    final api = _Api()..fail = true;
    await open(tester, sale(api));
    await fill(tester, 'دلیل اصلاح (الزامی)', 'تعداد اشتباه بود');
    await review(tester);
    await tester.tap(find.text('ثبت اصلاح'));
    await tester.pumpAndSettle();
    expect(find.text('این رکورد تغییر کرده است'), findsOneWidget);
    expect(find.byType(SellerWorkEditDialog), findsOneWidget);
    expect(api.saved, isNull);
  });

  testWidgets('آرد کمتر از برگشت قبلی به سرور فرستاده نمی‌شود', (tester) async {
    final api = _Api();
    await open(tester, flour(api));
    await fill(tester, 'تعداد کیسه', '۱');
    await fill(tester, 'دلیل اصلاح (الزامی)', 'کیسه اشتباه بود');
    await review(tester);
    expect(api.calls, 0);
    expect(find.text('مقدار از برگشت ثبت‌شده کمتر نمی‌شود'), findsOneWidget);
  });

  testWidgets('اصلاح آرد همکار مقدار و جهت و نسخه اصلی را ارسال می‌کند',
      (tester) async {
    final api = _Api();
    await open(tester, flour(api));
    await fill(tester, 'تعداد کیسه', '۳');
    await fill(tester, 'دلیل اصلاح (الزامی)', 'کیسه اشتباه بود');
    await review(tester);
    expect(api.calls, 0);
    await tester.tap(find.text('ثبت اصلاح'));
    await tester.pumpAndSettle();
    expect(api.saved!['bags'], 3);
    expect(api.saved!['direction'], 'borrowed');
    expect(api.saved!['customer_id'], 4);
    expect(api.saved!['version'], 'نسخه آرد');
  });
}
