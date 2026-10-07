import 'package:bakery_app/models/bakery.dart';
import 'package:bakery_app/models/customer.dart';
import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/screens/seller/seller_home_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  List<SalePaymentLine>? saved;

  @override
  Future<List<Customer>> customers(
          {bool partnersOnly = false, bool buyersOnly = false}) async =>
      [];

  @override
  Future<List<StaffName>> saleStaff() async => const [
        StaffName(id: 11, name: 'علی'),
        StaffName(id: 12, name: 'رضا'),
      ];

  @override
  Future<bool> recordSplitSale(
      {required int chaneEntryId,
      required List<SalePaymentLine> payments,
      String? note}) async {
    saved = payments;
    return false;
  }
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  Future<void> open(WidgetTester tester, _Api api) async {
    await tester.binding.setSurfaceSize(const Size(600, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        home: Builder(
            builder: (context) => Scaffold(
                body: TextButton(
                    onPressed: () => showModalBottomSheet<void>(
                        context: context,
                        isScrollControlled: true,
                        builder: (_) => RecordSaleSheet(
                            api: api,
                            bakery:
                                const Bakery(name: 'نانوایی', breadPrice: 5000),
                            chane: const ChaneEntry(
                                id: 1,
                                doughEntryId: 1,
                                chaneCount: 5,
                                normalWeightKg: 1,
                                naninoWeightKg: 0,
                                sprayFlourKg: 0,
                                status: 'pending'))),
                    child: const Text('باز کن'))))));
    await tester.tap(find.text('باز کن'));
    await tester.pumpAndSettle();
  }

  Future<void> tap(WidgetTester tester, Finder target) async {
    await tester.ensureVisible(target);
    await tester.tap(target);
    await tester.pumpAndSettle();
  }

  Future<void> fill(WidgetTester tester, String secondCount,
      {bool duplicate = false}) async {
    final first = find.descendant(
        of: find.byKey(const ValueKey(PaymentType.home)),
        matching: find.byType(TextFormField));
    await tester.ensureVisible(first);
    await tester.enterText(first, '۳');
    await tester.pumpAndSettle();
    await tap(tester, find.byType(DropdownButtonFormField<int>).first);
    await tap(tester, find.text('علی').last);
    await tap(tester, find.text('افزودن کارمند برای نان منزل'));
    final fields = find.byType(TextFormField);
    final extra = fields.at(fields.evaluate().length - 2);
    await tester.ensureVisible(extra);
    await tester.enterText(extra, secondCount);
    await tester.pumpAndSettle();
    await tap(tester, find.byType(DropdownButtonFormField<int>).last);
    await tap(tester, find.text(duplicate ? 'علی' : 'رضا').last);
  }

  testWidgets('نان دو کارمند با تعداد فارسی و شناسه جدا ثبت می‌شود',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    await fill(tester, '۲');
    await tap(tester, find.text('ثبت فروش').last);
    expect(api.saved!.map((r) => r.toJson()).toList(), [
      {'payment_type': 'home', 'bread_count': 3, 'consumed_by_user_id': 11},
      {'payment_type': 'home', 'bread_count': 2, 'consumed_by_user_id': 12},
    ]);
    expect(tester.takeException(), isNull);
  });

  testWidgets('مجموع نان کارکنان از تعداد چانه بیشتر نمی‌شود', (tester) async {
    final api = _Api();
    await open(tester, api);
    await fill(tester, '۳');
    await tap(tester, find.text('ثبت فروش').last);
    expect(api.saved, isNull);
    expect(find.text('مجموع تعداد نان از 5 عدد این چانه بیشتر است.'),
        findsOneWidget);
  });

  testWidgets('یک کارمند در دو ردیف تکرار نمی‌شود', (tester) async {
    final api = _Api();
    await open(tester, api);
    await fill(tester, '۲', duplicate: true);
    await tap(tester, find.text('ثبت فروش').last);
    expect(api.saved, isNull);
    expect(
        find.text('هر کارمند را فقط در یک ردیف انتخاب کنید.'), findsOneWidget);
  });
}
