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

import 'package:bakery_app/screens/shared/consignment_flour_screen.dart';
import 'package:bakery_app/screens/seller/flour_day_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/theme/app_theme.dart';

/// «همه اسما باشه»، «قسمت تسویه نباشه» و «گردش روزانه آرد برای فروشنده».
class _Canned implements HttpClientAdapter {
  _Canned(this.byPath);

  final Map<String, Object> byPath;
  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.method} ${options.uri}');
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

Map<String, Object?> _p(int id, String name, num net, int entries,
        [int? days]) =>
    {
      'partner_id': id,
      'partner_name': name,
      'net_bags': net,
      'lent_bags': net > 0 ? net : 0,
      'borrowed_bags': net < 0 ? -net : 0,
      'entries': entries,
      'days': days,
      'is_settled': net == 0,
    };

// همان هشت همکار واقعی و مانده‌هایشان (۱۴۰۵/۰۷/۱۹).
final _partners = [
  _p(11, 'محمد صالح ایزدخواه نانوایی کرشان', 10, 1, 2),
  _p(9, 'محمداکبر قریشیان نانوایی کنت', 10, 4, 3),
  _p(5, 'عبدالحمید پرکی نانوایی کلوکان', 0, 0),
  _p(6, 'عبدالریٌوف درازهی نانوایی هیدوچ', 0, 5),
  _p(10, 'لال محمد قریشیان نانوایی کنت', 0, 0),
  _p(7, 'ممد زاکر پرکی نانوایی پدگان', 0, 2),
  _p(8, 'منصور پرکی نانوایی ناهوت', 0, 2),
  _p(12, 'میرمرازهی نانوایی کنت', 0, 0),
];

Map<String, Object?> _rec(
        int id, String name, String dir, num bags, String date) =>
    {
      'id': id,
      'partner_id': 9,
      'partner_name': name,
      'direction': dir,
      'direction_label': dir == 'lent' ? 'تحویلی به همکار' : 'دریافتی از همکار',
      'bags': bags,
      'quantity_label': '$bags کیسه',
      'occurred_on_display': date,
      'is_settled': false,
      'outstanding_bags': bags,
      'note': null,
    };

