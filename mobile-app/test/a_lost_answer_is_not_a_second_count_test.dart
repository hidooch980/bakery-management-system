import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// جوابی که گم شود، نباید به دو شمارش تبدیل شود.
///
/// دو شکستِ ممکن — timeout فرستادن و timeout گرفتن — یعنی درخواست به سرور
/// رسیده و به احتمال زیاد اجرا هم شده، و فقط جوابش گم شده. دکمه دوباره
/// فعال می‌شود و مالک دوباره می‌زند. بدون نام، سرور آن تلاش دوم را یک
/// شمارش تازه می‌بیند — و اگر کلید «اصلاح» روشن باشد، **دو بار** پول یا
/// آرد جابه‌جا می‌کند.
///
/// همین استدلال در `ApiClient.postOrQueue` نوشته شده بود و به مسیرهایی که
/// عمداً صف نمی‌شوند نرسیده بود.
///
/// آنچه اینجا ثابت می‌شود، قرارداد لایهٔ API است: نامی که داده شود روی سیم
/// می‌رود، و همان نام در تلاش دوم همان می‌ماند. اینکه صفحه نام را یک بار
/// می‌زند و نگه می‌دارد، یک `final` در خودِ صفحه است.
class _Wire implements HttpClientAdapter {
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions o,
    Stream<Uint8List>? s,
    Future<void>? c,
  ) async {
    seen.add(o);

    return ResponseBody.fromString(
      jsonEncode({
        'success': true,
        'data': {
          'id': 1,
          'difference_label': 'می‌خواند',
          'is_exact': true,
          'adjusted': false,
          'counted_at': '۱۴۰۵/۰۶/۲۳',
        },
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Wire wire;
  late BakeryApi api;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    final client = ApiClient(baseUrl: 'http://test.local');
    wire = _Wire();
    client.transport = wire;
    api = BakeryApi(client);
  });

  List<String?> keysFor(String path) => wire.seen
      .where((r) => r.path == path && r.method == 'POST')
      .map((r) => r.headers['Idempotency-Key'] as String?)
      .toList();

  test('a cash count carries the name it was given', () async {
    await api.recordCashCount(countedAmount: 100, attemptKey: 'k-1');

    expect(keysFor('/cash-counts'), ['k-1']);
  });

  test('the retry of one cash count carries the same name', () async {
    // همان چیزی که پس از یک جوابِ گم‌شده اتفاق می‌افتد: صفحه باز است، نام
    // عوض نشده، مالک دوباره می‌زند.
    for (var i = 0; i < 2; i++) {
      await api.recordCashCount(
        countedAmount: 100,
        adjust: true,
        attemptKey: 'k-same',
      );
    }

    expect(keysFor('/cash-counts'), ['k-same', 'k-same']);
  });

  test('a stock count carries the name it was given', () async {
    await api.recordStockCount(counted: 113, attemptKey: 'k-2');

    expect(keysFor('/stock-counts'), ['k-2']);
  });

  test('the retry of one stock count carries the same name', () async {
    for (var i = 0; i < 2; i++) {
      await api.recordStockCount(
        counted: 113,
        adjust: true,
        attemptKey: 'k-same',
      );
    }

    expect(keysFor('/stock-counts'), ['k-same', 'k-same']);
  });

  test('two separate countings are two different names', () async {
    // نام برای «همین یک نوشتن» است، نه برای همیشه. اگر مالک صفحه را ببندد
    // و دوباره باز کند، واقعاً شمارش دومی در کار است و باید ثبت شود.
    await api.recordCashCount(countedAmount: 100, attemptKey: 'first');
    await api.recordCashCount(countedAmount: 90, attemptKey: 'second');

    expect(keysFor('/cash-counts'), ['first', 'second']);
  });

  test('the sheets are the ones that pass a name', () {
    // این همان چیزی است که قبل از امروز غایب بود: قرارداد وجود داشت و
    // هیچ صفحه‌ای از آن استفاده نمی‌کرد، پس کل محافظ مرده بود.
    for (final path in const [
      'lib/screens/admin/cash_count_sheet.dart',
      'lib/screens/admin/stock_count_sheet.dart',
    ]) {
      final source = File(path).readAsStringSync();

      expect(source, contains('Uuid().v4()'),
          reason: '$path must mint a name');
      expect(source, contains('attemptKey: _attempt'),
          reason: '$path must send the name it minted');
      expect(source, contains('final String _attempt'),
          reason: '$path must hold the name, so a retry reuses it');
    }
  });
}
