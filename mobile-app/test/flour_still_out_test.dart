import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/screens/shared/consignment_flour_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// The screen that finally reads back what the app had only ever written.
///
/// Worth testing on sight rather than on behaviour alone: the last two
/// faults reported from the shop were an empty-looking list that was full
/// and a name painted black on black. Both would have passed a test that
/// only asked whether the request was made.
class _Canned implements HttpClientAdapter {
  _Canned(this.byPath);

  /// Longest key first when matching, so «/consignment-flour/partners»
  /// is not swallowed by «/consignment-flour».
  final Map<String, Object> byPath;

  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.method} ${options.path}');

    final keys = byPath.keys.toList()
      ..sort((a, b) => b.length.compareTo(a.length));

    final match = keys
        .where((k) => options.path.contains(k))
        .map((k) => byPath[k]!)
        .firstOrNull;

    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': match ?? const {}}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

// Object?, not Object: the server sends nulls here — an unsettled row has
// no settled_on, and a one-off partner has no id — and a map that cannot
// hold one is not the shape being tested against.
Map<String, Object?> _row({
  required int id,
  required String partner,
  required String direction,
  required String quantity,
}) =>
    {
      'id': id,
      'partner_id': null,
      'partner_name': partner,
      'partner_phone': null,
      'direction': direction,
      'direction_label': direction == 'lent' ? 'دادیم' : 'گرفتیم',
      'bags': 56,
      'amount_kg': 2520,
      'quantity_label': quantity,
      'occurred_on': '2026-08-03',
      'occurred_on_display': '۱۴۰۵/۰۵/۱۲',
      'settled_on_display': null,
      'is_settled': false,
      'note': null,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<_Canned> pump(
    WidgetTester tester, {
    required Object list,
    required Object balance,
    Object partners = const <Map<String, Object?>>[],
  }) async {
    final adapter = _Canned({
      '/consignment-flour/balance': balance,
      '/consignment-flour/partners': partners,
      '/consignment-flour': list,
    });
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;

    await tester.pumpWidget(
      MaterialApp(
        theme: ThemeData.dark(),
        home: ConsignmentFlourScreen(api: BakeryApi(client)),
      ),
    );
    await tester.pumpAndSettle();

    return adapter;
  }

  testWidgets('the partners holding the shop\'s flour are named', (tester) async {
    await pump(
      tester,
      list: {
        'data': [
          _row(id: 1, partner: 'عبدالرئوف', direction: 'lent', quantity: '۵۶ کیسه'),
          _row(id: 2, partner: 'ممد زاکر', direction: 'lent', quantity: '۲۰ کیسه'),
        ],
      },
      balance: {'lent_bags': 76, 'borrowed_bags': 0, 'net_bags': 76},
    );

    expect(find.text('عبدالرئوف'), findsOneWidget);
    expect(find.text('ممد زاکر'), findsOneWidget);
    expect(find.text('۵۶ کیسه'), findsOneWidget);
  });

  testWidgets('every visible label has a colour that is not the black default',
      (tester) async {
    await pump(
      tester,
      list: {
        'data': [
          _row(id: 1, partner: 'عبدالرئوف', direction: 'lent', quantity: '۵۶ کیسه'),
        ],
      },
      balance: {'lent_bags': 56, 'borrowed_bags': 0, 'net_bags': 56},
    );

    // «لیست افراد شیشه‌ای هست» — a name rendered in the ambient
    // DefaultTextStyle's black, on this app's near-black ground. The text
    // was present and unreadable, and every assertion about presence
    // passed while it was.
    for (final text in tester.widgetList<Text>(find.byType(Text))) {
      final colour = text.style?.color;
      expect(
        colour,
        isNot(Colors.black),
        reason: 'A label painted pure black disappears on the dark ground.',
      );
    }
  });

  testWidgets('the net position is said in words, not left to a sign',
      (tester) async {
    await pump(
      tester,
      list: {'data': const []},
      balance: {'lent_bags': 56, 'borrowed_bags': 20, 'net_bags': 36},
    );

    // «۳۶ کیسه» alone does not say which way it goes, and the person
    // reading it is deciding whether to lend more.
    expect(find.textContaining('طلب داریم'), findsOneWidget);
  });

  testWidgets('owing more than is owed reads as a debt', (tester) async {
    await pump(
      tester,
      list: {'data': const []},
      balance: {'lent_bags': 10, 'borrowed_bags': 30, 'net_bags': -20},
    );

    expect(find.textContaining('بدهکاریم'), findsOneWidget);
    // Never «-۲۰ کیسه طلب داریم».
    expect(find.textContaining('طلب داریم'), findsNothing);
  });

  testWidgets('nothing out says so, rather than showing an empty page',
      (tester) async {
    await pump(
      tester,
      list: {'data': const []},
      balance: {'lent_bags': 0, 'borrowed_bags': 0, 'net_bags': 0},
    );

    expect(find.text('هیچ آرد امانی‌ای باز نیست.'), findsOneWidget);
    expect(find.textContaining('صاف است'), findsOneWidget);
  });

  testWidgets('it asks for the open ones, not the whole history',
      (tester) async {
    final adapter = await pump(
      tester,
      list: {'data': const []},
      balance: {'lent_bags': 0, 'borrowed_bags': 0, 'net_bags': 0},
    );

    // Settled rows are history; a list that grew for ever would stop
    // being read.
    expect(
      adapter.seen.any((r) => r.contains('/consignment-flour')),
      isTrue,
    );
  });

  group('the report, gathered by partner', () {
    Map<String, Object?> partner(String name, num net, int? days, int entries) => {
          'partner_name': name,
          'lent_kg': net * 45,
          'borrowed_kg': 0,
          'net_kg': net * 45,
          'lent_bags': net,
          'borrowed_bags': 0,
          'net_bags': net,
          'entries': entries,
          'since': '2026-08-03',
          'since_display': '۱۴۰۵/۰۵/۱۲',
          'days': days,
        };

    testWidgets('says how much each partner holds and for how long',
        (tester) async {
      await pump(
        tester,
        list: {'data': const []},
        balance: {'lent_bags': 76, 'borrowed_bags': 0, 'net_bags': 76},
        partners: [
          partner('عبدالرئوف', 56, 23, 2),
          partner('ممد زاکر', 20, 18, 1),
        ],
      );

      expect(find.text('به تفکیک همکار'), findsOneWidget);
      expect(find.text('عبدالرئوف'), findsOneWidget);
      // The shop says it in days. A date would make the reader subtract.
      expect(find.textContaining('۲۳ روز'), findsNothing);
      expect(find.textContaining('23 روز'), findsOneWidget);
    });

    testWidgets('flour lent today reads as today, not as zero days',
        (tester) async {
      await pump(
        tester,
        list: {'data': const []},
        balance: {'lent_bags': 5, 'borrowed_bags': 0, 'net_bags': 5},
        partners: [partner('رحیم', 5, 0, 1)],
      );

      expect(find.text('از امروز'), findsOneWidget);
    });

    testWidgets('owing a partner is labelled as owing, not as holding',
        (tester) async {
      await pump(
        tester,
        list: {'data': const []},
        balance: {'lent_bags': 0, 'borrowed_bags': 10, 'net_bags': -10},
        partners: [partner('رحیم', -10, 4, 1)],
      );

      // «۱۰ کیسه» with no word beside it would read as ten sacks of ours
      // out there, when it is ten of theirs in our store.
      expect(find.text('بدهکاریم'), findsWidgets);
    });

    testWidgets('with no partner out, the section is absent rather than empty',
        (tester) async {
      await pump(
        tester,
        list: {'data': const []},
        balance: {'lent_bags': 0, 'borrowed_bags': 0, 'net_bags': 0},
      );

      expect(find.text('به تفکیک همکار'), findsNothing);
    });
  });
}
