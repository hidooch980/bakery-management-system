import 'dart:typed_data';

import 'package:bakery_app/screens/admin/warehouse_journey_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The warehouse, on the phone, answering «کجا رفت».
///
/// The tab has only ever shown what is left. Every other part of the shop
/// had a report; the warehouse had a list of balances, which answers «چقدر
/// داریم» and never where any of it went.
Future<_Wire> _pump(WidgetTester tester, String data) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  final wire = _Wire('{"success":true,"data":$data}');
  client.useAdapterForTest(wire);

  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: SingleChildScrollView(
        child: WarehouseJourneySection(api: BakeryApi(client)),
      ),
    ),
  ));
  await tester.pumpAndSettle();

  return wire;
}

String _flour({
  num opening = 100,
  num inKg = 400,
  num outKg = 160,
  num closing = 340,
  bool balances = true,
  String? bags,
}) =>
    '{"key":"flour","name":"آرد","unit":"کیلوگرم",'
    '"opening_kg":$opening,"closing_kg":$closing,'
    '"in_kg":$inKg,"out_kg":$outKg,'
    '"opening_bags":${bags ?? 'null'},"closing_bags":${bags ?? 'null'},'
    '"in_bags":null,"out_bags":null,'
    '"balances":$balances,'
    '"out":[{"reason":"production","label":"مصرف در تولید","kg":120,'
    '"bags":null,"share":75},'
    '{"reason":"flour_sale","label":"فروش آرد","kg":40,"bags":null,"share":25}],'
    '"in":[{"reason":"purchase","label":"خرید","kg":400,"bags":null,"share":100}]}';

void main() {
  testWidgets('it names each destination the stock went to', (tester) async {
    await _pump(tester, '{"items":[${_flour()}]}');

    expect(tester.takeException(), isNull);
    expect(find.text('آرد'), findsOneWidget);
    expect(find.text('مصرف در تولید'), findsOneWidget);
    expect(find.text('فروش آرد'), findsOneWidget);
    expect(find.text('خرید'), findsOneWidget);
  });

  testWidgets('it reads opening, in, out and what is left', (tester) async {
    await _pump(tester, '{"items":[${_flour()}]}');

    expect(find.textContaining('اول دوره'), findsOneWidget);
    expect(find.textContaining('آمد'), findsOneWidget);
    expect(find.textContaining('رفت'), findsOneWidget);
  });

  testWidgets('a good with a sack size is counted in sacks', (tester) async {
    // «کیلو در انبار معنی نداره، فقط کیسه بیاد» — the same rule the
    // balances above this section follow.
    await _pump(tester, '{"items":[${_flour(bags: "8.5")}]}');

    expect(find.textContaining('کیسه'), findsWidgets);
  });

  testWidgets('a row that does not add up says so', (tester) async {
    // A report that quietly drops stock is worse than none.
    await _pump(tester, '{"items":[${_flour(balances: false)}]}');

    expect(find.textContaining('جمع نمی‌خورد'), findsOneWidget);
  });

  testWidgets('a row that adds up says nothing about it', (tester) async {
    await _pump(tester, '{"items":[${_flour()}]}');

    expect(find.textContaining('جمع نمی‌خورد'), findsNothing);
  });

  testWidgets('a good that neither moved nor has stock is left out',
      (tester) async {
    await _pump(
      tester,
      '{"items":[{"key":"salt","name":"نمک","unit":"کیلوگرم",'
      '"opening_kg":0,"closing_kg":0,"in_kg":0,"out_kg":0,'
      '"balances":true,"in":[],"out":[]}]}',
    );

    expect(find.text('نمک'), findsNothing);
    expect(find.textContaining('چیزی وارد یا خارج نشد'), findsOneWidget);
  });

  testWidgets('changing the window asks the server again', (tester) async {
    // The arithmetic is the server's. A second opinion on where the flour
    // went would be worse than one answer.
    final wire = await _pump(tester, '{"items":[${_flour()}]}');
    final before = wire.seen.length;

    await tester.tap(find.text('۷ روز'));
    await tester.pumpAndSettle();

    expect(wire.seen.length, greaterThan(before));
    expect(wire.seen.last, contains('/reports/inventory'));
  });

  testWidgets('a report that will not load does not take the tab down',
      (tester) async {
    await _pump(tester, '"not a map"');

    expect(tester.takeException(), isNull);
    expect(find.text('گردش انبار'), findsOneWidget);
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
    seen.add('${options.method} ${options.uri}');

    return ResponseBody.fromString(body, 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}
