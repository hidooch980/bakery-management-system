import 'package:bakery_app/models/penalty_request.dart';
import 'package:bakery_app/screens/admin/penalty_requests_section.dart';
import 'package:bakery_app/screens/seller/penalty_requests_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  Map<String, Object?>? sent;

  @override
  Future<PenaltyRequest> requestPenalty({
    required int userId,
    required String type,
    required String reason,
    double? amount,
    String? note,
  }) async {
    sent = {
      'user_id': userId,
      'type': type,
      'reason': reason,
      'amount': amount
    };
    return PenaltyRequest.fromJson({
      'id': 1,
      'user_name': 'علی',
      'type': type,
      'status': 'pending',
      'is_pending': true,
    });
  }
}

const _options = PenaltyOptions(
  staff: [PenaltyChoice('7', 'علی'), PenaltyChoice('8', 'مریم')],
  reasons: [PenaltyChoice('late', 'تأخیر'), PenaltyChoice('other', 'سایر')],
);

Widget _wrap(Widget child) => MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: child),
      ),
    );

void main() {
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));

  testWidgets('a fine is sent with who, why and how much', (tester) async {
    final api = _Api();
    await tester
        .pumpWidget(_wrap(PenaltyRequestForm(api: api, options: _options)));
    await tester.pumpAndSettle();

    // Nothing chosen yet: the form refuses before the server does.
    await tester.tap(find.text('ارسال برای تأیید مدیر'));
    await tester.pump();
    expect(api.sent, isNull);

    await tester.tap(find.text('کارمند'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('علی').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('علت'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تأخیر').last);
    await tester.pumpAndSettle();
    await tester.enterText(
        find.widgetWithText(TextField, 'مبلغ جریمه (تومان)'), '۵۰۰۰۰۰');
    await tester.tap(find.text('ارسال برای تأیید مدیر'));
    await tester.pumpAndSettle();

    expect(api.sent,
        {'user_id': 7, 'type': 'fine', 'reason': 'late', 'amount': 500000.0});
  });

  testWidgets('a warning asks for no amount', (tester) async {
    await tester
        .pumpWidget(_wrap(PenaltyRequestForm(api: _Api(), options: _options)));
    await tester.pumpAndSettle();
    expect(find.text('مبلغ جریمه (تومان)'), findsOneWidget);
    await tester.tap(find.text('اخطار'));
    await tester.pumpAndSettle();
    expect(find.text('مبلغ جریمه (تومان)'), findsNothing);
  });

  testWidgets('the seller sees each answer in words', (tester) async {
    PenaltyRequest r(String status, {bool cancelled = false}) =>
        PenaltyRequest.fromJson({
          'id': 1,
          'user_name': 'علی',
          'type': 'fine',
          'reason_label': 'تأخیر',
          'status': status,
          'status_label': cancelled ? 'لغو شد' : status,
          'is_pending': status == 'pending',
          'is_cancelled': cancelled,
          'decision_note': 'مرخصی داشت',
        });
    await tester.pumpWidget(_wrap(Column(children: [
      PenaltyRequestCard(request: r('rejected')),
      PenaltyRequestCard(request: r('approved')),
    ])));
    expect(find.text('دلیل رد: مرخصی داشت'), findsOneWidget);
    expect(find.text('در کسورات حقوق ثبت شد'), findsOneWidget);
  });

  testWidgets('the manager is warned about a late day', (tester) async {
    await tester.pumpWidget(_wrap(PenaltyRequestsCard(
      requests: [
        PenaltyRequest.fromJson({
          'id': 1,
          'user_name': 'علی',
          'type': 'fine',
          'status': 'pending',
          'is_pending': true,
          'late_warning': 'این شخص همین روز ۴۵ دقیقه تأخیر دارد',
        }),
      ],
      onApprove: (_) {},
      onReject: (_) {},
    )));
    expect(find.textContaining('تأخیر دارد'), findsOneWidget);
  });
}
