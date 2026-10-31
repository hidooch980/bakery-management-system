import 'dart:convert';
import 'dart:typed_data';

import 'package:bakery_app/screens/admin/monthly_quota_button.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _Server implements HttpClientAdapter {
  final writes = <Map<String, dynamic>>[];
  int status = 201;

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (options.method != 'POST' || options.path != '/flour-allocations') {
      throw StateError('درخواست پیش‌بینی‌نشده: ${options.path}');
    }
    writes.add(Map<String, dynamic>.from(options.data as Map));
    return ResponseBody.fromString(
      jsonEncode({
        'success': status == 201,
        'message': status == 409 ? 'برای این ماه قبلاً سهمیه ثبت شده است.' : 'سهمیه ثبت شد.',
        'data': {'id': 1},
      }),
      status,
      headers: {Headers.contentTypeHeader: ['application/json']},
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<void> _open(WidgetTester tester, _Server server, VoidCallback saved) async {
  final client = ApiClient(baseUrl: 'https://server.test/api/v1');
  client.transport = server;
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(body: MonthlyQuotaButton(api: BakeryApi(client), onSaved: saved)),
  ));
  await tester.tap(find.text('ثبت سهمیه آرد'));
  await tester.pumpAndSettle();
}

Future<void> _submit(WidgetTester tester) async {
  final button = find.widgetWithText(FilledButton, 'ثبت سهمیه');
  await tester.ensureVisible(button);
  await tester.tap(button);
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  testWidgets('ثبت ماه انتخابی همراه تعداد کیسه فارسی و مانده ارسال می‌شود', (tester) async {
    final server = _Server();
    var saved = 0;
    await _open(tester, server, () => saved++);
    final picker = tester.widget<DropdownButton<String>>(
      find.byType(DropdownButton<String>),
    );
    final index = picker.items!.indexWhere((item) => item.value == picker.value);
    final next = picker.items![index + 1];
    await tester.tap(find.byKey(const ValueKey('quota-month')));
    await tester.pumpAndSettle();
    await tester.tap(find.text((next.child as Text).data!).last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('quota-bags')), '۳۰۰٫۵');
    await tester.enterText(find.byKey(const ValueKey('quota-carryover')), '۲۰');
    await tester.enterText(find.byKey(const ValueKey('quota-note')), 'حواله ماهانه');
    await _submit(tester);
    expect(server.writes, hasLength(1));
    expect(server.writes.single['month_start'], next.value);
    expect(next.value, endsWith('/01'));
    expect(server.writes.single['total_bags'], 300.5);
    expect(server.writes.single['carryover_bags'], 20);
    expect(server.writes.single['note'], 'حواله ماهانه');
    expect(saved, 1);
    expect(find.byKey(const ValueKey('quota-bags')), findsNothing);
  });

  testWidgets('سهمیه نامعتبر ارسال نمی‌شود', (tester) async {
    final server = _Server();
    await _open(tester, server, () {});
    await _submit(tester);
    expect(server.writes, isEmpty);
    expect(find.text('تعداد کیسه معتبر و نامنفی وارد کنید.'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('quota-bags')), '-5');
    await _submit(tester);
    expect(server.writes, isEmpty);
  });

  testWidgets('پیام سهمیه تکراری فرم را باز و مقدار واردشده را حفظ می‌کند', (tester) async {
    final server = _Server()..status = 409;
    var saved = 0;
    await _open(tester, server, () => saved++);
    await tester.enterText(find.byKey(const ValueKey('quota-bags')), '300');
    await _submit(tester);
    expect(find.text('برای این ماه قبلاً سهمیه ثبت شده است.'), findsOneWidget);
    expect(find.text('300'), findsOneWidget);
    expect(saved, 0);
    expect(server.writes, hasLength(1));
  });
}
