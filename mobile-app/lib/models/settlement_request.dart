/// A seller's claim that they have handed their account over, and where it
/// stands. Sellers cannot clear their own debt — the admin confirms it —
/// so the app shows the state rather than acting on it.
class SettlementRequest {
  const SettlementRequest({
    required this.id,
    required this.amountFormatted,
    required this.status,
    required this.statusLabel,
    this.note,
    this.rejectionReason,
    this.requestedOnDisplay,
    this.confirmedBy,
    this.paidCashFormatted,
    this.paidCardFormatted,
  });

  final int id;
  final String amountFormatted;

  /// pending, confirmed or rejected.
  final String status;
  final String statusLabel;

  final String? note;
  final String? rejectionReason;
  final String? requestedOnDisplay;
  final String? confirmedBy;

  /// How the seller says they handed the money over. Cash reaches the
  /// admin by hand; the card share has already gone to the bank, so the
  /// two are declared separately rather than as one total.
  final String? paidCashFormatted;
  final String? paidCardFormatted;

  bool get isPending => status == 'pending';

  bool get isRejected => status == 'rejected';

  factory SettlementRequest.fromJson(Map<String, dynamic> json) =>
      SettlementRequest(
        id: (json['id'] as num?)?.toInt() ?? 0,
        amountFormatted: '${json['amount_formatted'] ?? ''}',
        status: '${json['status'] ?? 'pending'}',
        statusLabel: '${json['status_label'] ?? ''}',
        note: json['note'] as String?,
        rejectionReason: json['rejection_reason'] as String?,
        requestedOnDisplay: json['requested_on_display'] as String?,
        confirmedBy: json['confirmed_by'] as String?,
        paidCashFormatted: json['paid_cash_formatted'] as String?,
        paidCardFormatted: json['paid_card_formatted'] as String?,
      );
}

/// One open debt the seller may hand over. They tick the ones this money
/// covers rather than settling the whole account at once.
class SettleableLine {
  const SettleableLine({
    required this.id,
    required this.amount,
    required this.amountFormatted,
    required this.paymentLabel,
    this.soldOnDisplay,
    this.customer,
  });

  final int id;

  /// Kept as a number so the sheet can total the ticked lines without
  /// parsing the formatted string back apart.
  final double amount;
  final String amountFormatted;
  final String paymentLabel;
  final String? soldOnDisplay;
  final String? customer;

  factory SettleableLine.fromJson(Map<String, dynamic> json) => SettleableLine(
        id: (json['id'] as num?)?.toInt() ?? 0,
        amount: (json['amount'] as num?)?.toDouble() ?? 0,
        amountFormatted: '${json['amount_formatted'] ?? ''}',
        paymentLabel: '${json['payment_label'] ?? ''}',
        soldOnDisplay: json['sold_on_display'] as String?,
        customer: json['customer'] as String?,
      );
}
