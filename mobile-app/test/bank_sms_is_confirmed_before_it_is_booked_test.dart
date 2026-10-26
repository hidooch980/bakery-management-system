import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/screens/admin/bank_sms/bank_sms_screens.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/utils/bank_sms_parser.dart';

/// طرح «پیامک‌های بانکی». اعداد ساختگی‌اند.
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

final _now = DateTime(2026, 10, 26, 9, 0);

final _samples = <BankSms>[
  BankSms(
    id: 1,
    bank: 'ملی',
    body:
        'بانک ملی\nواریز: 12,345,000 ریال\nکارت 6037**1234\nمانده: 98,765,000\n1405/08/04 08:41',
    receivedAt: _now.subtract(const Duration(minutes: 19)),
    amountToman: 1234500,
    direction: BankSmsDirection.deposit,
    cardLast4: '1234',
    accountName: 'ملی — حساب کارتخوان',
    suggestedCategory: 'sale',
  ),
  BankSms(
    id: 2,
    bank: 'ملی',
    body:
        'بانک ملی\nبرداشت: 1,111,000 ریال\nکارت 6037**1234\nمانده: 97,654,000',
    receivedAt: _now.subtract(const Duration(hours: 2)),
    amountToman: 111100,
    direction: BankSmsDirection.withdrawal,
    cardLast4: '1234',
    accountName: 'ملی — حساب کارتخوان',
    suggestedCategory: 'freight',
  ),
  BankSms(
    id: 3,
    bank: 'سپه',
    body: 'سپه\nحساب 0987\nبرداشت 2,222,000\nبابت قبض برق',
    receivedAt: _now.subtract(const Duration(hours: 5)),
    amountToman: 222200,
    direction: BankSmsDirection.withdrawal,
    cardLast4: '0987',
    suggestedCategory: 'utilities',
  ),
  BankSms(
    id: 4,
    bank: 'ملت',
    body: 'ملت\n+3,333,000\nحساب 5678\nیارانه',
    receivedAt: _now.subtract(const Duration(days: 1)),
    amountToman: 333300,
    direction: BankSmsDirection.deposit,
    cardLast4: '5678',
    accountName: 'ملت — حساب اصلی',
    suggestedCategory: 'subsidy',
  ),
];

final _matched = <BankSms>[
  BankSms(
    id: 7,
    bank: 'ملی',
    body: 'برداشت: 6,500,000 ریال — کارت 6037**1234',
    receivedAt: _now.subtract(const Duration(hours: 15)),
    amountToman: 650000,
    direction: BankSmsDirection.withdrawal,
    cardLast4: '1234',
    accountName: 'ملی — حساب کارتخوان',
    suggestedCategory: 'salary',
    status: 'matched',
    matchedLabel: 'حقوق — جلیل درازهی (۱۴۰۵/۰۸/۰۳)',
  ),
  BankSms(
    id: 8,
    bank: 'ملت',
    body: 'برداشت 12,000,000 — حساب 5678 — قسط',
    receivedAt: _now.subtract(const Duration(hours: 20)),
    amountToman: 1200000,
    direction: BankSmsDirection.withdrawal,
    cardLast4: '5678',
    accountName: 'ملت — حساب اصلی',
    suggestedCategory: 'other',
    status: 'matched',
    matchedLabel: 'قسط وام — وام ملت (قسط ۷)',
  ),
];

final _ambiguous = BankSms(
  id: 9,
  bank: 'ملی',
  body: 'برداشت 3,000,000 ریال — کارت 6037**1234',
  receivedAt: _now.subtract(const Duration(hours: 1)),
  amountToman: 300000,
  direction: BankSmsDirection.withdrawal,
  cardLast4: '1234',
  accountName: 'ملی — حساب کارتخوان',
  suggestedCategory: 'other',
  status: 'ambiguous',
  candidates: const ['علی‌الحساب — سعید غلامزهی', 'هزینه — تعمیر دستگاه'],
);

final _duplicate = BankSms(
  id: 10,
  bank: 'ملی',
  body: 'واریز: 12,345,000 ریال — کارت 6037**1234',
  receivedAt: _now.subtract(const Duration(minutes: 17)),
  amountToman: 1234500,
  direction: BankSmsDirection.deposit,
  cardLast4: '1234',
  accountName: 'ملی — حساب کارتخوان',
  suggestedCategory: 'sale',
  possibleDuplicateOf: 'واریز همین مبلغ ساعت 08:41 هم آمده',
);

