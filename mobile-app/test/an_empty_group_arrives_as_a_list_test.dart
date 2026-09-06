import 'dart:io';
import 'dart:typed_data';

import 'package:bakery_app/screens/admin/sales_breakdown_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/utils/json.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The grey rectangle on the owner's finance screen.
///
/// He photographed it: half a screen tall, blank, under «تفکیک فروش», with
/// every other figure on the page correct. It was a cast.
///
/// PHP has one type for «list» and «dictionary» and `json_encode` picks
/// `[]` or `{}` by looking at the keys, so `by_payment_type` is an object
/// on every day the shop sold something and an empty array on the days it
/// did not. `as Map?` throws on that array, the throw lands while the
/// widget is building, and the release-mode `ErrorWidget` paints a plain
/// grey rectangle with no message anywhere.
///
/// Four sections read a keyed group that way. All four could do this, on
/// any range with nothing in it, and none of them would have said why.
void main() {
  group('a group with nothing in it', () {
    test('arrives as an empty list and reads as empty', () {
      // Verbatim what Laravel sends for `$sales->groupBy(...)` with no
      // sales in the range.
      expect(keyedGroup(const []), isEmpty);
    });

    test('or as an empty object, which it is on any other day', () {
      expect(keyedGroup(const <String, dynamic>{}), isEmpty);
    });

    test('or is simply absent', () {
      expect(keyedGroup(null), isEmpty);
    });
  });

  test('a group with something in it is read, and keeps its keys', () {
    final group = keyedGroup(const {
      'cash': {'label': 'نقدی', 'amount': 500000},
      'credit': {'label': 'نسیه', 'amount': 120000},
    });

    expect(group.keys, containsAll(['cash', 'credit']));
    expect((group['cash'] as Map)['label'], 'نقدی');
  });

  test('a shape nobody expects is nothing, rather than a crash', () {
    // The point is not that a list of rows is meaningful here. It is that
    // one surprising payload must not take a section off the screen with
    // no way to tell that it did.
    expect(keyedGroup(const [1, 2, 3]), isEmpty);
    expect(keyedGroup('پیام'), isEmpty);
  });

  testWidgets('the section draws rather than becoming a grey rectangle',
      (tester) async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    // A range with no sales in it, exactly as Laravel sends it.
    client.useAdapterForTest(_Wire(
      '{"success":true,"data":{"count":0,"bread_count":0,'
      '"total_amount":0,"total_amount_formatted":"۰ ریال",'
      '"by_payment_type":[],"by_seller":[]}}',
    ));

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SalesBreakdownSection(
          api: BakeryApi(client),
          from: '2026-09-01',
          to: '2026-09-06',
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('تفکیک فروش'), findsOneWidget);
    expect(find.textContaining('فروشی ثبت نشده'), findsOneWidget);
  });

  group('rows arrive in more than one shape too', () {
    test('a list of rows is read as it is', () {
      expect(
        rowList(const [
          {'label': 'نقدی'},
          {'label': 'نسیه'},
        ]).map((r) => r['label']),
        ['نقدی', 'نسیه'],
      );
    });

    test('a keyed group read as rows keeps its values', () {
      // The same collection, from an endpoint that did not call
      // `->values()` on it. `as List?` threw here; the key is already
      // inside each row where it matters.
      expect(
        rowList(const {
          'cash': {'label': 'نقدی'},
          'credit': {'label': 'نسیه'},
        }).map((r) => r['label']),
        ['نقدی', 'نسیه'],
      );
    });

    test('nothing, in any of the ways nothing arrives', () {
      expect(rowList(const []), isEmpty);
      expect(rowList(const <String, dynamic>{}), isEmpty);
      expect(rowList(null), isEmpty);
    });

    test('one malformed entry does not take the section down', () {
      // Dropped rather than cast: a section is worth more with a row
      // missing than replaced entirely by a grey rectangle.
      expect(
        rowList(const [
          {'label': 'نقدی'},
          'پیام',
          null,
        ]),
        hasLength(1),
      );
    });
  });

  test('no screen casts a server list or group by hand any more', () {
    // The sweep, pinned. Both spellings threw on the shape they did not
    // expect, and in a release build the throw is a plain grey rectangle
    // with no message — which took a photograph and a round of questions
    // to place. A new one should be caught here instead.
    final offenders = <String>[];

    for (final dir in ['lib/screens', 'lib/widgets']) {
      for (final file in Directory(dir)
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('.dart'))) {
        final source = file.readAsStringSync();

        for (final line in source.split('\n')) {
          // Both spellings, and this is the second version of this
          // pattern: the first read `as Map?` and `as List?` and missed
          // `as Map<String, dynamic>?` entirely — eighteen more sites,
          // several of them without even a `?`, so they threw on an
          // absent field and not only on a surprising one. A rule that
          // catches most of a class is how the class survives.
          if (RegExp(r"\[[^\]]+\]\s+as\s+(List|Map)\s*(<[^>]*>)?\??[\s)]")
              .hasMatch(line)) {
            offenders.add('${file.path}: ${line.trim()}');
          }
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'Read it through rowList or keyedGroup, which survive the '
          'shape the server actually sends.',
    );
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

