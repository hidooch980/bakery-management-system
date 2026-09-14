import 'dart:typed_data';

import 'package:bakery_app/screens/admin/admin_overview_tab.dart';
import 'package:bakery_app/screens/admin/admin_warehouse_tab.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/widgets/common.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// یک فراخوانی فرعی که شکست بخورد، صفحه را با خودش نمی‌برد.
///
/// هر دو تب چند چیز را با هم می‌خواستند و همه را در یک `Future.wait`
/// می‌گذاشتند، پس شکست هر کدام یعنی خطای کل تب:
///
///   - در «انبار»، شکست سهمیه، **موجودی انبار** را می‌برد — تنها چیزی که
///     مالک این تب را برایش باز می‌کند. و صفحه از قبل حالت «سهمیه‌ای
///     تعریف نشده» را داشت، پس نبودنش وضعیت شناخته‌شده بود نه خرابی.
///   - در «خلاصه»، شکست تختهٔ چانه — که دو سطر از صفحه است — «امروز» و
///     «صف کاری» و «کارکنان» را با خودش می‌برد.
class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  /// مسیرهایی که ۵۰۰ می‌دهند، مثل سروری که یک سرویسش خراب است.
  final Set<String> broken = {};

  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options.path);

    if (broken.contains(options.path)) {
      return ResponseBody.fromString(
        '{"success":false,"message":"خراب"}',
        500,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );
    }

    return ResponseBody.fromString(
      bodies[options.path] ?? '{"success":true,"data":null}',
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

    wire = _Wire({
      '/inventory': '{"success":true,"data":[{"key":"flour","name":"آرد",'
          '"balance":4520,"balance_bags":113,"unit":"کیلوگرم","is_low":false}]}',
      '/flour-allocations/current': '{"success":true,"data":null}',
      '/flour-sales/today': '{"success":true,"data":{"sales":[],'
          '"summary":{"count":0,"total_weight_kg":0,'
          '"total_amount_formatted":"۰ تومان"}}}',
      '/reports/dashboard': '{"success":true,"data":{"today":{},"queues":{},"staff":{}}}',
      '/chane-board': '{"success":true,"data":{"waiting_chane":12,'
          '"baked_chane":0,"sold_bread":0}}',
    });

    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);
    api = BakeryApi(client);
  });

  Future<void> show(WidgetTester tester, Widget tab) async {
    await tester.pumpWidget(MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: tab),
      ),
    ));

    await tester.pumpAndSettle();
  }

  testWidgets('a broken quota leaves the stock levels standing', (tester) async {
    wire.broken.add('/flour-allocations/current');

    await show(tester, AdminWarehouseTab(api: api));

    // موجودی سر جایش، و نبودن سهمیه با همان حالت شناخته‌شده گفته می‌شود.
    expect(find.text('موجودی انبار'), findsOneWidget);
    expect(find.text('سهمیه‌ای تعریف نشده'), findsOneWidget);
  });

  testWidgets('a broken flour-sales call leaves the stock levels standing',
      (tester) async {
    wire.broken.add('/flour-sales/today');

    await show(tester, AdminWarehouseTab(api: api));

    expect(find.text('موجودی انبار'), findsOneWidget);
  });

  testWidgets('the stock levels failing is still an error', (tester) async {
    // موجودی خودِ صفحه است: اگر این نیاید چیزی برای نشان دادن نمانده.
    wire.broken.add('/inventory');

    await show(tester, AdminWarehouseTab(api: api));

    expect(find.text('موجودی انبار'), findsNothing);
    expect(find.byType(ErrorBox), findsOneWidget);
  });

  testWidgets('a broken chane board leaves the overview standing',
      (tester) async {
    // به کادر خطا نگاه می‌کنیم نه به متنی در دل صفحه: تب یک ListView است
    // و چیزی که پایین‌تر از لبهٔ صفحه باشد اصلاً ساخته نمی‌شود، پس
    // نبودنِ یک نوشته چیزی دربارهٔ سالم بودن صفحه نمی‌گوید.
    wire.broken.add('/chane-board');

    await show(tester, AdminOverviewTab(api: api, bakery: null));

    expect(find.byType(ErrorBox), findsNothing);
  });

  testWidgets('the dashboard failing is still an error', (tester) async {
    // داشبورد خودِ صفحه است: اگر این نیاید چیزی برای نشان دادن نمانده.
    wire.broken.add('/reports/dashboard');

    await show(tester, AdminOverviewTab(api: api, bakery: null));

    expect(find.byType(ErrorBox), findsOneWidget);
  });

  testWidgets('a flour-sales answer with no summary is not a crash',
      (tester) async {
    // سروری کمی قدیمی‌تر از اپ، یا روزی که شکل پاسخ فرق کند. خطای نوعی
    // که اینجا درمی‌آمد `ApiException` نبود، پس محافظ تب هم نمی‌گرفتش و
    // موجودی انبار با آن می‌رفت.
    wire.bodies['/flour-sales/today'] = '{"success":true,"data":{}}';

    await show(tester, AdminWarehouseTab(api: api));

    expect(find.text('موجودی انبار'), findsOneWidget);
  });
}
