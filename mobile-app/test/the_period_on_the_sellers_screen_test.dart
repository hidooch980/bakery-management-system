import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/screens/seller/seller_workbench.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// «نان دوره، سهمیه دوره، فروش کارتخوان، باقی‌مانده — در کارتابل اپلیکیشن
/// فروشنده نمایش بده».
///
/// All four already came back from `/flour-allocations/current`, which the
/// seller has had permission to read since it was written. Nothing on
/// their screen ever asked for it, so the person watching the card reader
/// all day was the one person who could not see what it added up to.
class _Canned implements HttpClientAdapter {
  _Canned(this.byPath);

  final Map<String, Object?> byPath;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final keys = byPath.keys.toList()
      ..sort((a, b) => b.length.compareTo(a.length));

    final match = keys
        .where((k) => options.path.contains(k))
        .map((k) => byPath[k])
        .firstOrNull;

    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': match}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Map<String, Object?> _allocation({
  required int allocated,
  required int sold,
  String label = 'دوره سوم',
  bool withWholePeriod = true,
}) =>
    {
      'periods': [
        {
          'number': 1,
          'label': 'دوره اول',
          'is_current': false,
          'allocated_bread_count': 9999,
          'card_bread_count': 9999,
          'bread_remainder': 0,
        },
        {
          'number': 3,
          'label': label,
          'is_current': true,
          'allocated_bread_count': allocated,
          'card_bread_count': sold,
          'bread_remainder': allocated - sold,
        },
      ],
      // Shaped like a period, because the server sums it off those very
      // periods and the app draws it with the same card.
      if (withWholePeriod)
        'whole_period': {
          'number': 0,
          'label': 'کل دوره (۵ تا ۴ ماه بعد)',
          'is_current': false,
          'allocated_bread_count': 23295,
          'card_bread_count': 19781,
          'bread_remainder': 3514,
        },
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<void> pump(WidgetTester tester, Object? allocation) async {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = _Canned({
      '/flour-allocations/current': allocation,
      // Everything else the workbench asks for, answered emptily so the
      // page builds and this test is about the quota alone.
      '/': const <String, Object?>{},
    });

    await tester.pumpWidget(
      MaterialApp(
        theme: ThemeData.dark(),
        home: Scaffold(
          body: SingleChildScrollView(
            child: SellerWorkbench(
              api: BakeryApi(client),
              onChanged: () {},
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('the four figures are on the seller\'s page', (tester) async {
    await pump(tester, _allocation(allocated: 24000, sold: 19600));

    // One heading, and the three labels once per card — the running
    // period and the three added up.
    expect(find.text('سهمیه دوره'), findsOneWidget);
    expect(find.text('نان دوره'), findsNWidgets(2));
    expect(find.text('فروش کارتخوان'), findsNWidgets(2));
    expect(find.text('باقی‌مانده'), findsNWidgets(2));

    // The running period's own figures.
    expect(find.text('24000'), findsOneWidget);
    expect(find.text('19600'), findsOneWidget);
    expect(find.text('4400 نان'), findsOneWidget);
  });

  testWidgets('it reads the period that is running, not the first one',
      (tester) async {
    // Three delivery periods come back together and only one is current.
    // Taking `periods.first` would show a finished period's figures and
    // read as this one's.
    await pump(tester, _allocation(allocated: 24000, sold: 19600));

    expect(find.text('9999'), findsNothing);
  });

  testWidgets('going over the quota says so instead of showing a minus',
      (tester) async {
    await pump(tester, _allocation(allocated: 24000, sold: 24400));

    // «-۴۰۰ نان باقی‌مانده» is a sentence nobody can act on.
    expect(find.text('بیش از سهمیه'), findsOneWidget);
    expect(find.text('400 نان'), findsOneWidget);
    expect(find.textContaining('-'), findsNothing);

    // The card below is a different sum and is not over: the two cards
    // answer for themselves rather than sharing one verdict.
    expect(find.text('باقی‌مانده'), findsOneWidget);
  });

  testWidgets('a shop with no quota recorded shows nothing, not an error',
      (tester) async {
    await pump(tester, null);

    // A red box above the day's work would be read as something being
    // wrong, when nothing is.
    expect(find.text('سهمیه دوره'), findsNothing);
  });

  testWidgets('the whole period is on the page too', (tester) async {
    // «برای فروشنده دوباره کل دوره نمایش بده». The owner has had this on
    // their screen for a while; the seller had to add it up off three
    // numbers nobody showed them.
    await pump(tester, _allocation(allocated: 24000, sold: 19600));

    expect(find.text('کل دوره (۵ تا ۴ ماه بعد)'), findsOneWidget);
    expect(find.text('23295'), findsOneWidget);
    expect(find.text('19781'), findsOneWidget);
    expect(find.text('3514 نان'), findsOneWidget);
  });

  testWidgets('the total is told apart from the period above it',
      (tester) async {
    // Two cards in a column read as two periods — or worse, as the
    // second correcting the first — unless something between them says
    // what the second one is.
    await pump(tester, _allocation(allocated: 24000, sold: 19600));

    final rule = find.text('کل دوره (۵ تا ۴ ماه بعد)');
    final running = find.text('دوره سوم');

    expect(rule, findsOneWidget);
    expect(
      tester.getCenter(rule).dy,
      greaterThan(tester.getCenter(running).dy),
      reason: 'جمعِ سه دوره باید زیر دورهٔ جاری بیاید، نه بالایش.',
    );
  });

  testWidgets('a quota with no total yet shows the period alone',
      (tester) async {
    // A shop whose allocation has no periods gets no total back. The
    // running card must still stand on its own rather than the section
    // disappearing with it.
    await pump(
      tester,
      _allocation(allocated: 24000, sold: 19600, withWholePeriod: false),
    );

    expect(find.text('سهمیه دوره'), findsOneWidget);
    expect(find.text('نان دوره'), findsOneWidget);
    expect(find.text('کل دوره (۵ تا ۴ ماه بعد)'), findsNothing);
  });

  testWidgets('the period label is shown so the figures are dateable',
      (tester) async {
    await pump(
      tester,
      _allocation(allocated: 24000, sold: 100, label: 'دوره سوم'),
    );

    // Four numbers with no period beside them are four numbers about an
    // unknown fortnight.
    expect(find.text('دوره سوم'), findsOneWidget);
  });
}
