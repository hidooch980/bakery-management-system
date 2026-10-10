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
import 'package:bakery_app/screens/shared/partner_statement_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/widgets/partner_flour_card.dart';

/// پروندهٔ هر همکار در اپ: سرخط «طلب ما: ۱۰ کیسه» و گردش ریز به کیسه.
///
/// داده همان گردش واقعیِ کنت است که در طرح تأیید شد.
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

Map<String, Object?> _r(
        String date, String kind, String label, num bags, num balance,
        [String note = '', bool approx = false]) =>
    {
      'date_display': date,
      'kind': kind,
      'label': label,
      'bags': bags,
      'note': note,
      'approximate': approx,
      'user': 'مدیر',
      'record_id': 1,
      'balance_bags': balance,
    };

final _kent = <String, Object?>{
  'partner': {'id': 7, 'name': 'محمداکبر قریشیان نانوایی کنت', 'phone': null},
  'opening_bags': 0,
  'closing_bags': 10,
  'current_bags': 10,
  'headline': {'label': 'طلب ما: 10 کیسه', 'tone': 'owed'},
  'totals': {
    'lent_bags': 40,
    'borrowed_bags': 12,
    'returns_bags': 42,
  },
  'rows': [
    _r('1405/05/16', 'borrowed', 'گرفتیم', -12, -12, '', true),
    _r('1405/06/03', 'lent', 'دادیم', 20, 8),
    _r('1405/07/05', 'lent', 'دادیم', 10, 18),
    _r('1405/07/16', 'returned_by_us', 'پس دادیم', 12, 30,
        'برگشت کامل «گرفتیم 12 کیسه» مورخ 1405/05/16'),
    _r('1405/07/16', 'returned_to_us', 'پس گرفتیم', -10, 20,
        'برگشت کامل «دادیم 10 کیسه» مورخ 1405/07/05'),
    _r('1405/07/16', 'returned_to_us', 'پس گرفتیم', -20, 0,
        'برگشت کامل «دادیم 20 کیسه» مورخ 1405/06/03'),
    _r('1405/07/16', 'lent', 'دادیم', 10, 10),
  ],
};

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

  Future<_Canned> show(WidgetTester tester, Widget Function(BakeryApi) page,
      {Brightness brightness = Brightness.dark}) async {
    final adapter = _Canned({
      '/consignment-flour/partners/7/statement': _kent,
      '/consignment-flour/balance': {
        'lent_bags': 10,
        'borrowed_bags': 3,
        'net_bags': 7,
        'owed_to_us_bags': 10,
        'we_owe_bags': 3,
        'partners_net_bags': 7,
        'headline': {'label': 'طلب ما: 7 کیسه', 'tone': 'owed'},
      },
      '/consignment-flour/partners': [
        {
          'partner_id': 7,
          'partner_name': 'محمداکبر قریشیان نانوایی کنت',
          'net_bags': 10,
          'entries': 1,
          'days': 2,
        },
      ],
      '/consignment-flour': {'data': <Object>[]},
    });
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.transport = adapter;

    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(MaterialApp(
      theme: brightness == Brightness.dark ? AppTheme.dark() : AppTheme.light(),
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

  testWidgets(
      'سرخط پرونده طلب ما را به کیسه می‌گوید و ردیف‌ها با مانده می‌آیند',
      (tester) async {
    await show(
      tester,
      (api) => PartnerStatementScreen(
        api: api,
        partnerId: 7,
        partnerName: 'محمداکبر قریشیان نانوایی کنت',
      ),
    );

    expect(find.text('طلب ما: 10 کیسه'), findsOneWidget);
    expect(find.text('آرد ما نزد این همکار است'), findsOneWidget);
    // جدیدترین بالا.
    expect(find.text('دادیم 10 کیسه'), findsWidgets);
    expect(find.text('+10'), findsWidgets);
    expect(find.textContaining('کیلو'), findsNothing);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'partner-statement');
  });

  testWidgets('مانده با علامت چپ‌به‌راست کنار عدد می‌ماند', (tester) async {
    await show(
      tester,
      (api) =>
          PartnerStatementScreen(api: api, partnerId: 7, partnerName: 'کنت'),
    );
    final balance = tester.widget<Text>(find.text('+10').first);
    expect(balance.textDirection, TextDirection.ltr);
    expect(signedBags(-2), '−2');
    expect(signedBags(0), '0');
    expect(bags(46.5), '46.5');
  });

  testWidgets('زدن روی همکار پرونده‌اش را باز می‌کند', (tester) async {
    final adapter =
        await show(tester, (api) => ConsignmentFlourScreen(api: api));
    await tester.tap(find.text('محمداکبر قریشیان نانوایی کنت'));
    await tester.pumpAndSettle();

    expect(
        adapter.seen.any((s) => s.contains('/partners/7/statement')), isTrue);
    expect(find.text('طلب ما: 10 کیسه'), findsOneWidget);
  });

  testWidgets('کارت خانه طلب، بدهی و خالص را می‌گوید', (tester) async {
    await show(
      tester,
      (api) => Scaffold(
        body: ListView(
          padding: const EdgeInsets.all(20),
          children: [PartnerFlourCard(api: api)],
        ),
      ),
    );

    expect(find.text('آرد امانی همکاران'), findsOneWidget);
    expect(find.text('10 کیسه'), findsOneWidget);
    expect(find.text('3 کیسه'), findsOneWidget);
    expect(find.text('طلب ما: 7 کیسه'), findsOneWidget);
    await _preview(tester, 'home-partner-card');
  });
}
