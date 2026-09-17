import 'dart:typed_data';

import 'package:bakery_app/screens/admin/seller_debts_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// آنچه فروشنده قبلاً داده، روی همان صفحه‌ای که مالک نگاه می‌کند.
///
/// یک فروش یا کامل تسویه می‌شود یا اصلاً — پس کسی که ۴۰۰ از ۴۵۰ را
/// تحویل می‌دهد، بدهی‌اش سرِ جایش می‌ماند و پولش کنارش می‌نشیند. سرور
/// این را می‌داند و می‌فرستد.
///
/// صفحه‌اش را نمی‌دانست. ردیف کلِ ۴۵۰ را نشان می‌داد و دیالوگ تأیید
/// می‌پرسید «مبلغ ۴۵۰ هزار را تحویل گرفته‌اید؟» — سؤالی که جوابِ صادقانه‌اش
/// برای مردی که ۵۰ هزار بدهکار است «نه» است. مالک یا دوباره همان را
/// می‌خواست، یا دکمه را می‌زد و می‌پذیرفت چیزی را که نگرفته.
///
/// اینکه سرور درست بداند و صفحه غلط بگوید، همان باگ است — فقط یک لایه
/// آن‌طرف‌تر.
String _seller({
  double settleable = 450000,
  double? onAccount,
  double? stillOwed,
}) =>
    '{"success":true,"data":{"sellers":[{'
    '"id":4,"name":"فروشنده",'
    '"cash":$settleable,"cash_formatted":"۴۵۰٬۰۰۰ تومان",'
    '"difference_formatted":"۰","shortfall_formatted":"۰",'
    '"credit":0,"credit_formatted":"۰",'
    '"settleable":$settleable,"settleable_formatted":"۴۵۰٬۰۰۰ تومان"'
    '${onAccount == null ? '' : ',"on_account":$onAccount,'
        '"on_account_formatted":"۴۰۰٬۰۰۰ تومان"'}'
    '${stillOwed == null ? '' : ',"still_owed":$stillOwed,'
        '"still_owed_formatted":"۵۰٬۰۰۰ تومان"'}'
    ',"request":null}],"pending_count":0,"currency_label":"تومان"}}';

Future<_Wire> _pump(WidgetTester tester, String body) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  final wire = _Wire({'/seller-accounts': body});
  client.useAdapterForTest(wire);

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: SellerDebtsSection(api: BakeryApi(client)),
      ),
    ),
  ));
  await tester.pumpAndSettle();

  return wire;
}

void main() {
  testWidgets('the row shows what is left, not the debt before he paid',
      (tester) async {
    await _pump(tester, _seller(onAccount: 400000, stillOwed: 50000));

    expect(find.text('۵۰٬۰۰۰ تومان'), findsOneWidget);
  });

  testWidgets('and says how much he has already handed over', (tester) async {
    await _pump(tester, _seller(onAccount: 400000, stillOwed: 50000));

    expect(find.textContaining('قبلاً پرداخت شده'), findsOneWidget);
    expect(find.textContaining('۴۰۰٬۰۰۰ تومان'), findsOneWidget);
  });

  testWidgets('a seller who has paid nothing gets no extra line',
      (tester) async {
    // «قبلاً پرداخت شده: ۰» on every other row is noise, and noise is
    // what makes a page stop being read.
    await _pump(tester, _seller(onAccount: 0, stillOwed: 450000));

    expect(find.textContaining('قبلاً پرداخت شده'), findsNothing);
  });

  testWidgets('the confirm dialog asks about what is actually left',
      (tester) async {
    // The assertion this file exists for. Asking «have you received
    // 450,000?» of a man who owes 50,000 gets an honest «no» — or a tap
    // that accepts money nobody handed over.
    await _pump(tester, _seller(onAccount: 400000, stillOwed: 50000));

    await tester.tap(find.widgetWithText(OutlinedButton, 'ثبت تسویه'));
    await tester.pumpAndSettle();

    expect(find.textContaining('۵۰٬۰۰۰ تومان را از این فروشنده'), findsOneWidget);
    expect(find.textContaining('قبلاً ۴۰۰٬۰۰۰ تومان پرداخت کرده'), findsOneWidget);
  });

  testWidgets('an older server that sends neither field still reads right',
      (tester) async {
    // The phone outlives the server it talks to. Without the fields the
    // row falls back to the debt and behaves exactly as it always did.
    await _pump(tester, _seller());

    expect(find.text('۴۵۰٬۰۰۰ تومان'), findsOneWidget);
    expect(find.textContaining('قبلاً پرداخت شده'), findsNothing);
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.method} ${options.path}');

    return ResponseBody.fromString(_bodyFor(options.path), 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  String _bodyFor(String path) {
    for (final key in bodies.keys) {
      if (path.contains(key)) return bodies[key]!;
    }

    return '{"success":true,"data":[]}';
  }

  @override
  void close({bool force = false}) {}
}
