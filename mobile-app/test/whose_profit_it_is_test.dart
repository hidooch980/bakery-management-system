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
Future<void> _pump(
  WidgetTester tester,
  String data, {
  String? settlements,
}) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire({
    '/shares/split': '{"success":true,"data":$data}',
    if (settlements != null)
      '/shares/settlements': '{"success":true,"data":$settlements}',
  }));

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

  testWidgets('the history lists what has actually been handed over',
      (tester) async {
    // The split only ever shows one stretch. «پارسال چقدر گرفتم» is
    // answered here and nowhere else on the phone.
    await _pump(
      tester,
      _split(_holder()),
      settlements: '[{"id":7,"bakery_share_id":1,'
          '"share":{"id":1,"name":"عبدالناصر"},'
          '"period_label":"مرداد ۱۴۰۵","amount_formatted":"۴٬۰۰۰٬۰۰۰ ریال",'
          '"paid_on_display":"۱۴۰۵/۰۶/۰۴","is_paid":true,"note":"نقدی"}]',
    );

    await tester.tap(find.text('سابقهٔ تسویه‌ها'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('مرداد ۱۴۰۵  •  ۱۴۰۵/۰۶/۰۴'), findsOneWidget);
    expect(find.text('۴٬۰۰۰٬۰۰۰ ریال'), findsOneWidget);
    expect(find.text('نقدی'), findsOneWidget);
  });

  testWidgets('a payout not yet handed over says so', (tester) async {
    // A row with no paid_on is a decision recorded, not money gone. The
    // colour and the wording have to keep those apart.
    await _pump(
      tester,
      _split(_holder()),
      settlements: '[{"id":8,"bakery_share_id":1,'
          '"share":{"id":1,"name":"حسین"},"period_label":"شهریور ۱۴۰۵",'
          '"amount_formatted":"۱٬۰۰۰٬۰۰۰ ریال","paid_on_display":null,'
          '"is_paid":false,"note":null}]',
    );

    await tester.tap(find.text('سابقهٔ تسویه‌ها'));
    await tester.pumpAndSettle();

    expect(find.textContaining('پرداخت نشده'), findsOneWidget);
  });

  testWidgets('an empty history says so rather than showing nothing',
      (tester) async {
    await _pump(tester, _split(_holder()), settlements: '[]');

    await tester.tap(find.text('سابقهٔ تسویه‌ها'));
    await tester.pumpAndSettle();

    expect(find.text('هنوز دنگی پرداخت نشده است.'), findsOneWidget);
  });

  testWidgets('a history row with no partner name still says which record',
      (tester) async {
    await _pump(
      tester,
      _split(_holder()),
      settlements: '[{"id":9,"bakery_share_id":3,'
          '"period_label":"تیر ۱۴۰۵","amount_formatted":"۱ ریال",'
          '"is_paid":true}]',
    );

    await tester.tap(find.text('سابقهٔ تسویه‌ها'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('کارمند #3'), findsOneWidget);
  });
}


class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  /// Keyed by path, because the دنگ section now reads two endpoints and a
  /// single canned reply would have the history answer the split.
  final Map<String, String> bodies;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(_bodyFor(options.path), 200, headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      });

  String _bodyFor(String path) {
    for (final entry in bodies.entries) {
      if (path.contains(entry.key)) return entry.value;
    }

    return '{"success":true,"data":[]}';
  }

  @override
  void close({bool force = false}) {}
}
