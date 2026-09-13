import 'dart:typed_data';

import 'package:bakery_app/screens/admin/payroll_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/utils/formatters.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A payslip written is not a wage handed over.
///
/// The payroll decided who had been paid by asking whether a slip existed
/// for the period, and never looked at `is_paid` — which the server sends
/// and the model has parsed all along. Every slip the phone writes is paid
/// the moment it is written, so on the phone's own path the two are the
/// same thing and nothing showed.
///
/// They come apart in the panel, which has an «unpaid» filter, a mark-paid
/// action and a badge counting them. A slip prepared there and not yet
/// paid made the phone print «پرداخت شد» with a green tick and refuse the
/// row — so the wage could not be paid from the phone, and the screen said
/// it already had been. Somebody is waiting for that money.
String get _thisPeriod {
  final now = latinDigits(JalaliFormat.date(DateTime.now()));
  final parts = now.split('/');

  return parts.length == 3 ? '${parts[0]}/${parts[1]}/01' : now;
}

String _slip({required bool isPaid}) =>
    '{"id":5,"user":{"id":1,"name":"عبدالناصر"},'
    '"period_start_jalali":"$_thisPeriod","period_label":"مهر",'
    '"net_amount":9000000,"net_amount_formatted":"۹٬۰۰۰٬۰۰۰ ریال",'
    '"is_paid":$isPaid}';

Future<_Wire> _pump(
  WidgetTester tester, {
  required bool isPaid,
  bool withAccount = false,
}) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  final wire = _Wire({
    '/salaries/employees': '{"success":true,"data":['
        '{"id":1,"name":"عبدالناصر","monthly_salary":9000000,'
        '"monthly_salary_formatted":"۹٬۰۰۰٬۰۰۰ ریال"}]}',
    '/salaries': '{"success":true,"data":[${_slip(isPaid: isPaid)}]}',
    '/bank-accounts': withAccount
        ? '{"success":true,"data":{"accounts":[{"id":3,"title":"ملی",'
            '"is_active":true,"balance":0,"balance_formatted":"۰"}],'
            '"total":0}}'
        : '{"success":true,"data":{"accounts":[],"total":0}}',
  });
  client.useAdapterForTest(wire);

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(child: PayrollSection(api: BakeryApi(client))),
    ),
  ));
  await tester.pumpAndSettle();

  return wire;
}

void main() {
  testWidgets('a slip already paid closes the row', (tester) async {
    await _pump(tester, isPaid: true);

    expect(find.text('پرداخت شد'), findsOneWidget);
  });

  testWidgets('a slip prepared but not paid does not read as paid',
      (tester) async {
    // The row must not claim the money has gone. It has not.
    await _pump(tester, isPaid: false);

    expect(find.text('پرداخت شد'), findsNothing);
  });

  testWidgets('an unpaid slip says it is waiting to be handed over',
      (tester) async {
    await _pump(tester, isPaid: false);

    expect(find.textContaining('پرداخت نشده'), findsOneWidget);
  });

  testWidgets('handing over an unpaid slip pays that slip, not a second one',
      (tester) async {
    // Writing another slip for the same month is the payroll paid twice.
    // This is the assertion the whole file is for.
    final wire = await _pump(tester, isPaid: false);

    await tester.tap(find.textContaining('پرداخت نشده'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'پرداخت شد'));
    await tester.pumpAndSettle();

    expect(wire.seen, contains('PATCH /salaries/5/mark-paid'));
    expect(wire.seen.where((r) => r == 'POST /salaries'), isEmpty);
  });

  testWidgets('backing out of the sheet pays nobody', (tester) async {
    final wire = await _pump(tester, isPaid: false);

    await tester.tap(find.textContaining('پرداخت نشده'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'بی‌خیال'));
    await tester.pumpAndSettle();

    expect(wire.seen.any((r) => r.startsWith('PATCH')), isFalse);
  });

  testWidgets('dismissing the sheet without answering pays nobody',
      (tester) async {
    // Tapping the scrim, dragging down, or the back button pops null —
    // none of them goes through a button of mine. Treating «no answer» as
    // an answer hands over a wage the owner just backed out of.
    final wire = await _pump(tester, isPaid: false);

    await tester.tap(find.textContaining('پرداخت نشده'));
    await tester.pumpAndSettle();

    // The scrim: the top-left corner of the screen, above the sheet.
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();

    expect(wire.seen.any((r) => r.startsWith('PATCH')), isFalse);
  });

  testWidgets('paying from the till says so rather than leaving it unsaid',
      (tester) async {
    // The server keeps the slip's existing account when the key is absent,
    // and a slip prepared in the panel defaults to the shop's main account.
    // So «از صندوق» has to be said out loud, or cash out of the till debits
    // the bank.
    final wire = await _pump(tester, isPaid: false);

    await tester.tap(find.textContaining('پرداخت نشده'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'پرداخت شد'));
    await tester.pumpAndSettle();

    final patch = wire.sentBodies[wire.seen.indexWhere((r) => r.startsWith('PATCH'))];

    expect(patch, isA<Map<String, dynamic>>());
    expect((patch! as Map<String, dynamic>).containsKey('bank_account_id'), isTrue);
    expect((patch as Map<String, dynamic>)['bank_account_id'], isNull);
  });

  testWidgets('choosing a bank account sends that account', (tester) async {
    // The other half of the same question: «از صندوق» must send null and
    // «از حساب» must send the id. One record carries both answers, so both
    // need a test or the record is only half exercised.
    final wire = await _pump(tester, isPaid: false, withAccount: true);

    await tester.tap(find.textContaining('پرداخت نشده'));
    await tester.pumpAndSettle();

    await tester.tap(find.byType(DropdownButtonFormField<int>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ملی').last);
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'پرداخت شد'));
    await tester.pumpAndSettle();

    final patch = wire.sentBodies[
        wire.seen.indexWhere((r) => r.startsWith('PATCH'))]! as Map<String, dynamic>;

    expect(patch['bank_account_id'], 3);
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  /// Every request, as «METHOD path». The point of the test below is which
  /// call the tap makes, so the calls have to be visible.
  final List<String> seen = [];

  /// And what was sent with it. «از صندوق» and «از حساب» differ only in
  /// this body, so the body is the only place the difference can be read.
  final List<Object?> sentBodies = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.method} ${options.path}');
    sentBodies.add(options.data);

    return ResponseBody.fromString(_bodyFor(options.path), 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  String _bodyFor(String path) {
    // Longest match first: '/salaries/employees' also contains '/salaries'.
    final keys = bodies.keys.toList()
      ..sort((a, b) => b.length.compareTo(a.length));

    for (final key in keys) {
      if (path.contains(key)) return bodies[key]!;
    }

    return '{"success":true,"data":[]}';
  }

  @override
  void close({bool force = false}) {}
}
