import 'dart:typed_data';

import 'package:bakery_app/screens/admin/inventory_entries_sheet.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// «وقتی روش کلیک کنم ریز گردش نشون بده.»
///
/// «۱۰۶ کیسه» invites exactly one question, and answering it used to mean
/// scrolling past the balances to the journey below and opening a day. The
/// balance is where the question is asked, so it is where the entries open.
const _entry = '{"id":4,"item":{"key":"flour","name":"آرد","unit":"کیلوگرم"},'
    '"direction":"out","quantity":120,"reason":"production",'
    '"reason_label":"مصرف در تولید","note":"پخت صبح",'
    '"user":{"id":2,"name":"عبدالناصر"},'
    '"created_at":"2026-09-12 09:14:00",'
    '"created_at_display":"۱۴۰۵/۰۶/۲۱ ۰۹:۱۴"}';

Future<_Wire> _open(
  WidgetTester tester, {
  String rows = _entry,
  String? from,
  String? to,
  String subtitle = 'آخرین گردش‌ها',
}) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  final wire = _Wire('{"success":true,"data":{"data":[$rows]}}');
  client.useAdapterForTest(wire);

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: InventoryEntriesSheet(
        api: BakeryApi(client),
        itemKey: 'flour',
        itemName: 'آرد',
        subtitle: subtitle,
        from: from,
        to: to,
      ),
    ),
  ));
  await tester.pumpAndSettle();

  return wire;
}

void main() {
  testWidgets('each entry says how much, why, when and who', (tester) async {
    await _open(tester);

    expect(tester.takeException(), isNull);
    expect(find.text('مصرف در تولید'), findsOneWidget);
    expect(find.textContaining('120'), findsOneWidget);
    // A figure nobody's name is on cannot be asked about.
    expect(find.textContaining('عبدالناصر'), findsOneWidget);
    expect(find.textContaining('۰۹:۱۴'), findsOneWidget);
    expect(find.text('پخت صبح'), findsOneWidget);
  });

  testWidgets('it says which stretch it is showing', (tester) async {
    // A list of entries read against the wrong dates is worse than none.
    await _open(tester, subtitle: '۱۴۰۵/۰۶/۲۱');

    expect(find.text('۱۴۰۵/۰۶/۲۱'), findsOneWidget);
  });

  testWidgets('opened off a balance it asks for no dates', (tester) async {
    // «آخرین گردش‌ها» must show something without the owner first naming
    // a range — that is the whole point of tapping the number.
    final wire = await _open(tester);

    expect(wire.seen.single, contains('/inventory/movements'));
    expect(wire.seen.single, isNot(contains('from=')));
  });

  testWidgets('opened off a day it asks for that day', (tester) async {
    final wire = await _open(tester, from: '2026-09-12', to: '2026-09-12');

    expect(wire.seen.single, contains('from=2026-09-12'));
    expect(wire.seen.single, contains('to=2026-09-12'));
  });

  testWidgets('a stretch with nothing in it says so', (tester) async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(_Wire('{"success":true,"data":{"data":[]}}'));

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: InventoryEntriesSheet(
          api: BakeryApi(client),
          itemKey: 'salt',
          itemName: 'نمک',
          subtitle: 'آخرین گردش‌ها',
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.textContaining('حرکتی ثبت نشده'), findsOneWidget);
  });

  testWidgets('an entry with no name still says which record it is',
      (tester) async {
    await _open(
      tester,
      rows: '{"id":9,"direction":"in","quantity":40,"reason":"purchase",'
          '"reason_label":"خرید","created_at_display":"۱۴۰۵/۰۶/۲۰ ۱۰:۰۰"}',
    );

    expect(tester.takeException(), isNull);
    expect(find.text('خرید'), findsOneWidget);
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.body);

  final String body;

  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.uri}');

    return ResponseBody.fromString(body, 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}