final _done = <BankSms>[
  ..._matched,
  BankSms(
    id: 5,
    bank: 'ملی',
    body: 'واریز 4,444,000',
    receivedAt: _now.subtract(const Duration(days: 1, hours: 3)),
    amountToman: 444400,
    direction: BankSmsDirection.deposit,
    cardLast4: '1234',
    accountName: 'ملی — حساب کارتخوان',
    suggestedCategory: 'sale',
    status: 'confirmed',
  ),
  BankSms(
    id: 6,
    bank: 'ملی',
    body: 'برداشت 555,000',
    receivedAt: _now.subtract(const Duration(days: 2)),
    amountToman: 55500,
    direction: BankSmsDirection.withdrawal,
    cardLast4: '1234',
    accountName: 'ملی — حساب کارتخوان',
    suggestedCategory: 'other',
    status: 'ignored',
  ),
];

void main() {
  group('خواندن پیامک بانک', () {
    test('واریز با رقم فارسی و ریال', () {
      final p = BankSmsParser.parse('300017',
          'بانک ملي\nواريز: ۱۲٬۳۴۵٬۰۰۰ ريال\nکارت ۶۰۳۷**۱۲۳۴\nمانده: ۹۸٬۷۶۵٬۰۰۰');
      expect(p.direction, BankSmsDirection.deposit);
      expect(p.amountToman, 1234500);
      expect(p.balanceToman, 9876500);
      expect(p.bank, 'ملی');
      expect(p.cardLast4, '1234');
    });

    test('برداشت با علامت منفی', () {
      final p = BankSmsParser.parse('200033', 'ملت\n-1,111,000\nحساب 5678');
      expect(p.direction, BankSmsDirection.withdrawal);
      expect(p.amountToman, 111100);
      expect(p.cardLast4, '5678');
    });

    test('رمز پویا هرگز پیامک بانکی حساب نمی‌شود', () {
      expect(BankSmsParser.isBankMessage('300017', 'رمز پویا شما: 123456'),
          isFalse);
      expect(
          BankSmsParser.isBankMessage('+98300017', 'واریز 1,000,000'), isTrue);
      expect(BankSmsParser.isBankMessage('09121234567', 'واریز 1,000,000'),
          isFalse);
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
    final open = [..._samples, _ambiguous, _duplicate, ..._matched]
        .where((s) => s.isOpen)
        .toList();
    await show(tester, BankSmsPendingPopup(items: open),
        size: const Size(390, 1700));
    expect(find.text('۶ پیامک بانکی منتظر ثبت'), findsOneWidget);
    expect(find.text('قبلاً ثبت شده'), findsNothing);
    expect(find.text('بعداً'), findsOneWidget);
    await _preview(tester, '2-popup-on-open');
  });

  testWidgets('صندوق تأیید', (tester) async {
    await show(
        tester,
        BankSmsInboxScreen(
            items: [_ambiguous, _duplicate, ..._samples, ..._done]),
        size: const Size(390, 2100));
    expect(find.textContaining('همین است:'), findsNWidgets(2));
    expect(find.textContaining('شاید تکراری'), findsOneWidget);
    await _preview(tester, '3-inbox');
    await tester.tap(find.text('ثبت‌شده و نادیده'));
    await tester.pumpAndSettle();
    expect(find.text('قبلاً ثبت شده'), findsNWidgets(2));
    await _preview(tester, '3b-inbox-done-and-matched');
  });

  testWidgets('برگهٔ تأیید با دسته', (tester) async {
    await show(
      tester,
      Scaffold(
        body: Align(
          alignment: Alignment.bottomCenter,
          child: Material(
            elevation: 4,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
            child: BankSmsConfirmSheet(sms: _samples[1]),
          ),
        ),
      ),
    );
    expect(find.text('ثبت به‌عنوان هزینه'), findsOneWidget);
    await _preview(tester, '4-confirm-sheet');
  });

  testWidgets('تنظیمات و وصل کارت به حساب', (tester) async {
    await show(
      tester,
      const BankSmsSettingsScreen(
        cards: [
          ('1234', 'ملی', 'ملی — حساب کارتخوان'),
          ('5678', 'ملت', 'ملت — حساب اصلی'),
          ('0987', 'سپه', null),
        ],
        senders: [('300017', 'ملی'), ('200033', 'ملت'), ('200020', 'سپه')],
      ),
      size: const Size(390, 1100),
    );
    await _preview(tester, '5-settings');
  });

  testWidgets('نشان شمار روی صفحهٔ اصلی', (tester) async {
    await show(
      tester,
      const Scaffold(
        body: Padding(
          padding: EdgeInsets.all(16),
          child: BankSmsHomeCard(pending: 6),
        ),
      ),
      size: const Size(390, 200),
    );
    expect(find.text('۶ پیامک منتظر تأیید'), findsOneWidget);
    await _preview(tester, '6-home-badge');
  });
}
