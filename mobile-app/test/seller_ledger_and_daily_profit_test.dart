import 'package:bakery_app/screens/admin/seller_debts_section.dart';
import 'package:bakery_app/screens/shared/partner_statement_screen.dart';
import 'package:bakery_app/screens/shared/seller_ledger_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/widgets/daily_profit_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> _ledger({String? warning}) => {
      'balance': 150000,
      'balance_formatted': '۱۵۰٬۰۰۰ تومان',
      'prepaid_formatted': '۵۰٬۰۰۰ تومان',
      'debts_total_formatted': '۵۰۰٬۰۰۰ تومان',
      'paid_total_formatted': '۳۵۰٬۰۰۰ تومان',
      'customer_credit_formatted': '۴۰۰٬۰۰۰ تومان',
      'warning': warning,
      'debt_alert': null,
      'entries': [
        {
          'kind': 'payment',
          'label': 'تسویه #۱۲',
          'on_label': '۸ آبان ۱۴۰۵ ۱۰:۰۰',
          'credit_formatted': '۳۵۰٬۰۰۰ تومان',
          'balance_formatted': '۱۵۰٬۰۰۰ تومان',
        },
        {
          'kind': 'debt',
          'label': 'فروش نقدی',
          'on_label': '۷ آبان ۱۴۰۵ ۰۹:۰۰',
          'debit_formatted': '۵۰۰٬۰۰۰ تومان',
          'balance_formatted': '۵۰۰٬۰۰۰ تومان',
        },
      ],
    };

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));

  String? sentFingerprint;

  @override
  Future<List<Map<String, dynamic>>> sellerAccounts() async => [
        {
          'id': 9,
          'name': 'مریم',
          'still_owed': 150000,
          'still_owed_formatted': '۱۵۰٬۰۰۰ تومان',
          'ledger_warning': 'مغایرت حساب: اختلاف ۷۰٬۰۰۰ تومان',
        },
      ];

  @override
  Future<Map<String, dynamic>> sellerLedger(int sellerId) async =>
      _ledger(warning: 'مغایرت حساب: اختلاف ۷۰٬۰۰۰ تومان');

  @override
  Future<Map<String, dynamic>> dailyProfit({int days = 7}) async => {
        'rule': 'سود روز = فروش − آرد − حقوق − هزینه',
        'days': [
          for (var i = 0; i < 7; i++)
            {
              'date_label': '1405/08/0${i + 1}',
              'profit': i == 3 ? -20000 : 100000,
              'profit_formatted': i == 3 ? '−۲۰٬۰۰۰ تومان' : '۱۰۰٬۰۰۰ تومان',
              'income_formatted': '۴۰۰٬۰۰۰ تومان',
              'flour_formatted': '۲۰۰٬۰۰۰ تومان',
              'wages_formatted': '۵۰٬۰۰۰ تومان',
              'expenses_formatted': '۵۰٬۰۰۰ تومان',
            },
        ],
      };

  @override
  Future<Map<String, dynamic>> partnerStatement(int partnerId,
          {String? from, String? to}) async =>
      {
        'partner': {'name': 'نانوایی کنت'},
        'current': 5,
        'current_label': 'طلب ما: ۵ کیسه',
        'rows': <Map<String, dynamic>>[],
        'totals': <String, dynamic>{},
      };

  @override
  Future<Map<String, dynamic>> partnerReportPreview(int partnerId) async => {
        'has_phone': true,
        'phone_masked': '0935***2233',
        'channel': 'پیامک',
        'message': 'مانده فعلی: شما ۵ کیسه بدهکارید.',
        'fingerprint': 'abc',
      };

  @override
  Future<String> sendPartnerReport(int partnerId, String fingerprint) async {
    sentFingerprint = fingerprint;
    return 'پیامک';
  }
}

void main() {
  Future<void> size(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(600, 1600));
    addTearDown(() => tester.binding.setSurfaceSize(null));
  }

  testWidgets('the ledger shows each entry, the balance and agreement',
      (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(
      home: SellerLedgerScreen(load: () async => _ledger(), title: 'دفتر حساب من'),
    ));
    await tester.pumpAndSettle();

    expect(find.text('ماندهٔ حساب با دفتر یکی است.'), findsOneWidget);
    expect(find.text('تسویه #۱۲'), findsOneWidget);
    expect(find.text('فروش نقدی'), findsOneWidget);
    expect(find.text('مانده: ۱۵۰٬۰۰۰ تومان'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the manager sees the reconciliation warning and opens the ledger',
      (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: SingleChildScrollView(child: SellerDebtsSection(api: _Api()))),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('seller-ledger-warning')), findsOneWidget);
    await tester.tap(find.text('دفتر حساب'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('ledger-warning')), findsOneWidget);
    expect(find.text('دفتر حساب مریم'), findsOneWidget);
  });

  testWidgets('the daily profit card shows today and a week of bars',
      (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: DailyProfitCard(api: _Api())),
    ));
    await tester.pumpAndSettle();

    expect(find.text('سود امروز'), findsOneWidget);
    expect(find.text('۱۰۰٬۰۰۰ تومان'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('«ارسال» previews the message to a masked number, then sends',
      (tester) async {
    await size(tester);
    final api = _Api();
    await tester.pumpWidget(MaterialApp(
      home: PartnerStatementScreen(
          api: api, partnerId: 3, partnerName: 'نانوایی کنت', canSend: true),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('partner-report-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('0935***2233'), findsOneWidget);
    expect(find.text('مانده فعلی: شما ۵ کیسه بدهکارید.'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'ارسال'));
    await tester.pumpAndSettle();
    expect(api.sentFingerprint, 'abc');
  });

  testWidgets('«ارسال» is not offered to someone who cannot send',
      (tester) async {
    await size(tester);
    await tester.pumpWidget(MaterialApp(
      home: PartnerStatementScreen(api: _Api(), partnerId: 3, partnerName: 'نانوایی کنت'),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('partner-report-send')), findsNothing);
  });
}
