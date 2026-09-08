import 'dart:typed_data';

import 'package:bakery_app/screens/admin/lateness_report_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// «A tariff nobody can check is a fine, not a rule.»
///
/// The baker has been able to check his own since that was written. The
/// person who would apply it could not check anybody's: the panel has no
/// penalty column at all, and `/work-starts/late-report` — which computes
/// the amount per person — had no caller on either side.
///
/// So the figure a baker is shown on his phone existed in exactly one
/// place, and it was not the place the deduction would be entered from.
Future<void> _pump(WidgetTester tester, String data) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire('{"success":true,"data":$data}'));

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: LatenessReportSection(api: BakeryApi(client)),
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

String _report({
  int lateDays = 3,
  String total = '۶٬۰۰۰٬۰۰۰ ریال',
  String byUser = '',
}) =>
    '{"period_label":"شهریور ۱۴۰۵","late_days":$lateDays,'
    '"penalty_total_formatted":"$total","by_user":[$byUser]}';

String _person(
  String? name,
  int count,
  String penalty, {
  int? id = 7,
  bool waived = false,
  bool settled = false,
}) =>
    '{"user":${name == null ? 'null' : '"$name"'},"late_count":$count,'
    '"penalty_formatted":"$penalty",'
    '${id == null ? '' : '"adjustment_id":$id,'}'
    '"waived":$waived,"settled":$settled}';

void main() {
  testWidgets('a month with nobody late says so', (tester) async {
    await _pump(tester, _report(lateDays: 0));

    expect(tester.takeException(), isNull);
    expect(find.textContaining('تأخیری ثبت نشده'), findsOneWidget);
  });

  testWidgets('each person is named with what the tariff comes to',
      (tester) async {
    await _pump(tester, _report(byUser: [
      _person('حسن شاطر', 2, '۴٬۰۰۰٬۰۰۰ ریال'),
      _person('رضا چانه‌گیر', 1, '۲٬۰۰۰٬۰۰۰ ریال'),
    ].join(',')));

    expect(tester.takeException(), isNull);
    expect(find.text('حسن شاطر'), findsOneWidget);
    expect(find.textContaining('۴٬۰۰۰٬۰۰۰ ریال'), findsOneWidget);
    expect(find.textContaining('۲٬۰۰۰٬۰۰۰ ریال'), findsOneWidget);
  });

  testWidgets('forgiving is offered on the row itself', (tester) async {
    // The screen showing the figure has to be the screen that can do
    // something about it, or the owner reads a number on the phone and
    // reaches for a computer to act on it.
    await _pump(tester, _report(byUser: _person('حسن شاطر', 2, '۴٬۰۰۰٬۰۰۰ ریال')));

    expect(find.text('بخشیدن'), findsOneWidget);
    expect(find.textContaining('طبق تعرفه از حقوق کسر می‌شود'), findsOneWidget);
  });

  testWidgets('a forgiven row says so and offers it back', (tester) async {
    await _pump(tester, _report(
      byUser: _person('حسن شاطر', 2, '۴٬۰۰۰٬۰۰۰ ریال', waived: true),
    ));

    // The amount stays visible: next month the question is «چقدر بود که
    // نگرفتم».
    expect(find.textContaining('۴٬۰۰۰٬۰۰۰ ریال'), findsOneWidget);
    expect(find.textContaining('بخشیده شد'), findsOneWidget);
    expect(find.text('بازگرداندن'), findsOneWidget);
    expect(find.text('بخشیدن'), findsNothing);
  });

  testWidgets('a month already on a payslip is not offered at all',
      (tester) async {
    // That figure is part of a net somebody was paid.
    await _pump(tester, _report(
      byUser: _person('حسن شاطر', 2, '۴٬۰۰۰٬۰۰۰ ریال', settled: true),
    ));

    expect(find.text('در فیش'), findsOneWidget);
    expect(find.text('بخشیدن'), findsNothing);
    expect(find.text('بازگرداندن'), findsNothing);
  });

  testWidgets('a row with no deduction behind it offers nothing to press',
      (tester) async {
    await _pump(tester, _report(
      byUser: _person('حسن شاطر', 2, '۴٬۰۰۰٬۰۰۰ ریال', id: null),
    ));

    expect(tester.takeException(), isNull);

    final button = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'بخشیدن'),
    );
    expect(button.onPressed, isNull);
  });

  testWidgets('a row whose name did not arrive still says something',
      (tester) async {
    await _pump(tester, _report(byUser: _person(null, 2, '۴٬۰۰۰٬۰۰۰ ریال')));

    expect(tester.takeException(), isNull);
    expect(find.text('بدون نام'), findsOneWidget);
  });

  testWidgets('a report that comes back as a list does not throw',
      (tester) async {
    // PHP sends `[]` for an empty keyed collection, which is how the grey
    // rectangle on the finance screen happened.
    await _pump(tester,
        '{"period_label":"شهریور ۱۴۰۵","late_days":2,"by_user":[]}');

    expect(tester.takeException(), isNull);
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
