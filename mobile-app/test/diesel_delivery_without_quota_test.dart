import 'dart:convert';
import 'dart:typed_data';

import 'package:bakery_app/screens/admin/diesel_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _Server implements HttpClientAdapter {
  bool hasQuota = false;
  final deliveries = <Map<String, dynamic>>[];
  final writes = <Map<String, dynamic>>[];

  Map<String, dynamic>? get allocation => hasQuota
      ? {
          'month_label': 'مهر ۱۴۰۵',
          'total_litres': 1000,
          'delivered_litres': 0,
          'remaining_litres': 1000,
        }
      : null;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    late Map<String, dynamic> data;
    if (options.method == 'GET' && options.path == '/diesel/quota') {
      data = {'allocation': allocation, 'deliveries': deliveries};
    } else if (options.method == 'POST' &&
        options.path == '/diesel/deliveries') {
      final body = Map<String, dynamic>.from(options.data as Map);
      writes.add(body);
      final delivery = {
        'id': deliveries.length + 1,
        'litres': body['litres'],
        'amount_formatted': '۱۲۰٬۰۰۰ تومان',
        'received_on_label': '۱۴۰۵/۰۷/۲۳',
        'docket_number': body['docket_number'],
      };
      deliveries.insert(0, delivery);
      data = {'allocation': allocation, 'delivery': delivery, 'warning': null};
    } else {
      throw StateError(
        'درخواست پیش‌بینی‌نشده: ${options.method} ${options.path}',
      );
    }
    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      options.method == 'POST' ? 201 : 200,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<void> _show(WidgetTester tester, _Server server) async {
  final client = ApiClient(baseUrl: 'https://server.test/api/v1');
  client.transport = server;
  await tester.pumpWidget(
    MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          body: ListView(children: [DieselSection(api: BakeryApi(client))]),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  testWidgets('مدیر بدون سهمیه تحویل را ثبت می‌کند و نتیجه را می‌بیند', (
    tester,
  ) async {
    final server = _Server();
    await _show(tester, server);
    expect(find.text('برای این ماه سهمیه‌ای ثبت نشده'), findsOneWidget);
    await tester.tap(find.text('ثبت تحویل'));
    await tester.pumpAndSettle();
    expect(find.text('ثبت تحویل گازوئیل'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'ثبت'));
    await tester.pumpAndSettle();
    expect(server.writes, isEmpty);
    await tester.enterText(find.byType(TextField).at(0), '400');
    await tester.enterText(find.byType(TextField).at(1), '120000');
    await tester.enterText(find.byType(TextField).at(2), 'A-123');
    await tester.tap(find.widgetWithText(FilledButton, 'ثبت'));
    await tester.pumpAndSettle();
    expect(server.writes, hasLength(1));
    expect(server.writes.single['litres'], 400);
    expect(server.writes.single['amount'], 120000);
    expect(server.writes.single['docket_number'], 'A-123');
    expect(find.textContaining('400 لیتر'), findsOneWidget);
    expect(find.textContaining('A-123'), findsOneWidget);
    expect(find.text('ثبت تحویل'), findsOneWidget);
  });

  testWidgets('تحویل قبلی با نبودن سهمیه از فهرست پنهان نمی‌شود', (
    tester,
  ) async {
    final server = _Server();
    server.deliveries.add({
      'id': 1,
      'litres': 250,
      'received_on_label': '۱۴۰۵/۰۷/۲۲',
      'docket_number': 'OLD-1',
    });
    await _show(tester, server);
    expect(find.textContaining('250 لیتر'), findsOneWidget);
    expect(find.textContaining('OLD-1'), findsOneWidget);
    expect(find.text('ثبت تحویل'), findsOneWidget);
  });

  testWidgets('داشتن سهمیه مسیر ثبت تحویل را حفظ می‌کند', (tester) async {
    await _show(tester, _Server()..hasQuota = true);
    expect(find.text('سهمیه گازوئیل — مهر ۱۴۰۵'), findsOneWidget);
    await tester.tap(find.text('ثبت تحویل'));
    await tester.pumpAndSettle();
    expect(find.text('ثبت تحویل گازوئیل'), findsOneWidget);
  });
}
