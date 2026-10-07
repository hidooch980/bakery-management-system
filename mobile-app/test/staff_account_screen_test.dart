import 'package:bakery_app/models/payroll.dart';
import 'package:bakery_app/screens/admin/staff_account_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  int reads = 0;
  Map<String, dynamic>? edited;
  @override
  Future<Map<String, dynamic>> staffAccount(int id) async {
    reads++;
    return {
      'summary': {
        'unpaid': '۷۰۰ تومان',
        'advances': '۳۰۰ تومان',
        'bread': '۰ تومان'
      },
      'payslips': [
        {
          'id': 1,
          'period_label': 'مهر',
          'base_amount': 1000,
          'bonus': 0,
          'deduction': 0,
          'advance_deduction_formatted': '۳۰۰ تومان',
          'bread_deduction_formatted': '۰ تومان',
          'net_amount_formatted': '۷۰۰ تومان',
          'is_paid': false,
          'advance_links': [
            {'id': 2, 'amount': '۳۰۰ تومان'}
          ],
          'bread_links': [],
          'recover_advances': true,
          'recover_bread': true,
        }
      ],
      'advances': [
        {
          'id': 2,
          'date': '۱۴۰۵/۰۷/۰۱',
          'amount': 300,
          'amount_formatted': '۳۰۰ تومان',
          'outstanding_formatted': '۰ تومان',
          'salary_ids': [1],
          'editable': false
        }
      ],
      'bread': [],
      'adjustments': [],
    };
  }

  @override
  Future<void> updatePayslip(int id, Map<String, dynamic> data) async {
    edited = data;
  }
}

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  Future<void> open(WidgetTester tester, _Api api) async {
    await tester.pumpWidget(MaterialApp(
        home: StaffAccountScreen(
            api: api,
            person: const Employee(
                id: 11,
                name: 'علی',
                monthlySalary: 1000,
                monthlySalaryFormatted: '۱۰۰۰ تومان'))));
    await tester.pumpAndSettle();
  }

  testWidgets('فیش به مساعده اصلی و مساعده به فیش مرتبط باز می‌شود',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    expect(find.text('حقوق پرداخت‌نشده: ۷۰۰ تومان'), findsOneWidget);
    await tester.tap(find.text('مهر'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('مساعده #2'));
    await tester.pumpAndSettle();
    expect(find.text('فیش مرتبط #1'), findsOneWidget);
    expect(find.text('اصلاح رکورد اصلی'), findsNothing);
    await tester.tap(find.text('فیش مرتبط #1'));
    await tester.pumpAndSettle();
    expect(find.text('فیش حقوق #1'), findsOneWidget);
  });

  testWidgets('اصلاح فیش انتخاب کسر بدهی را ذخیره و پرونده را تازه می‌کند',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    await tester.tap(find.text('مهر'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('اصلاح رکورد اصلی'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('کسر مساعده'));
    await tester.pumpAndSettle();
    final reason = find.widgetWithText(TextFormField, 'دلیل اصلاح (الزامی)');
    await tester.ensureVisible(reason);
    await tester.enterText(reason, 'انتقال بدهی به دوره بعد');
    await tester.tap(find.text('تأیید اصلاح'));
    await tester.pumpAndSettle();
    expect(api.edited!['recover_advances'], false);
    expect(api.edited!['base_amount'], 1000);
    expect(api.edited!['note'], contains('انتقال بدهی'));
    expect(api.reads, 2);
    expect(tester.takeException(), isNull);
  });
}
