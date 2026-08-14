/// The month's diesel quota, and what is left of it.
class DieselQuota {
  const DieselQuota({
    required this.monthLabel,
    required this.totalLitres,
    required this.deliveredLitres,
    required this.remainingLitres,
    required this.usedPercent,
    required this.isOverdrawn,
    required this.consumedLitres,
    required this.bagsBaked,
    required this.inTankLitres,
    required this.isTankEmpty,
    this.litresPerBag,
    this.derivationLabel,
  });

  factory DieselQuota.fromJson(Map<String, dynamic> json) => DieselQuota(
        monthLabel: json['month_label'] as String? ?? '',
        totalLitres: _d(json['total_litres']),
        deliveredLitres: _d(json['delivered_litres']),
        remainingLitres: _d(json['remaining_litres']),
        usedPercent: _d(json['used_percent']),
        isOverdrawn: json['is_overdrawn'] as bool? ?? false,
        consumedLitres: _d(json['consumed_litres']),
        bagsBaked: _d(json['bags_baked']),
        inTankLitres: _d(json['in_tank_litres']),
        isTankEmpty: json['is_tank_empty'] as bool? ?? false,
        litresPerBag: json['litres_per_bag'] == null
            ? null
            : _d(json['litres_per_bag']),
        derivationLabel: json['derivation_label'] as String?,
      );

  final String monthLabel;
  final double totalLitres;
  final double deliveredLitres;
  final double remainingLitres;
  final double usedPercent;

  /// True when the depot will issue no more this month.
  final bool isOverdrawn;

  /// Litres burned baking, estimated from the sacks that went into dough
  /// at the same rate the quota is built on.
  final double consumedLitres;
  final double bagsBaked;

  /// What should still be in the tank: what arrived, less what was burned.
  /// A different question from how much the depot will still issue — a
  /// month can be in credit with an empty tank, and only the empty tank
  /// stops the oven.
  final double inTankLitres;
  final bool isTankEmpty;

  /// What a sack earns this month. It moves: the depot allows more some
  /// months and less others.
  final double? litresPerBag;

  /// How the figure was arrived at, in words — "343 کیسه × 6.5 لیتر".
  final String? derivationLabel;
}

/// A tanker's worth of diesel arriving.
class DieselDelivery {
  const DieselDelivery({
    required this.id,
    required this.litres,
    required this.amountFormatted,
    required this.wasPaidFor,
    required this.receivedOnLabel,
    this.docketNumber,
    this.recordedBy,
    this.note,
  });

  factory DieselDelivery.fromJson(Map<String, dynamic> json) => DieselDelivery(
        id: json['id'] as int,
        litres: _d(json['litres']),
        amountFormatted: json['amount_formatted'] as String? ?? '',
        wasPaidFor: json['was_paid_for'] as bool? ?? false,
        receivedOnLabel: json['received_on_label'] as String? ?? '',
        docketNumber: json['docket_number'] as String?,
        recordedBy: json['recorded_by'] as String?,
        note: json['note'] as String?,
      );

  final int id;
  final double litres;
  final String amountFormatted;
  final bool wasPaidFor;
  final String receivedOnLabel;
  final String? docketNumber;
  final String? recordedBy;
  final String? note;
}

/// Money handed to a member of staff before payday.
class StaffAdvance {
  const StaffAdvance({
    required this.id,
    required this.userName,
    required this.amountFormatted,
    required this.recoveredFormatted,
    required this.outstanding,
    required this.outstandingFormatted,
    required this.isSettled,
    required this.paidOnLabel,
    this.note,
  });

  factory StaffAdvance.fromJson(Map<String, dynamic> json) => StaffAdvance(
        id: json['id'] as int,
        userName: json['user_name'] as String? ?? '',
        amountFormatted: json['amount_formatted'] as String? ?? '',
        recoveredFormatted: json['recovered_formatted'] as String? ?? '',
        outstanding: _d(json['outstanding']),
        outstandingFormatted: json['outstanding_formatted'] as String? ?? '',
        isSettled: json['is_settled'] as bool? ?? false,
        paidOnLabel: json['paid_on_label'] as String? ?? '',
        note: json['note'] as String?,
      );

