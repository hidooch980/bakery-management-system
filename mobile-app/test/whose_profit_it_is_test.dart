import 'dart:typed_data';

import 'package:bakery_app/screens/admin/share_split_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// دنگ, on the phone.
///
/// The shares, the split and the settlement have been on the server and in
/// the panel since they were written, and the phone had none of it. So the
/// one figure a partner actually asks about — «سهم من چقدر شد» — was the
/// one the owner could not answer without opening a computer.
///
/// The dividing stays the server's: each cut is rounded to the currency
/// and the residual goes to the largest holder so the parts add back up to
/// the profit exactly. Repeating that here would give «سهم من» two
/// answers, which is worse than none.
Future<void> _pump(WidgetTester tester, String data) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire('{"success":true,"data":$data}'));

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: ShareSplitSection(
          api: BakeryApi(client),
          from: '2026-09-01',
          to: '2026-09-07',
        ),
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

String _holder({
  int id = 1,
  String name = 'عبدالناصر',
  String dang = '۳ دانگ از ۶',
  String amount = '۵٬۰۰۰٬۰۰۰ ریال',
  num paid = 0,
  num remaining = 5000000,
}) =>
    '{"id":$id,"name":"$name","dang_label":"$dang",'
    '"amount_formatted":"$amount","paid":$paid,'
    '"paid_formatted":"پرداختی","remaining":$remaining,'
    '"remaining_formatted":"مانده"}';

String _split(String holders) =>
    '{"profit_formatted":"۱۰٬۰۰۰٬۰۰۰ ریال","holders":[$holders]}';

void main() {
  testWidgets('each partner is named with their dang and their cut',
      (tester) async {
    await _pump(tester, _split([
      _holder(id: 1, name: 'عبدالناصر'),
      _holder(id: 2, name: 'حسین', dang: '۳ دانگ از ۶'),
    ].join(',')));

    expect(tester.takeException(), isNull);
    expect(find.text('عبدالناصر'), findsOneWidget);
    expect(find.text('حسین'), findsOneWidget);
    expect(find.textContaining('۳ دانگ از ۶'), findsWidgets);
  });

  testWidgets('a shop with one owner shows nothing to divide',
      (tester) async {
    // An empty table would read as something missing rather than absent.
    await _pump(tester, _split(''));

    expect(tester.takeException(), isNull);
    expect(find.text('دنگ شرکا'), findsNothing);
  });

  testWidgets('a partly paid partner shows what is left', (tester) async {
    await _pump(tester, _split(
      _holder(paid: 2000000, remaining: 3000000),
    ));

    expect(find.textContaining('مانده'), findsOneWidget);
    expect(find.text('پرداخت'), findsOneWidget);
  });

  testWidgets('a settled partner is not offered payment again',
      (tester) async {
    // Paying the same stretch twice takes the money out of the account
    // twice. The server refuses it; the screen must not invite it.
    await _pump(tester, _split(
      _holder(paid: 5000000, remaining: 0),
    ));

    expect(find.text('تسویه شده'), findsOneWidget);
    expect(find.text('پرداخت'), findsNothing);
  });

  testWidgets('the snapshot rule is said on the screen', (tester) async {
    // A settlement keeps the amount of the day it was made, so correcting
    // the books later does not rewrite what somebody was handed.
    await _pump(tester, _split(_holder()));

    expect(find.textContaining('اصلاح بعدیِ'), findsOneWidget);
  });

  testWidgets('a partner with no name still says which record it is',
      (tester) async {
    await _pump(tester,
        '{"profit_formatted":"۱۰٬۰۰۰٬۰۰۰ ریال","holders":[{"id":4}]}');

    expect(tester.takeException(), isNull);
    expect(find.text('کارمند #4'), findsOneWidget);
  });

  testWidgets('a split that came back as a list does not throw',
      (tester) async {
    await _pump(tester, '{"profit_formatted":"۰ ریال","holders":[]}');

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
