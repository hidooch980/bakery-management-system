import 'dart:typed_data';

import 'package:bakery_app/screens/admin/profit_and_loss_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// «سود و زیان», on the phone.
///
/// The panel has had the statement since it was written. This page had the
/// halves — income in one card, expenses in another, profit in a widget —
/// and never the sum. On 2026-08-16 the dashboard and the report disagreed
/// about profit by 164,640,000 Rial because flour was counted twice, and
/// two screens each showing half a sum is how that survived.
Future<void> _pump(WidgetTester tester, String data) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire('{"success":true,"data":$data}'));

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: ProfitAndLossSection(
          api: BakeryApi(client),
          from: '2026-09-01',
          to: '2026-09-07',
        ),
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

String _statement({
  num profit = 3000000,
  String profitText = '۳٬۰۰۰٬۰۰۰ ریال',
}) =>
    '{"income":{"bread_formatted":"۱۰٬۰۰۰٬۰۰۰ ریال",'
    '"flour_formatted":"۲٬۰۰۰٬۰۰۰ ریال",'
    '"other_formatted":"۰ ریال"},'
    '"income_total_formatted":"۱۲٬۰۰۰٬۰۰۰ ریال",'
    '"costs":[{"label":"خرید آرد","amount_formatted":"۷٬۰۰۰٬۰۰۰ ریال"},'
    '{"label":"حقوق پرداخت‌شده","amount_formatted":"۲٬۰۰۰٬۰۰۰ ریال"},'
    '{"label":"سایر هزینه‌ها","amount_formatted":"۰ ریال"}],'
    '"expense_total_formatted":"۹٬۰۰۰٬۰۰۰ ریال",'
    '"profit":$profit,"profit_formatted":"$profitText",'
    '"cogs_formatted":"۵٬۰۰۰٬۰۰۰ ریال",'
    '"gross_profit_formatted":"۷٬۰۰۰٬۰۰۰ ریال"}';

void main() {
  testWidgets('the whole statement is on one card', (tester) async {
    await _pump(tester, _statement());

    expect(tester.takeException(), isNull);
    expect(find.text('فروش نان'), findsOneWidget);
    expect(find.text('خرید آرد'), findsOneWidget);
    expect(find.text('جمع درآمد'), findsOneWidget);
    expect(find.text('جمع پرداختی'), findsOneWidget);
    expect(find.text('سود دوره'), findsOneWidget);
  });

  testWidgets('a loss is called a loss', (tester) async {
    // A loss is not a smaller profit. It is the one line on this card that
    // has to be impossible to skim past.
    await _pump(tester, _statement(
      profit: -1500000,
      profitText: '−۱٬۵۰۰٬۰۰۰ ریال',
    ));

    expect(find.text('زیان دوره'), findsOneWidget);
    expect(find.text('سود دوره'), findsNothing);
  });

  testWidgets('cost of goods is beside the profit and says why',
      (tester) async {
    // It counts flour as it is baked rather than as it is bought, so it
    // disagrees with the headline on purpose. Unlabelled, that reads as a
    // contradiction.
    await _pump(tester, _statement());

    expect(find.textContaining('بهای تمام‌شده'), findsOneWidget);
    expect(find.textContaining('روزی که پخته شده'), findsOneWidget);
  });

  testWidgets('the headline says what it counts', (tester) async {
    await _pump(tester, _statement());

    expect(find.textContaining('هر پولی که از حساب خارج شده'), findsOneWidget);
  });

  testWidgets('a statement that came back short does not take the tab with it',
      (tester) async {
    // PHP sends `[]` for an empty keyed collection, which is how the grey
    // rectangle on this very screen happened.
    await _pump(tester, '{"income":[],"costs":[],"profit":0}');

    expect(tester.takeException(), isNull);
    expect(find.text('سود دوره'), findsOneWidget);
  });

  testWidgets('an unreachable statement stays away rather than shouting',
      (tester) async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(_Wire('nonsense', status: 500));

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: ProfitAndLossSection(
          api: BakeryApi(client),
          from: '2026-09-01',
          to: '2026-09-07',
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('سود دوره'), findsNothing);
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.body, {this.status = 200});

  final String body;
  final int status;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(body, status, headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      });

  @override
  void close({bool force = false}) {}
}
