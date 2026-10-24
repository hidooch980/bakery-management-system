import 'dart:async';

import 'package:bakery_app/models/bank_account.dart';
import 'package:bakery_app/models/payroll.dart';
import 'package:bakery_app/screens/admin/payroll_section.dart';
import 'package:bakery_app/screens/admin/salary_settlement_review.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/utils/formatters.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  double advance = 200, bread = 100;
  bool duplicate = false, incomplete = false;
  int reads = 0, writes = 0;
  Completer<Map<String, dynamic>>? pending;

  @override
  Future<List<Employee>> payrollEmployees() async => const [
        Employee(
            id: 1,
            name: 'حسن',
            monthlySalary: 1000,
            monthlySalaryFormatted: '۱۰۰۰ تومان',
            advanceOutstanding: 200,
            advanceOutstandingFormatted: '۲۰۰ تومان',
            breadOutstanding: 100,
            breadOutstandingFormatted: '۱۰۰ تومان')
      ];
  @override
  Future<List<Payslip>> payslips({String? status}) async => [];
  @override
  Future<BankBalances> bankBalances() async =>
      const BankBalances(accounts: [], total: 0, totalFormatted: '۰ تومان');
  @override
  Future<Map<String, dynamic>> staffAccount(int id) async {
    reads++;
    if (pending != null) return pending!.future;
    return {
      'currency_label': 'تومان',
      'summary': incomplete
          ? {}
          : {'advance_outstanding': advance, 'bread_outstanding': bread},
      'payslips': [
        if (duplicate) {'period_start_jalali': _period}
      ],
    };
  }

  @override
  Future<Payslip> recordSalary(
      {required int userId,
      required String periodStart,
      required double baseAmount,
      double bonus = 0,
      double deduction = 0,
      String? paidOn,
      int? bankAccountId,
      String? note,
      String? attemptKey,
      bool recoverAdvances = true,
      bool recoverBread = true}) async {
    writes++;
    return const Payslip(
        id: 9,
        userId: 1,
        userName: 'حسن',
        periodLabel: '',
        periodStartJalali: '',
        netAmount: 700,
        netAmountFormatted: '۷۰۰ تومان',
        isPaid: true);
  }
}

String get _period {
  final parts = latinDigits(JalaliFormat.date(DateTime.now())).split('/');
  return '${parts[0]}/${parts[1]}/01';
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  Future<void> open(WidgetTester tester, _Api api) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: SingleChildScrollView(child: PayrollSection(api: api)))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حسن'));
    await tester.pumpAndSettle();
  }

  Future<void> preview(WidgetTester tester) async {
    await tester.ensureVisible(find.text('پیش‌نمایش تسویه'));
    await tester.tap(find.text('پیش‌نمایش تسویه'));
    await tester.pumpAndSettle();
  }

  test('تسویه بدهی بیش از حقوق را به ماه بعد می‌برد و خالص منفی نمی‌سازد', () {
    const s = SalarySettlement(
        base: 1000,
        bonus: 100,
        deduction: 50,
        advanceOwed: 900,
        breadOwed: 400,
        recoverAdvances: true,
        recoverBread: true);
    expect(s.advance, 900);
    expect(s.bread, 150);
    expect(s.net, 0);
    expect(s.breadRemaining, 250);
    const skipped = SalarySettlement(
        base: 1000,
        bonus: 0,
        deduction: 0,
        advanceOwed: 200,
        breadOwed: 100,
        recoverAdvances: false,
        recoverBread: true);
    expect(skipped.net, 900);
    expect(skipped.advanceRemaining, 200);
  });

  testWidgets('پیش‌نمایش بدون تأیید هیچ پرداختی نمی‌نویسد', (tester) async {
    final api = _Api();
    await open(tester, api);
    await preview(tester);
    expect(find.textContaining('خالص قابل پرداخت:'), findsOneWidget);
    expect(api.writes, 0);
    await tester.tap(find.text('بازگشت بدون ثبت'));
    await tester.pumpAndSettle();
    expect(api.writes, 0);
  });

  testWidgets('تأیید نهایی پرونده را دوباره می‌خواند و یک فیش می‌نویسد',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    await preview(tester);
    await tester.tap(find.text('تأیید و ثبت تسویه'));
    await tester.pumpAndSettle();
    expect(api.reads, 2);
    expect(api.writes, 1);
  });

  testWidgets('تغییر بدهی پس از پیش‌نمایش ثبت را متوقف می‌کند', (tester) async {
    final api = _Api();
    await open(tester, api);
    await preview(tester);
    api.advance = 500;
    await tester.tap(find.text('تأیید و ثبت تسویه'));
    await tester.pumpAndSettle();
    expect(api.writes, 0);
    expect(
        find.textContaining('پرونده یا دوره حقوق تغییر کرده'), findsOneWidget);
  });

  testWidgets('فیش ثبت‌شده روی دستگاه دیگر پیش از پرداخت دوباره شناسایی می‌شود',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    await preview(tester);
    api.duplicate = true;
    await tester.tap(find.text('تأیید و ثبت تسویه'));
    await tester.pumpAndSettle();
    expect(api.writes, 0);
    expect(find.textContaining('قبلاً فیش ثبت شده'), findsOneWidget);
  });

  testWidgets('پاسخ ناقص پرونده به بدهی صفر تعبیر نمی‌شود', (tester) async {
    final api = _Api()..incomplete = true;
    await open(tester, api);
    await preview(tester);
    expect(api.writes, 0);
    expect(find.byType(SalarySettlementReview), findsNothing);
    expect(find.textContaining('مانده بدهی معتبر دریافت نشد'), findsOneWidget);
  });

  testWidgets('انتظار پاسخ اجازه باز کردن هم‌زمان تسویه را نمی‌دهد',
      (tester) async {
    final api = _Api()..pending = Completer<Map<String, dynamic>>();
    await open(tester, api);
    await preview(tester);
    await tester.tap(find.text('حسن'));
    await tester.pumpAndSettle();
    expect(find.text('پیش‌نمایش تسویه'), findsNothing);
    expect(api.reads, 1);
    api.pending!.complete({
      'currency_label': 'تومان',
      'summary': {'advance_outstanding': 200, 'bread_outstanding': 100},
      'payslips': []
    });
    await tester.pumpAndSettle();
    expect(find.byType(SalarySettlementReview), findsOneWidget);
  });

  testWidgets('پیش‌نمایش در صفحه باریک و فونت بزرگ قابل تأیید است',
      (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: const TextScaler.linear(2)),
            child: child!),
        home: Scaffold(
            body: Builder(
                builder: (context) => TextButton(
                    onPressed: () => showDialog<bool>(
                        context: context,
                        builder: (_) => const SalarySettlementReview(
                            personName: 'کارمند نمونه با نام طولانی',
                            period: '1405/08/01',
                            accountTitle: 'صندوق',
                            currency: 'تومان',
                            settlement: SalarySettlement(
                                base: 1000,
                                bonus: 0,
                                deduction: 0,
                                advanceOwed: 200,
                                breadOwed: 100,
                                recoverAdvances: true,
                                recoverBread: true))),
                    child: const Text('باز کردن'))))));
    await tester.tap(find.text('باز کردن'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('تأیید و ثبت تسویه'), findsOneWidget);
  });
}
