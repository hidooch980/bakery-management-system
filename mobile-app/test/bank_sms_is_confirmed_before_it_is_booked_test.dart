import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/screens/admin/bank_sms/bank_sms_screens.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/utils/bank_sms_parser.dart';

/// «پیامک‌های بانکی». The inbox below is the server's own answer
/// (test/fixtures/bank_sms_inbox.json, made by the backend from fake Sepah
/// SMS), so the screens are checked against what the API really sends.
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

/// The three shapes Bank Sepah sends, with made-up numbers.
const _withdrawal =
    'بانك سپه\nبرداشت از حساب 0101234567890\nمبلغ:1,111,000\nمانده:98,765,000\n1405/08/04-07:00';
const _posGroup =
    'بانك سپه\nواريز گروهي به حساب 0101234567890\nمبلغ:12,345,000\nکارتخوان\nمانده:111,110,000\n1405/08/04-08:41';
const _paya =
    'بانك سپه\nواريز پايا به حساب 0101234567890\nمبلغ:3,333,000\nمانده:114,443,000\n1405/08/04-09:00';

final _fixture =
    jsonDecode(File('test/fixtures/bank_sms_inbox.json').readAsStringSync())
        as Map<String, dynamic>;

List<BankSms> _list(String key) => [
      for (final j in _fixture[key] as List)
        BankSms.fromJson((j as Map).cast<String, dynamic>())
    ];

void main() {
  group('خواندن پیامک بانک سپه', () {
    test('برداشت از حساب', () {
      final p = BankSmsParser.parse('9830003', _withdrawal);
      expect(p.direction, BankSmsDirection.withdrawal);
      expect(p.amountToman, 111100);
      expect(p.balanceToman, 9876500);
      expect(p.cardLast4, '7890');
    });

    test('واریز گروهی کارتخوان', () {
      final p = BankSmsParser.parse('9830003', _posGroup);
      expect(p.direction, BankSmsDirection.deposit);
      expect(p.amountToman, 1234500);
      expect(p.cardLast4, '7890');
    });

    test('واریز پایا', () {
      final p = BankSmsParser.parse('9830003', _paya);
      expect(p.direction, BankSmsDirection.deposit);
      expect(p.amountToman, 333300);
    });

    test('رمز پویا هرگز پیامک بانکی حساب نمی‌شود', () {
      expect(BankSmsParser.isOtp('بانک سپه — رمز پویا: 123456'), isTrue);
      expect(BankSmsParser.isBankMessage('9830003', 'رمز پویا شما: 123456'),
          isFalse);
    });
  });

  group('پاسخ سرور', () {
    test('شمارهٔ کامل حساب هرگز به گوشی برنمی‌گردد', () {
      final raw = File('test/fixtures/bank_sms_inbox.json').readAsStringSync();
      expect(raw.contains('0101234567890'), isFalse);
      expect(raw.contains('****7890'), isTrue);
    });

    test('پیامک‌های قبلاً ثبت‌شده در صف انتظار نیستند', () {
      final open = _list('open');
      expect(open.every((s) => s.isOpen), isTrue);
      expect(open.where((s) => s.status == 'ambiguous').single.candidates,
          hasLength(2));
      expect(open.where((s) => s.possibleDuplicateAt != null), hasLength(1));
      final matched = _list('done').where((s) => s.status == 'matched');
      expect(matched.map((s) => s.matchedLabel),
          containsAll(['حقوق — جلیل درازهی', 'قسط وام — قسط ۷ وام سپه']));
    });
  });

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

  Future<void> show(WidgetTester tester, Widget page,
      {Size size = const Size(390, 844)}) async {
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
        home: page,
      ),
    ));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  }

  testWidgets('اجازهٔ پیامک با توضیح فارسی', (tester) async {
    await show(tester, const BankSmsPermissionScreen());
    expect(find.text('اجازه خواندن پیامک بانک'), findsOneWidget);
    await _preview(tester, '1-permission');
  });

  testWidgets('با باز شدن برنامه، پیامک‌های منتظر بالا می‌آیند',
      (tester) async {
    var later = false;
    await show(tester,
        BankSmsPendingPopup(items: _list('open'), onLater: () => later = true),
        size: const Size(390, 1900));
    expect(find.text('۶ پیامک بانکی منتظر ثبت'), findsOneWidget);
    expect(find.text('قبلاً ثبت شده'), findsNothing);
    await _preview(tester, '2-popup-on-open');
    await tester.tap(find.text('بعداً'));
    expect(later, isTrue);
  });

  testWidgets('صندوق تأیید', (tester) async {
    BankSms? confirmed;
    int? picked;
    await show(
        tester,
        BankSmsInboxScreen(
          open: _list('open'),
          done: _list('done'),
          actions: BankSmsActions(
            confirm: (s) => confirmed = s,
            pick: (s, id) => picked = id,
          ),
        ),
        size: const Size(390, 2300));
    expect(find.textContaining('همین است:'), findsNWidgets(2));
    expect(find.textContaining('شاید تکراری'), findsOneWidget);
    expect(find.text('حساب وصل نشده'), findsOneWidget);
    await _preview(tester, '3-inbox');

    await tester.tap(find.textContaining('همین است:').first);
    expect(picked, isNotNull);
    await tester.tap(find.text('ثبت درآمد').first);
    expect(confirmed?.isIncome, isTrue);

    await tester.tap(find.text('ثبت‌شده و نادیده'));
    await tester.pumpAndSettle();
    expect(find.text('قبلاً ثبت شده'), findsNWidgets(2));
    await _preview(tester, '3b-inbox-done-and-matched');
  });

  testWidgets('برگهٔ تأیید با دسته', (tester) async {
    final pos = _list('open').firstWhere((s) => s.isIncome);
    await show(
      tester,
      Scaffold(
        body: Align(
          alignment: Alignment.bottomCenter,
          child: Material(
            elevation: 4,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
            child: BankSmsConfirmSheet(sms: pos, onSubmit: (_) async {}),
          ),
        ),
      ),
    );
    expect(find.text('ثبت به‌عنوان درآمد'), findsOneWidget);
    await _preview(tester, '4-confirm-sheet');
  });

  testWidgets('تنظیمات و وصل حساب', (tester) async {
    await show(
      tester,
      BankSmsSettingsScreen(
        enabled: true,
        permitted: true,
        showOnOpen: true,
        mappings: [
          for (final m in _fixture['mappings'] as List)
            (
              id: ((m as Map)['id'] as num).toInt(),
              last4: '${m['last4']}',
              accountName: m['account_name'] as String?,
            ),
        ],
        unmapped: [for (final d in _fixture['unmapped'] as List) '$d'],
        onEnabled: (_) {},
        onShowOnOpen: (_) {},
        onMap: (_) {},
        onUnmap: (_) {},
      ),
      size: const Size(390, 900),
    );
    expect(find.text('حساب …۷۸۹۰'), findsOneWidget);
    await _preview(tester, '5-settings');
  });

  testWidgets('نشان شمار روی صفحهٔ اصلی', (tester) async {
    await show(
      tester,
      Scaffold(
        body: Padding(
          padding: const EdgeInsets.all(16),
          child: BankSmsHomeCard(
            pending: _list('open').length,
            balances: [
              for (final b in _fixture['balances'] as List)
                BankSmsBalance.fromJson((b as Map).cast<String, dynamic>()),
            ],
          ),
        ),
      ),
      size: const Size(390, 220),
    );
    expect(find.text('۶'), findsOneWidget);
    expect(find.textContaining('موجودی سپه — حساب اصلی'), findsOneWidget);
    await _preview(tester, '6-home-badge');
  });
}
