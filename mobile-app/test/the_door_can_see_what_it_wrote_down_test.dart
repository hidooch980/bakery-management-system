import 'dart:typed_data';

import 'package:bakery_app/models/purchase.dart';
import 'package:bakery_app/screens/shared/my_purchases_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Whoever takes the delivery at the door knows what came off the lorry,
/// and had no way of seeing what they had already entered — the list lived
/// on the owner's panel. So «آن بار آرد را ثبت کردم یا نه» was answered by
/// entering it a second time.
///
/// `/purchases/mine` answered this all along and nothing asked it.
Future<void> _pump(WidgetTester tester, String rows) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(
    _Wire('{"success":true,"data":{"current_page":1,"data":[$rows]}}'),
  );

  await tester.pumpWidget(MaterialApp(
    home: MyPurchasesScreen(api: BakeryApi(client)),
  ));
  await tester.pumpAndSettle();
}

String _invoice({
  int id = 1,
  String supplier = 'آسیاب مرکزی',
  String? invoiceNo,
  bool settled = true,
  String outstanding = '۰ ریال',
}) =>
    '{"id":$id,"supplier_name":"$supplier",'
    '"purchased_on_display":"۱۴۰۵/۰۶/۰۱",'
    '"amount_formatted":"۵۰٬۰۰۰٬۰۰۰ ریال",'
    '"outstanding_formatted":"$outstanding",'
    '"is_settled":$settled,'
    '${invoiceNo == null ? '' : '"invoice_no":"$invoiceNo",'}'
    '"items":[{"label":"آرد","quantity_label":"۱۰ کیسه",'
    '"amount_formatted":"۵۰٬۰۰۰٬۰۰۰ ریال"}]}';

void main() {
  group('an invoice that came back short', () {
    test('a missing id does not throw', () {
      // `as int` threw, and a throw during build is a grey rectangle with
      // no text — one short row would have taken the list with it.
      final purchase = Purchase.fromJson(const {});

      expect(purchase.id, 0);
      expect(purchase.lines, isEmpty);
      expect(purchase.isSettled, isFalse);
    });
  });

  group('the screen', () {
    testWidgets('says so when nothing has been entered', (tester) async {
      await _pump(tester, '');

      expect(tester.takeException(), isNull);
      expect(find.textContaining('خریدی ثبت نکرده‌اید'), findsOneWidget);
    });

    testWidgets('leads with what is still owed', (tester) async {
      // An invoice not yet settled is the one worth acting on; the total
      // is only context for it.
      await _pump(tester, [
        _invoice(id: 1),
        _invoice(id: 2, settled: false, outstanding: '۱۲٬۰۰۰٬۰۰۰ ریال'),
      ].join(','));

      expect(find.textContaining('1 فاکتور تسویه‌نشده از 2'), findsOneWidget);
      expect(find.textContaining('مانده ۱۲٬۰۰۰٬۰۰۰ ریال'), findsOneWidget);
    });

    testWidgets('says all settled when none is owed', (tester) async {
      await _pump(tester, _invoice());

      expect(find.textContaining('همه تسویه شده'), findsOneWidget);
    });

    testWidgets('names the invoice by its number when the mill is not named',
        (tester) async {
      // An invoice against a supplier already on file arrives with an id
      // and no name.
      await _pump(tester, _invoice(supplier: '', invoiceNo: '۸۸۱'));

      expect(find.text('فاکتور ۸۸۱'), findsOneWidget);
    });

    testWidgets('falls back to the record number when there is neither',
        (tester) async {
      await _pump(tester, _invoice(id: 7, supplier: ''));

      expect(tester.takeException(), isNull);
      expect(find.text('فاکتور #7'), findsOneWidget);
    });

    testWidgets('shows what was on the invoice', (tester) async {
      await _pump(tester, _invoice());

      expect(find.text('آسیاب مرکزی'), findsOneWidget);
      expect(find.textContaining('آرد'), findsWidgets);
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