  final int id;
  final String userName;
  final String amountFormatted;
  final String recoveredFormatted;
  final double outstanding;
  final String outstandingFormatted;
  final bool isSettled;
  final String paidOnLabel;
  final String? note;
}

/// A request for pay early, and the answer to it.
class AdvanceRequest {
  const AdvanceRequest({
    required this.id,
    required this.userName,
    required this.amountFormatted,
    required this.status,
    required this.statusLabel,
    required this.isPending,
    required this.requestedAtLabel,
    this.reason,
    this.decisionNote,
    this.decidedByName,
  });

  factory AdvanceRequest.fromJson(Map<String, dynamic> json) => AdvanceRequest(
        id: json['id'] as int,
        userName: json['user_name'] as String? ?? '',
        amountFormatted: json['amount_formatted'] as String? ?? '',
        status: json['status'] as String? ?? 'pending',
        statusLabel: json['status_label'] as String? ?? '',
        isPending: json['is_pending'] as bool? ?? false,
        requestedAtLabel: json['requested_at_label'] as String? ?? '',
        reason: json['reason'] as String?,
        decisionNote: json['decision_note'] as String?,
        decidedByName: json['decided_by_name'] as String?,
      );

  final int id;
  final String userName;
  final String amountFormatted;
  final String status;
  final String statusLabel;
  final bool isPending;
  final String requestedAtLabel;
  final String? reason;

  /// Why it was turned down. A bare "no" to someone asking for money early
  /// is worse than no answer at all, so this is shown wherever the status is.
  final String? decisionNote;
  final String? decidedByName;

  bool get wasApproved => status == 'approved';

  bool get wasRejected => status == 'rejected';
}

/// Server numbers arrive as int or double depending on the value.
double _d(Object? value) => switch (value) {
      final num n => n.toDouble(),
      final String s => double.tryParse(s) ?? 0,
      _ => 0,
    };

/// What one person is owed, as their own home screen shows it.
///
/// Two figures rather than one total, because they are different kinds of
/// truth: an issued payslip is a debt the shop has already accepted, and
/// what is left of this month's wage is a forecast that the month can still
/// change. Adding them would produce a number that is neither.
class PaySummary {
  const PaySummary({
    required this.periodLabel,
    required this.advanceOutstandingFormatted,
    required this.advanceOutstanding,
    required this.unpaidPayslipsFormatted,
    required this.unpaidPayslipsCount,
    required this.carriesOver,
    required this.hasPendingRequest,
    required this.summary,
    this.monthlySalaryFormatted,
    this.remainingFormatted,
  });

  factory PaySummary.fromJson(Map<String, dynamic> json) => PaySummary(
        periodLabel: json['period_label'] as String? ?? '',
        monthlySalaryFormatted: json['monthly_salary_formatted'] as String?,
        advanceOutstanding: _d(json['advance_outstanding']),
        advanceOutstandingFormatted:
            json['advance_outstanding_formatted'] as String? ?? '',
        unpaidPayslipsFormatted:
            json['unpaid_payslips_total_formatted'] as String? ?? '',
        unpaidPayslipsCount: json['unpaid_payslips_count'] as int? ?? 0,
        remainingFormatted: json['remaining_formatted'] as String?,
        carriesOver: json['carries_over'] as bool? ?? false,
        hasPendingRequest: json['has_pending_request'] as bool? ?? false,
        summary: json['summary'] as String? ?? '',
      );

  final String periodLabel;

  /// Null until an admin has set what this person is paid.
  final String? monthlySalaryFormatted;

  final double advanceOutstanding;
  final String advanceOutstandingFormatted;

  final String unpaidPayslipsFormatted;
  final int unpaidPayslipsCount;

  /// What is left of this month's wage. Null when no wage is on record.
  final String? remainingFormatted;

  /// The advances already exceed a month's wage, so the rest comes off the
  /// month after. Said outright, because a remainder of zero otherwise
  /// reads as "nothing more is owed".
  final bool carriesOver;

  final bool hasPendingRequest;
  final String summary;

  bool get owesAdvance => advanceOutstanding > 0;

  bool get hasUnpaidPayslips => unpaidPayslipsCount > 0;
}
