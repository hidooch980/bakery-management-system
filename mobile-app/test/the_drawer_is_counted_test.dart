import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// The phone side of counting the drawer.
///
/// The server has its own tests for the arithmetic. What those cannot show
/// is the half that makes this worth having on a phone: that the figure
/// being counted against is read live rather than out of the cache, that
/// «کسری» reaches the screen as the server said it and is not re-derived
/// from a sign, and that a count is never queued for later — one sent
/// tomorrow morning would be compared against tomorrow's ledger, which is
/// a gap invented by the delay.
class _Wire implements HttpClientAdapter {
  _Wire(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);

    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _ok(Object data) => ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  ({BakeryApi api, _Wire wire}) apiThat(
    ResponseBody Function(RequestOptions) answer,
  ) {
    final client = ApiClient(baseUrl: 'http://test.local');
    final wire = _Wire(answer);
    client.transport = wire;

    return (api: BakeryApi(client), wire: wire);
  }

  test('the drawer reads what to count against, and how long it has been',
      () async {
    final it = apiThat((_) => _ok({
          'expected_formatted': '۵٬۰۰۰٬۰۰۰ تومان',
          'last_counted_at': '۱۴۰۵/۰۶/۲۰',
          'days_since_count': 3,
          'counts': [
            {
              'id': 7,
              'counted_formatted': '۴٬۸۰۰٬۰۰۰ تومان',
              'expected_formatted': '۵٬۰۰۰٬۰۰۰ تومان',
              'difference_formatted': '۲۰۰٬۰۰۰ تومان',
              'difference_label': 'کسری',
              'is_exact': false,
              'adjusted': true,
              'counted_at': '۱۴۰۵/۰۶/۲۰',
              'counted_by': 'عبدالناصر',
              'note': 'پول خرد',
            },
          ],
        }));

    final book = await it.api.cashCounts();

    expect(book.expectedFormatted, '۵٬۰۰۰٬۰۰۰ تومان');
    expect(book.daysSinceCount, 3);
    expect(book.neverCounted, isFalse);
    expect(book.counts.single.differenceLabel, 'کسری');
    expect(book.counts.single.adjusted, isTrue);
  });

  test('a shop that has never counted says so rather than showing a date',
      () async {
    final it = apiThat((_) => _ok({
          'expected_formatted': '۵٬۰۰۰٬۰۰۰ تومان',
          'last_counted_at': null,
          'days_since_count': null,
          'counts': [],
        }));

    final book = await it.api.cashCounts();

    expect(book.neverCounted, isTrue);
    expect(book.counts, isEmpty);
  });

  test('the figure to count against is read live, not from the cache',
      () async {
    // A remembered balance would have somebody counting against yesterday
    // and finding a gap that is only the cache.
    final it = apiThat((_) => _ok({
          'expected_formatted': '۱ تومان',
          'counts': const [],
        }));

    await it.api.cashCounts();
    await it.api.cashCounts();

    expect(it.wire.seen.where((r) => r.path == '/cash-counts').length, 2);
  });

  test('a count is sent, never queued', () async {
    // Queued, it would arrive tomorrow and be compared against tomorrow's
    // ledger — a gap invented by the delay.
    final it = apiThat((_) => _ok({
          'id': 9,
          'counted_formatted': '۴٬۸۰۰٬۰۰۰ تومان',
          'expected_formatted': '۵٬۰۰۰٬۰۰۰ تومان',
          'difference_formatted': '۲۰۰٬۰۰۰ تومان',
          'difference_label': 'کسری',
          'is_exact': false,
          'adjusted': false,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
        }));

    final count = await it.api.recordCashCount(countedAmount: 4800000);

    final sent = it.wire.seen.single;
    expect(sent.method, 'POST');
    expect(sent.path, '/cash-counts');
    expect(count.differenceLabel, 'کسری');
    expect(count.isExact, isFalse);
  });

  test('correcting the books is asked for, never assumed', () async {
    final it = apiThat((_) => _ok({
          'id': 9,
          'difference_label': 'می‌خواند',
          'is_exact': true,
          'adjusted': false,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
        }));

    await it.api.recordCashCount(countedAmount: 5000000);
    expect((it.wire.seen.single.data as Map)['adjust'], isNull);

    it.wire.seen.clear();

    await it.api.recordCashCount(countedAmount: 5000000, adjust: true);
    expect((it.wire.seen.single.data as Map)['adjust'], isTrue);
  });

  test('a count with fields missing does not throw', () async {
    // The server is older than the app, or a field was renamed. An empty
    // row is a row somebody can look past; a crash takes the screen.
    final it = apiThat((_) => _ok({'counts': [
          {'id': 1},
          'not a row',
        ]}));

    final book = await it.api.cashCounts();

    expect(book.counts.single.id, 1);
    expect(book.counts.single.differenceLabel, '');
    expect(book.neverCounted, isTrue);
  });
}
