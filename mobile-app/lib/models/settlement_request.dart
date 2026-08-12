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


/// The seller's running account: one figure they can pay against.
///
/// [balance] is what they owe after the credit the shop is already holding
/// for them; [debt] is before it. Paying is against the balance, so a
/// seller with credit is never asked for money the shop already has.
class SellerRunningAccount {
  const SellerRunningAccount({
    required this.debt,
    required this.credit,
    required this.balance,
    required this.debtFormatted,
    required this.creditFormatted,
    required this.balanceFormatted,
    this.uncollectedCredit = 0,
    this.uncollectedCreditFormatted = '',
  });

  final double debt;
  final double credit;
  final double balance;

  final String debtFormatted;
  final String creditFormatted;
  final String balanceFormatted;

  /// Money still with the customers rather than the seller. Shown apart,
  /// because the seller cannot hand over what they never collected.
  final double uncollectedCredit;
  final String uncollectedCreditFormatted;

  bool get hasCredit => credit > 0;

  bool get hasNothingToSettle => balance <= 0;

  factory SellerRunningAccount.fromJson(Map<String, dynamic> json) {
    double num_(String key) => (json[key] as num?)?.toDouble() ?? 0;

    return SellerRunningAccount(
      debt: num_('debt'),
      credit: num_('credit'),
      balance: num_('balance'),
      debtFormatted: '${json['debt_formatted'] ?? ''}',
      creditFormatted: '${json['credit_formatted'] ?? ''}',
      balanceFormatted: '${json['balance_formatted'] ?? ''}',
      uncollectedCredit: num_('uncollected_credit'),
      uncollectedCreditFormatted: '${json['uncollected_credit_formatted'] ?? ''}',
    );
  }
}
