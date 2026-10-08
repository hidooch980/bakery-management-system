import 'package:bakery_app/screens/admin/admin_home_screen.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/models/payroll.dart';
import 'package:bakery_app/models/bank_account.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/screens/admin/payroll_section.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  @override
  Future<List<Employee>> payrollEmployees() async => [
        const Employee(
            id: 7,
            name: 'عبدالناصر ملازهی',
            monthlySalary: 9000000,
            monthlySalaryFormatted: '۹۰٬۰۰۰٬۰۰۰ ریال',
            hasRequested: true,
            advanceOutstanding: 1000000,
            advanceOutstandingFormatted: '۱۰٬۰۰۰٬۰۰۰ ریال',
            breadOutstanding: 300000,
            breadOutstandingFormatted: '۳٬۰۰۰٬۰۰۰ ریال')
      ];
  @override
  Future<List<Payslip>> payslips({String? status}) async => [];
  @override
  Future<BankBalances> bankBalances() async =>
      const BankBalances(accounts: [], totalFormatted: '۰', total: 0);
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  testWidgets('فهرست حقوق با نام و بدهی در صفحه باریک و فونت بزرگ خطا ندارد',
      (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: MediaQuery(
            data: const MediaQueryData(
                size: Size(320, 640), textScaler: TextScaler.linear(2)),
            child: Directionality(
                textDirection: TextDirection.rtl,
                child: Scaffold(
                    body:
                        ListView(children: [PayrollSection(api: _Api())]))))));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('عبدالناصر ملازهی'), findsOneWidget);
  });
  testWidgets('نام کارمند با مبلغ بلند و فونت بزرگ روی گوشی خوانا می‌ماند',
      (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: MediaQuery(
          data: const MediaQueryData(
              size: Size(320, 640), textScaler: TextScaler.linear(2)),
          child: Directionality(
              textDirection: TextDirection.rtl,
              child: Scaffold(
                  body: ListView(children: [
                AdminRow(
                    label: 'عبدالناصر ملازهی',
                    value: '۹۰٬۰۰۰٬۰۰۰ ریال',
                    icon: Icons.payments),
              ]))),
        )));
    expect(tester.takeException(), isNull);
    final text = tester.getRect(find.text('عبدالناصر ملازهی'));
    expect(text.width, greaterThan(50));
  });
}
