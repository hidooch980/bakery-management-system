import 'package:bakery_app/screens/admin/seller_warning_rules_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Item 12: the manager's seller-warning rules, switched from the phone.
class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));

  final List<Map<String, dynamic>> sent = [];
  bool active = false;

  @override
  Future<List<Map<String, dynamic>>> sellerWarningRules() async => [
        {
          'id': 4,
          'name': 'عدم رعایت ساعت شیفت کاری (نانینو)',
          'condition_label': 'تأخیر در شروع شیفت',
          'unit_label': 'دقیقه',
          'threshold': 15,
          'threshold_label': '15 دقیقه',
          'source': 'nanino',
          'is_active': active,
        },
        {
          'id': 1,
          'name': 'بدهی بیش از سقف',
          'condition_label': 'بدهی فروشنده بیش از سقف',
          'unit_label': 'ریال',
          'threshold': null,
          'threshold_label': 'تعیین نشده',
          'source': 'custom',
          'is_active': false,
        },
      ];

  @override
  Future<Map<String, dynamic>> updateSellerWarningRule(
      int id, Map<String, dynamic> changes) async {
    sent.add({'id': id, ...changes});
    if (changes['is_active'] == true) active = true;
    return {};
  }
}

Widget _wrap(Widget child) => MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );

void main() {
  testWidgets('rules are listed with the nanino source and switch on',
      (tester) async {
    final api = _Api();
    await tester.pumpWidget(_wrap(SellerWarningRulesSection(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('عدم رعایت ساعت شیفت کاری (نانینو)'), findsOneWidget);
    expect(find.text('نانینو'), findsOneWidget);
    expect(find.text('تأخیر در شروع شیفت • مقدار: 15 دقیقه'), findsOneWidget);
    expect(find.text('0 فعال'), findsOneWidget);

    await tester.tap(find.byKey(const Key('seller-rule-switch-4')));
    await tester.pumpAndSettle();

    expect(api.sent.single, {'id': 4, 'is_active': true});
    expect(find.text('1 فعال'), findsOneWidget);
  });

  testWidgets('a threshold is set from the rule dialog', (tester) async {
    final api = _Api();
    await tester.pumpWidget(_wrap(SellerWarningRulesSection(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.text('بدهی بیش از سقف'));
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const Key('seller-rule-threshold')), '۵۰۰۰۰۰۰');
    await tester.tap(find.text('ذخیره'));
    await tester.pumpAndSettle();

    expect(api.sent.single['id'], 1);
    expect(api.sent.single['threshold'], 5000000);
  });
}
