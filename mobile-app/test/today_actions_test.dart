import 'package:bakery_app/models/payroll.dart';
import 'package:bakery_app/models/quota_and_advance.dart';
import 'package:bakery_app/models/staff_adjustment.dart';
import 'package:bakery_app/models/today_answer.dart';
import 'package:bakery_app/screens/admin/today_actions.dart';
import 'package:bakery_app/screens/admin/admin_today_tab.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/widgets/role_home_scaffold.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  bool salaryFails = false;
  String? salaryStatus;
  int loanReads = 0;
  @override
  Future<TodayAnswer> today() async =>
      throw Exception('گزارش وضعیت در دسترس نیست');
  @override
  Future<List<Payslip>> payslips({String? status}) async {
    salaryStatus = status;
    if (salaryFails) throw Exception('قطع ارتباط');
    return [
      const Payslip(
          id: 1,
          userId: 3,
          userName: 'علی',
          periodLabel: 'مهر',
          periodStartJalali: '۱۴۰۵/۰۷/۰۱',
          netAmount: 100,
          netAmountFormatted: '۱۰۰ تومان',
          isPaid: false)
    ];
  }

  @override
  Future<List<AdvanceRequest>> pendingAdvanceRequests() async => [];
  @override
  Future<List<SalaryRequest>> pendingSalaryRequests() async => [
        const SalaryRequest(
            id: 3,
            periodLabel: 'مهر',
            status: 'pending',
            statusLabel: 'منتظر',
            requestedOn: '۱۴۰۵/۰۷/۰۱',
            daysWaiting: 1),
      ];
  @override
  Future<List<Map<String, dynamic>>> bankLoans() async {
    loanReads++;
    return [];
  }
}

TodayNeed need(String key) => TodayNeed(
    key: key,
    severity: 'critical',
    title: 'نیازمند پیگیری',
    detail: 'جزئیات سرور',
    suggestion: 'بررسی کنید');

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  testWidgets('کارهای مدیر در صفحه باریک با فونت بزرگ قابل نمایش است',
      (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        home: MediaQuery(
            data: const MediaQueryData(textScaler: TextScaler.linear(2)),
            child: Scaffold(
                body: ListView(children: [
              TodayActions(
                  api: _Api(),
                  needs: [need('loan-due-1')],
                  openNeed: (_) {},
                  onReturn: () {})
            ])))));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
  testWidgets('خطای گزارش وضعیت، کارهای مالی مدیر را پنهان نمی‌کند',
      (tester) async {
    await tester.pumpWidget(
        MaterialApp(home: Scaffold(body: AdminTodayTab(api: _Api()))));
    await tester.pumpAndSettle();
    expect(find.text('کارهای امروز مدیر'), findsOneWidget);
    expect(find.text('1 مورد در فهرست دریافتی نیازمند بررسی است'),
        findsNWidgets(2));
    expect(find.textContaining('گزارش وضعیت در دسترس نیست'), findsOneWidget);
  });
  Future<void> show(WidgetTester tester, _Api api,
      {List<TodayNeed> needs = const [], void Function(String)? goTo}) async {
    await tester.pumpWidget(MaterialApp(
        home: HomeTabs(
            goTo: goTo ?? (_) {},
            child: Scaffold(
                body: ListView(children: [
              TodayActions(
                  api: api, needs: needs, openNeed: (_) {}, onReturn: () {}),
            ])))));
    await tester.pumpAndSettle();
  }

  testWidgets('فیش باز و درخواست منتظر با خواندن جداگانه نمایش داده می‌شوند',
      (tester) async {
    final api = _Api();
    await show(tester, api);
    expect(api.salaryStatus, 'unpaid');
    expect(find.text('1 مورد در فهرست دریافتی نیازمند بررسی است'),
        findsNWidgets(2));
    expect(api.loanReads, 0);
  });
  testWidgets(
      'خطای فیش‌ها درخواست‌ها را پنهان نمی‌کند و تلاش دوباره نتیجه را می‌خواند',
      (tester) async {
    final api = _Api()..salaryFails = true;
    await show(tester, api);
    expect(find.text('فیش‌های پرداخت‌نشده دریافت نشد'), findsOneWidget);
    expect(
        find.text('1 مورد در فهرست دریافتی نیازمند بررسی است'), findsOneWidget);
    api.salaryFails = false;
    await tester.tap(find.byTooltip('تلاش دوباره فیش‌های پرداخت‌نشده'));
    await tester.pumpAndSettle();
    expect(find.text('فیش‌های پرداخت‌نشده دریافت نشد'), findsNothing);
    expect(find.text('1 مورد در فهرست دریافتی نیازمند بررسی است'),
        findsNWidgets(2));
  });
  testWidgets('پیگیری قسط صفحه واقعی اقساط را باز می‌کند', (tester) async {
    final api = _Api();
    await show(tester, api, needs: [need('loan-due-1')]);
    await tester.tap(find.text('پیگیری'));
    await tester.pumpAndSettle();
    expect(find.text('اقساط وام بانکی'), findsOneWidget);
    expect(api.loanReads, 1);
  });
  testWidgets('موجودی کم به انبار می‌رود و هشدار نامرتبط در گروه تکرار نمی‌شود',
      (tester) async {
    String? destination;
    await show(tester, _Api(),
        needs: [need('low-stock-flour'), need('negative-bank-1')],
        goTo: (value) => destination = value);
    expect(find.text('جزئیات سرور'), findsOneWidget);
    await tester.tap(find.text('پیگیری'));
    expect(destination, 'warehouse');
    expect(isTodayAction(need('monthly-wages-1405-07')), isTrue);
    expect(isTodayAction(need('unknown-check')), isFalse);
  });
}
