import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// سمتِ گوشیِ شمارش انبار.
///
/// حساب‌وکتابش تست خودش را روی سرور دارد. آنچه آن‌ها نشان نمی‌دهند، همان
/// نیمی است که این را روی گوشی ارزشمند می‌کند: اینکه عددِ مقایسه زنده
/// خوانده می‌شود نه از کش، اینکه «کسری» همان‌طور که سرور گفته به صفحه
/// می‌رسد و از روی یک علامت دوباره ساخته نمی‌شود، و اینکه شمارش هیچ‌وقت
/// صف نمی‌شود — شمارشی که فردا صبح فرستاده شود با دفتر فردا مقایسه
/// می‌شود، که اختلافی است ساختهٔ خودِ تأخیر.
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

  Map<String, dynamic> quantity(double bags, double kg) => {
        'base': kg,
        'base_unit': 'کیلوگرم',
        'bags': bags,
        'bag_weight_kg': 40,
      };

  test('the store reads what to count against, and how long it has been',
      () async {
    final it = apiThat((_) => _ok({
          'item': {'key': 'flour', 'name': 'آرد', 'unit': 'کیلوگرم'},
          'expected': quantity(113, 4520),
          'first_count': false,
          'last_counted_at': '۱۴۰۵/۰۶/۲۳',
          'days_since_count': 4,
          'counts': const [],
        }));

    final book = await it.api.stockCounts();

    expect(book.itemName, 'آرد');
    expect(book.expectedLabel, '113 کیسه');
    expect(book.expectedValue, 113);
    expect(book.unitLabel, 'کیسه');
    expect(book.daysSinceCount, 4);
    expect(book.neverCounted, isFalse);
  });

  test('a store that has never been counted says so rather than showing a date',
      () async {
    final it = apiThat((_) => _ok({
          'item': {'key': 'flour', 'name': 'آرد', 'unit': 'کیلوگرم'},
          'expected': quantity(113, 4520),
          'first_count': true,
          'last_counted_at': null,
          'counts': const [],
        }));

    expect((await it.api.stockCounts()).neverCounted, isTrue);
  });

  test('a good sold by weight reads in its own unit, not in sacks', () async {
    // نمک کیسه‌ای نیست. صفحه نباید «۳ کیسه نمک» بپرسد.
    final it = apiThat((_) => _ok({
          'item': {'key': 'salt', 'name': 'نمک', 'unit': 'کیلوگرم'},
          'expected': {
            'base': 120.0,
            'base_unit': 'کیلوگرم',
            'bags': null,
            'bag_weight_kg': null,
          },
          'counts': const [],
        }));

    final book = await it.api.stockCounts(item: 'salt');

    expect(book.unitLabel, 'کیلوگرم');
    expect(book.expectedLabel, '120 کیلوگرم');
    expect(book.expectedValue, 120);
  });

  test('the figure to count against is read live, not from the cache',
      () async {
    final it = apiThat((_) => _ok({
          'item': {'key': 'flour', 'name': 'آرد', 'unit': 'کیلوگرم'},
          'expected': quantity(113, 4520),
          'counts': const [],
        }));

    await it.api.stockCounts();
    await it.api.stockCounts();

    // موجودیِ به‌یادسپرده یعنی کسی در برابر عدد دیروز بشمارد و کسری‌ای
    // پیدا کند که فقط کش است.
    expect(it.wire.seen.where((r) => r.path == '/stock-counts').length, 2);
  });

  test('a count is sent, never queued', () async {
    final it = apiThat((_) => _ok({
          'id': 9,
          'difference_label': 'می‌خواند',
          'is_exact': true,
          'adjusted': false,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
        }));

    await it.api.recordStockCount(counted: 113);

    final sent = it.wire.seen.single;

    expect(sent.method, 'POST');
    expect(sent.path, '/stock-counts');
    expect((sent.data as Map)['counted'], 113);
    expect((sent.data as Map)['item'], 'flour');
  });

  test('correcting the books is asked for, never assumed', () async {
    final it = apiThat((_) => _ok({
          'id': 9,
          'difference_label': 'می‌خواند',
          'is_exact': true,
          'adjusted': false,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
        }));

    await it.api.recordStockCount(counted: 113);
    expect((it.wire.seen.single.data as Map)['adjust'], isNull);

    it.wire.seen.clear();

    await it.api.recordStockCount(counted: 113, adjust: true);
    expect((it.wire.seen.single.data as Map)['adjust'], isTrue);
  });

  test('the gap reaches the screen as the server said it', () async {
    // دوباره ساختنش از روی علامت، یعنی دو جا تصمیم بگیرند منفی یعنی چه.
    final it = apiThat((_) => _ok({
          'id': 9,
          'counted': quantity(105, 4200),
          'expected': quantity(113, 4520),
          'difference': quantity(8, 320),
          'difference_label': 'کسری',
          'is_exact': false,
          'adjusted': true,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
          'counted_by': 'عبدالناصر',
        }));

    final count = await it.api.recordStockCount(counted: 105);

    expect(count.differenceLabel, 'کسری');
    expect(count.differenceAmount, '8 کیسه');
    expect(count.countedLabel, '105 کیسه');
    expect(count.adjusted, isTrue);
    expect(count.countedBy, 'عبدالناصر');
  });

  test('a count with fields missing does not throw', () async {
    // سرور قدیمی‌تر از اپ، یا نامی که عوض شده. یک ردیف خالی چیزی است که
    // می‌شود از رویش رد شد؛ یک خطا، کل صفحه را می‌برد.
    final it = apiThat((_) => _ok({
          'counts': [
            {'id': 1},
            'not a row',
          ],
        }));

    final book = await it.api.stockCounts();

    expect(book.counts.single.id, 1);
    expect(book.counts.single.differenceLabel, '');
    expect(book.neverCounted, isTrue);
    expect(book.expectedValue, 0);
  });
}
