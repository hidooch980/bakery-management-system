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

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/utils/formatters.dart';
import 'package:bakery_app/widgets/attendance_card.dart';

/// «ثبت خروج» و «مدت کار»: هم برای خود، هم برای فروشنده‌ای که بقیه را ثبت می‌کند.
class _Canned implements HttpClientAdapter {
  _Canned(this.byRequest);

  /// 'GET /attendance/today' → data
  final Map<String, Object> byRequest;
  final List<String> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add('${options.method} ${options.path}');
    final keys = byRequest.keys.toList()
      ..sort((a, b) => b.length.compareTo(a.length));
    final match = keys
        .where((k) {
          final parts = k.split(' ');
          return parts[0] == options.method && options.path.endsWith(parts[1]);
        })
        .map((k) => byRequest[k]!)
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

Map<String, Object?> _person(int id, String name, String role,
        {String? inAt, String? outAt, int? minutes}) =>
    {
      'id': id,
      'name': name,
      'role': role,
      'checked_in': inAt != null,
      'checked_in_at': inAt,
      'recorded_by_another': inAt != null,
      'checked_out': outAt != null,
      'checked_out_at': outAt,
      'worked_minutes': minutes,
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

  test('مدت کار با رقم فارسی', () {
    expect(workedDuration(495), '۸ ساعت و ۱۵ دقیقه');
    expect(workedDuration(480), '۸ ساعت');
    expect(workedDuration(45), '۴۵ دقیقه');
    expect(workedDuration(0), '۰ دقیقه');
  });

  Future<_Canned> show(WidgetTester tester, Map<String, Object> canned,
      {bool forOthers = false, Size size = const Size(390, 420)}) async {
    final adapter = _Canned(canned);
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(RepaintBoundary(
      key: const ValueKey('preview'),
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.dark(),
        builder: (context, child) =>
            Directionality(textDirection: TextDirection.rtl, child: child!),
        home: Scaffold(
          body: Padding(
            padding: const EdgeInsets.all(16),
            child: AttendanceCard(
              api: BakeryApi(client),
              canRecordForOthers: forOthers,
            ),
          ),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    return adapter;
  }

  testWidgets('بعد از ورود دکمهٔ «ثبت خروج» می‌آید و مدت کار نشان داده می‌شود',
      (tester) async {
    final adapter = await show(tester, {
      'GET /attendance/today': {
        'checked_in': true,
        'checked_in_at': '2026-10-18 06:00:00',
        'checked_out': false,
        'checked_out_at': null,
        'worked_minutes': null,
      },
      'POST /attendance/check-out': {
        'id': 1,
        'date': '2026-10-18',
        'checked_in_at': '2026-10-18 06:00:00',
        'checked_out_at': '2026-10-18 14:15:00',
        'worked_minutes': 495,
      },
    });

    expect(find.text('حضور ثبت شده'), findsOneWidget);
    expect(find.text('ورود ساعت 06:00'), findsOneWidget);
    expect(find.text('ثبت خروج'), findsOneWidget);
    await _preview(tester, 'self-checked-in');

    await tester.tap(find.text('ثبت خروج'));
    await tester.pumpAndSettle();

    expect(adapter.seen, contains('POST /attendance/check-out'));
    expect(find.text('خروج ثبت شده'), findsOneWidget);
    expect(find.text('ورود 06:00 — خروج 14:15'), findsOneWidget);
    expect(find.text('مدت کار: ۸ ساعت و ۱۵ دقیقه'), findsOneWidget);
    expect(find.text('ثبت خروج'), findsNothing);
    expect(tester.takeException(), isNull);
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
    await _preview(tester, 'self-checked-out');
  });

  testWidgets('پیش از ورود دکمهٔ خروجی نیست', (tester) async {
    await show(tester, {
      'GET /attendance/today': {'checked_in': false},
    });

    expect(find.text('تیک حضور امروز'), findsOneWidget);
    expect(find.text('ثبت خروج'), findsNothing);
  });

  testWidgets('فروشنده خروج دیگران را ثبت می‌کند و مدت کارشان را می‌بیند',
      (tester) async {
    final adapter = await show(
      tester,
      {
        'GET /attendance/today': {
          'checked_in': true,
          'checked_in_at': '2026-10-18 05:45:00',
        },
        'GET /attendance/roster': {
          'staff': [
            _person(2, 'جلیل درازهی', 'شاطر',
                inAt: '05:30', outAt: '13:45', minutes: 495),
            _person(3, 'سعید غلامزهی', 'چانه گیر', inAt: '05:40'),
            _person(4, 'عبدالله خوشنواز', 'خمیرگیر', inAt: '04:50'),
            _person(5, 'اشرف بلوچزهی', 'کارگر'),
          ],
        },
        'POST /attendance/check-out/4': {
          'checked_out_time': '13:20',
          'worked_minutes': 510,
        },
      },
      forOthers: true,
      size: const Size(390, 844),
    );

    await tester.tap(find.text('ثبت حضور و خروج بقیه کارکنان'));
    await tester.pumpAndSettle();

    expect(find.text('ورود 05:30 — خروج 13:45\nمدت کار: ۸ ساعت و ۱۵ دقیقه'),
        findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'ثبت خروج'), findsNWidgets(3));
    await _preview(tester, 'seller-roster-before');

    await tester.tap(find.widgetWithText(OutlinedButton, 'ثبت خروج').at(2));
    await tester.pumpAndSettle();

    expect(adapter.seen, contains('POST /attendance/check-out/4'));
    expect(find.text('ورود 04:50 — خروج 13:20\nمدت کار: ۸ ساعت و ۳۰ دقیقه'),
        findsOneWidget);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'seller-roster-after');
  });
}