final _day = <String, Object?>{
  'date': '2026-10-11',
  'date_display': '1405/07/19',
  'is_today': true,
  'previous_date': '2026-10-10',
  'next_date': null,
  'bag_weight_kg': 40,
  'opening_bags': 64.5,
  'in_bags': 21,
  'out_bags': 14.5,
  'closing_bags': 71,
  'in': [
    {'reason': 'purchase', 'label': 'خرید', 'bags': 20, 'count': 1},
    {
      'reason': 'consignment_in',
      'label': 'دریافت امانی',
      'bags': 1,
      'count': 1
    },
  ],
  'out': [
    {'reason': 'production', 'label': 'مصرف در تولید', 'bags': 11, 'count': 2},
    {'reason': 'flour_sale', 'label': 'فروش آرد', 'bags': 2, 'count': 1},
    {'reason': 'spray', 'label': 'آرد پاششی', 'bags': 0.5, 'count': 1},
    {
      'reason': 'consignment_out',
      'label': 'تحویل امانی',
      'bags': 1,
      'count': 1
    },
  ],
  'movements': [
    {
      'id': 1,
      'time': '06:40',
      'direction': 'out',
      'reason': 'production',
      'label': 'مصرف در تولید',
      'bags': 5.5,
      'note': 'خمیر نوبت صبح',
      'user': 'خمیرگیر'
    },
    {
      'id': 2,
      'time': '07:05',
      'direction': 'out',
      'reason': 'spray',
      'label': 'آرد پاششی',
      'bags': 0.5,
      'note': null,
      'user': 'شاطر'
    },
    {
      'id': 3,
      'time': '09:30',
      'direction': 'in',
      'reason': 'purchase',
      'label': 'خرید',
      'bags': 20,
      'note': 'سهمیه دوره دوم',
      'user': 'فروشنده'
    },
    {
      'id': 4,
      'time': '11:10',
      'direction': 'out',
      'reason': 'flour_sale',
      'label': 'فروش آرد',
      'bags': 2,
      'note': null,
      'user': 'فروشنده'
    },
    {
      'id': 5,
      'time': '12:20',
      'direction': 'out',
      'reason': 'consignment_out',
      'label': 'تحویل امانی',
      'bags': 1,
      'note': 'محمداکبر قریشیان نانوایی کنت',
      'user': 'فروشنده'
    },
    {
      'id': 6,
      'time': '13:45',
      'direction': 'in',
      'reason': 'consignment_in',
      'label': 'دریافت امانی',
      'bags': 1,
      'note': 'محمد صالح ایزدخواه نانوایی کرشان',
      'user': 'فروشنده'
    },
    {
      'id': 7,
      'time': '16:00',
      'direction': 'out',
      'reason': 'production',
      'label': 'مصرف در تولید',
      'bags': 5.5,
      'note': 'خمیر نوبت عصر',
      'user': 'خمیرگیر'
    },
  ],
};

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

  Future<_Canned> show(WidgetTester tester, Widget Function(BakeryApi) page,
      {Size size = const Size(390, 844)}) async {
    final adapter = _Canned({
      '/consignment-flour/balance': {
        'lent_bags': 20,
        'borrowed_bags': 0,
        'net_bags': 20,
        'owed_to_us_bags': 20,
        'we_owe_bags': 0,
        'partners_net_bags': 20,
        'headline': {'label': 'طلب ما: 20 کیسه', 'tone': 'owed'},
      },
      '/consignment-flour/partners': _partners,
      '/consignment-flour': {
        'data': [
          _rec(14, 'محمداکبر قریشیان نانوایی کنت', 'lent', 10, '1405/07/16'),
          _rec(
              13, 'محمد صالح ایزدخواه نانوایی کرشان', 'lent', 10, '1405/07/16'),
          _rec(12, 'محمداکبر قریشیان نانوایی کنت', 'lent', 10, '1405/07/05'),
        ],
      },
      '/inventory/flour/day': _day,
    });
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.dark(),
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: RepaintBoundary(
          key: const ValueKey('preview'),
          child: page(BakeryApi(client)),
        ),
      ),
    ));
    await tester.pumpAndSettle();

    return adapter;
  }

  testWidgets('همهٔ همکاران دیده می‌شوند و دکمهٔ تسویه‌ای نیست',
      (tester) async {
    final adapter = await show(
      tester,
      (api) => ConsignmentFlourScreen(api: api),
      size: const Size(390, 1500),
    );

    for (final p in _partners) {
      expect(find.text('${p['partner_name']}'), findsWidgets);
    }
    // صفرها «تسویه» می‌خوانند؛ بی‌ثبت‌ها می‌گویند هنوز ثبتی ندارند.
    expect(find.text('تسویه'), findsNWidgets(6));
    expect(find.text('طلب ما'), findsNWidgets(2));
    expect(find.text('هنوز ثبتی ندارد'), findsNWidgets(3));
    // نه دکمه، نه برچسب.
    expect(find.text('تسویه شد'), findsNothing);
    expect(find.widgetWithText(TextButton, 'تسویه شد'), findsNothing);
    // فهرست ثبت‌ها همهٔ جابه‌جایی‌هاست، نه فقط «باز»ها.
    expect(
        adapter.seen.any((u) =>
            u.contains('/consignment-flour?') &&
            u.contains('outstanding_only')),
        isFalse);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'consignment-all-partners');
  });

  testWidgets('گردش روزانه آرد: اول و آخر روز، آمد و رفت و ریز، به کیسه',
      (tester) async {
    final adapter = await show(
      tester,
      (api) => FlourDayScreen(api: api),
      size: const Size(390, 1250),
    );

    expect(find.text('امروز  •  1405/07/19'), findsOneWidget);
    expect(find.text('64.5 کیسه'), findsOneWidget);
    expect(find.text('71 کیسه'), findsOneWidget);
    expect(find.text('موجودی اول روز'), findsOneWidget);
    expect(find.text('موجودی آخر روز'), findsOneWidget);
    expect(find.text('ورودی'), findsOneWidget);
    expect(find.text('خروجی'), findsOneWidget);
    expect(find.text('06:40'), findsOneWidget);
    expect(find.text(signedBags(20, incoming: true)), findsOneWidget);
    expect(find.text('\u2066−5.5\u2069 کیسه'), findsNWidgets(2));
    expect(find.textContaining('کیلو'), findsNothing);
    // فقط خواندنی: هیچ درخواست نوشتنی.
    expect(adapter.seen.every((u) => u.startsWith('GET ')), isTrue);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'seller-daily-flour');

    // روز قبل.
    await tester.tap(find.byTooltip('روز قبل'));
    await tester.pumpAndSettle();
    expect(adapter.seen.last, contains('date=2026-10-10'));
  });
}
