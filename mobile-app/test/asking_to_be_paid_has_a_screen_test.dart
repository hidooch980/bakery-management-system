import 'dart:typed_data';

import 'package:bakery_app/models/staff_adjustment.dart';
import 'package:bakery_app/screens/admin/salary_requests_section.dart';
import 'package:bakery_app/screens/shared/my_salary_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The shop once went three weeks without writing a payslip and nobody had
/// a way to say so except in person — which means the one who asks is the
/// one willing to, not the one who is owed.
///
/// The server has carried the whole flow since then. Nothing on either
/// side of it — the staff member's or the owner's — ever called it, so the
/// requests were arriving into a table nobody opens.
Future<BakeryApi> _api(String body) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire(body));

  return BakeryApi(client);
}

String _mine(String rows) => '{"success":true,"data":[$rows]}';

String _pending(String rows) =>
    '{"success":true,"data":{"current_page":1,"data":[$rows]}}';

String _request({
  int id = 1,
  String status = 'pending',
  String statusLabel = 'در انتظار',
  int days = 19,
  String? name,
  String? net = '۱۲٬۰۰۰٬۰۰۰ ریال',
  String? decision,
}) =>
    '{"id":$id,"period_label":"مرداد ۱۴۰۵","status":"$status",'
    '"status_label":"$statusLabel","days_waiting":$days,'
    '"requested_on_jalali":"۱۴۰۵/۰۶/۰۱",'
    '${name == null ? '' : '"user":{"id":7,"name":"$name"},'}'
    '${net == null ? '' : '"estimated_net_formatted":"$net",'}'
    '${decision == null ? '' : '"decision_note":"$decision",'}'
    '"note":null}';

void main() {
  group('the staff member', () {
    testWidgets('is told plainly when they have asked for nothing',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: MySalaryScreen(api: await _api(_mine(''))),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('درخواستی ثبت نکرده‌اید'), findsOneWidget);
      expect(find.textContaining('درخواست حقوق این ماه'), findsOneWidget);
    });

    testWidgets('cannot ask twice while one is still waiting',
        (tester) async {
      // Two open requests are the same sentence twice, and whoever reads
      // them cannot tell which month is meant.
      await tester.pumpWidget(MaterialApp(
        home: MySalaryScreen(api: await _api(_mine(_request()))),
      ));
      await tester.pumpAndSettle();

      final button = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'یک درخواست در انتظار پاسخ دارید'),
      );

      expect(button.onPressed, isNull);
    });

    testWidgets('can ask again once the open one is answered',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: MySalaryScreen(
          api: await _api(_mine(_request(status: 'rejected', statusLabel: 'رد شده'))),
        ),
      ));
      await tester.pumpAndSettle();

      final button = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'درخواست حقوق این ماه'),
      );

      expect(button.onPressed, isNotNull);
    });

    testWidgets('sees the answer they were given, not only that it was no',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: MySalaryScreen(
          api: await _api(_mine(_request(
            status: 'rejected',
            statusLabel: 'رد شده',
            decision: 'آخر هفته پرداخت می‌شود',
          ))),
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.textContaining('آخر هفته پرداخت می‌شود'), findsOneWidget);
    });

    testWidgets('the figure is named as an estimate', (tester) async {
      // A number about somebody's pay, read as a promise, is worse than
      // no number.
      await tester.pumpWidget(MaterialApp(
        home: MySalaryScreen(api: await _api(_mine(_request()))),
      ));
      await tester.pumpAndSettle();

      expect(find.textContaining('اگر امروز پرداخت شود'), findsOneWidget);
    });
  });

  group('the owner', () {
    testWidgets('is told when nobody is waiting', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SalaryRequestsSection(api: await _api(_pending(''))),
        ),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('درخواست بی‌پاسخی نیست'), findsOneWidget);
    });

    testWidgets('sees who asked and how long they have waited',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SalaryRequestsSection(
            api: await _api(_pending(_request(name: 'حسن شاطر'))),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.text('حسن شاطر'), findsOneWidget);
      expect(find.textContaining('19 روز در انتظار'), findsOneWidget);
    });

    testWidgets('a request with no name still says which record it is',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SalaryRequestsSection(
            api: await _api(_pending(_request(id: 4))),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('کارمند #4'), findsOneWidget);
    });

    testWidgets('has no approve button, and the screen says why',
        (tester) async {
      // Paying through the pay sheet is what approval means; there is no
      // approve route on the server either.
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SalaryRequestsSection(
            api: await _api(_pending(_request(name: 'حسن شاطر'))),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.textContaining('تأیید'), findsNothing);
      expect(find.textContaining('رد کردن'), findsOneWidget);
      expect(find.textContaining('پرداخت از «حقوق ماه»'), findsOneWidget);
    });
  });

  group('a request that came back short', () {
    test('reads without an exception and keeps its id', () {
      final request = SalaryRequest.fromJson(const {});

      expect(request.id, 0);
      expect(request.isPending, isTrue);
      expect(request.estimatedNetFormatted, isNull);
    });
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.body);

  final String body;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(body, 200, headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      });

  @override
  void close({bool force = false}) {}
}
