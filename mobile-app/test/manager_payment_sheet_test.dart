import 'package:bakery_app/models/bank_account.dart';
import 'package:bakery_app/screens/admin/financial_payment_sheet.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  final keys = <String>[];
  Map<String, dynamic>? payment;
  bool fail = false;
  @override
  Future<void> recordStaffAdvance(
      Map<String, dynamic> data, String attemptKey) async {
    keys.add(attemptKey);
    payment = data;
    if (fail) {
      fail = false;
      throw Exception('پاسخ پرداخت نرسید');
    }
  }

  @override
  Future<void> payBankLoan(
          int id, Map<String, dynamic> data, String attemptKey) =>
      recordStaffAdvance(data, attemptKey);
}

const _accounts = [
  BankAccount(
      id: 4,
      title: 'حساب سفید',
      balance: 2000,
      balanceFormatted: '۲۰۰۰ تومان',
      isDefault: true)
];
void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  Future<void> open(WidgetTester tester, _Api api, {bool loan = false}) async {
    await tester.pumpWidget(MaterialApp(
        home: Scaffold(
            body: Builder(
                builder: (context) => TextButton(
                    onPressed: () => showModalBottomSheet<bool>(
                        context: context,
                        isScrollControlled: true,
                        builder: (_) => FinancialPaymentSheet(
                            api: api,
                            title: 'پرداخت',
                            accounts: _accounts,
                            employeeId: loan ? null : 11,
                            loanId: loan ? 9 : null,
                            maximumAmount: loan ? 300 : null)),
                    child: const Text('باز کردن'))))));
    await tester.tap(find.text('باز کردن'));
    await tester.pumpAndSettle();
  }

  testWidgets('ثبت علی‌الحساب مدیر حساب و عدد فارسی را ارسال می‌کند',
      (tester) async {
    final api = _Api();
    await open(tester, api);
    await tester.enterText(find.byType(TextFormField).first, '۲۰۰');
    await tester.tap(find.text('ثبت پرداخت انجام‌شده'));
    await tester.pumpAndSettle();
    expect(api.payment!['user_id'], 11);
    expect(api.payment!['amount'], 200);
    expect(api.payment!['bank_account_id'], 4);
  });
  testWidgets('تلاش دوباره قسط بعد از پاسخ ناموفق همان نام درخواست را دارد',
      (tester) async {
    final api = _Api()..fail = true;
    await open(tester, api, loan: true);
    await tester.enterText(find.byType(TextFormField).first, '100');
    await tester.tap(find.text('ثبت پرداخت انجام‌شده'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ثبت پرداخت انجام‌شده'));
    await tester.pumpAndSettle();
    expect(api.keys.length, 2);
    expect(api.keys[0], api.keys[1]);
  });
  testWidgets('قسط بیشتر از مانده ارسال نمی‌شود', (tester) async {
    final api = _Api();
    await open(tester, api, loan: true);
    await tester.enterText(find.byType(TextFormField).first, '400');
    await tester.tap(find.text('ثبت پرداخت انجام‌شده'));
    await tester.pumpAndSettle();
    expect(api.keys, isEmpty);
    expect(find.text('مبلغ از مانده وام بیشتر است'), findsOneWidget);
  });
}
