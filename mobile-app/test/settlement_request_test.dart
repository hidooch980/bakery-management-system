import 'package:flutter_test/flutter_test.dart';

import 'package:bakery_app/models/settlement_request.dart';

void main() {
  group('SettlementRequest', () {
    test('reads a request still waiting on the admin', () {
      final request = SettlementRequest.fromJson({
        'id': 3,
        'amount_formatted': '۸۵۰٬۰۰۰ تومان',
        'status': 'pending',
        'status_label': 'در انتظار تأیید مدیر',
        'requested_on_display': '۱۴۰۵/۰۵/۰۳ ۱۴:۲۰',
      });

      expect(request.isPending, isTrue);
      expect(request.isRejected, isFalse);
      expect(request.amountFormatted, '۸۵۰٬۰۰۰ تومان');
    });

    test('carries the reason a request was turned down', () {
      final request = SettlementRequest.fromJson({
        'id': 4,
        'status': 'rejected',
        'status_label': 'رد شده',
        'rejection_reason': 'مبلغ تحویلی کمتر بود',
      });

      // Without the reason the seller only sees that nothing cleared.
      expect(request.isRejected, isTrue);
      expect(request.isPending, isFalse);
      expect(request.rejectionReason, 'مبلغ تحویلی کمتر بود');
    });

    test('names the admin who agreed to it', () {
      final request = SettlementRequest.fromJson({
        'id': 5,
        'status': 'confirmed',
        'status_label': 'تأیید شده',
        'confirmed_by': 'مدیر',
      });

      expect(request.isPending, isFalse);
      expect(request.confirmedBy, 'مدیر');
    });

    test('degrades to a pending request when fields are missing', () {
      final request = SettlementRequest.fromJson({});

      expect(request.isPending, isTrue);
      expect(request.rejectionReason, isNull);
    });
  });
}
