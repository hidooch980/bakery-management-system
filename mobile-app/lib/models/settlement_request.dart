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
