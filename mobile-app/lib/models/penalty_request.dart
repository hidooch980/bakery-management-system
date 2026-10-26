/// A penalty the seller asked for, and the manager's answer.
///
/// Only an approved one is docked from pay; a warning is on the record and
/// worth nothing.
class PenaltyRequest {
  const PenaltyRequest({
    required this.id,
    required this.userName,
    required this.type,
    required this.typeLabel,
    required this.reasonLabel,
    required this.status,
    required this.statusLabel,
    required this.isPending,
    required this.requestedAtLabel,
    this.isCancelled = false,
    this.amount,
    this.amountFormatted,
    this.note,
    this.requestedByName,
    this.decidedByName,
    this.decisionNote,
    this.lateWarning,
  });

  factory PenaltyRequest.fromJson(Map<String, dynamic> json) => PenaltyRequest(
        id: json['id'] as int,
        userName: json['user_name'] as String? ?? '',
        type: json['type'] as String? ?? 'fine',
        typeLabel: json['type_label'] as String? ?? '',
        reasonLabel: json['reason_label'] as String? ?? '',
        status: json['status'] as String? ?? 'pending',
        statusLabel: json['status_label'] as String? ?? '',
        isPending: json['is_pending'] as bool? ?? false,
        isCancelled: json['is_cancelled'] as bool? ?? false,
        requestedAtLabel: json['requested_at_label'] as String? ?? '',
        amount: (json['amount'] as num?)?.toDouble(),
        amountFormatted: json['amount_formatted'] as String?,
        note: json['note'] as String?,
        requestedByName: json['requested_by_name'] as String?,
        decidedByName: json['decided_by_name'] as String?,
        decisionNote: json['decision_note'] as String?,
        lateWarning: json['late_warning'] as String?,
      );

  final int id;
  final String userName;
  final String type;
  final String typeLabel;
  final String reasonLabel;
  final String status;
  final String statusLabel;
  final bool isPending;
  final bool isCancelled;
  final String requestedAtLabel;
  final double? amount;
  final String? amountFormatted;
  final String? note;
  final String? requestedByName;
  final String? decidedByName;
  final String? decisionNote;
  final String? lateWarning;

  bool get isFine => type == 'fine';
  bool get isApproved => status == 'approved' && !isCancelled;
  bool get isRejected => status == 'rejected';
}

class PenaltyChoice {
  const PenaltyChoice(this.key, this.label);
  final String key;
  final String label;
}

class PenaltyOptions {
  const PenaltyOptions({required this.staff, required this.reasons});

  factory PenaltyOptions.fromJson(Map<String, dynamic> json) => PenaltyOptions(
        staff: ((json['staff'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map((s) => PenaltyChoice('${s['id']}', s['name'] as String? ?? ''))
            .toList(),
        reasons: ((json['reasons'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map((r) => PenaltyChoice(r['key'] as String, r['label'] as String))
            .toList(),
      );

  final List<PenaltyChoice> staff;
  final List<PenaltyChoice> reasons;
}
