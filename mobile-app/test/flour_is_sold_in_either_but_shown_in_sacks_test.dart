import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/screens/seller/flour_sale_sheet.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/utils/formatters.dart';

/// فروشنده آرد را به کیلو یا کیسه وارد می‌کند (پیش‌فرض کیسه)، ولی آنچه
/// می‌بیند همیشه کیسه است.
class _Canned implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final data = options.path.contains('/flour-sales/options')
        ? {
            'bag_weight_kg': 40,
            'available_kg': 11200,
            'available_bags': 280,
            'units': [
              {'key': 'kg', 'unit_price': 50000},
              {'key': 'bag', 'unit_price': 2000000},
            ],
            'currency_label': 'تومان',
          }
        : <Object>[];

    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<void> _preview(WidgetTester tester, String name) async {
  final dir = Platform.environment['BAKERY_UI_PREVIEW_DIR'];
  if (dir == null) return;
  await tester.runAsync(() async {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
        find.byKey(const ValueKey('preview')));
    final image = await boundary.toImage(pixelRatio: 2);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    await Directory(dir).create(recursive: true);
    await File('$dir/$name.png').writeAsBytes(bytes!.buffer.asUint8List());
    image.dispose();
  });
}

void main() {
  setUpAll(() async {
    for (final weight in ['Regular', 'Medium', 'Bold']) {
      final loader = FontLoader('Vazirmatn')
        ..addFont(rootBundle.load('assets/fonts/Vazirmatn-$weight.ttf'));
      await loader.load();
    }
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<void> show(WidgetTester tester) async {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = _Canned();
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.dark(),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: RepaintBoundary(
          key: const ValueKey('preview'),
          child: Scaffold(
            body: SingleChildScrollView(
              child: FlourSaleSheet(api: BakeryApi(client)),
            ),
          ),
        ),
      ),
    ));
    await tester.pumpAndSettle();
  }

  test('کیسه بی‌صفر اضافه نوشته می‌شود', () {
    expect(flourBags(13), '13 کیسه');
    expect(flourBags(46.5), '46.5 کیسه');
    expect(flourBags(0.13), '0.13 کیسه');
  });

  testWidgets('فرم فروش آرد با کیسه باز می‌شود', (tester) async {
    await show(tester);

    expect(find.text('مقدار (کیسه)'), findsOneWidget);
    await tester.enterText(find.byType(TextField).first, '3');
    await tester.pumpAndSettle();
    expect(find.text('3 کیسه'), findsOneWidget);
    await _preview(tester, 'flour-sale-bags');
  });

  testWidgets('به کیلو هم وارد می‌شود ولی پیش‌نمایش کیسه است', (tester) async {
    await show(tester);

    await tester.tap(find.text('کیلویی'));
    await tester.pumpAndSettle();
    expect(find.text('مقدار (کیلوگرم)'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, '20');
    await tester.pumpAndSettle();
    expect(find.text('0.5 کیسه'), findsOneWidget);
    expect(find.text('20.00 کیلوگرم'), findsNothing);
    await _preview(tester, 'flour-sale-kg-entry');
  });
}
