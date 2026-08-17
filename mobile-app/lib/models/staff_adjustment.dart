/// One reward or one penalty, written down on the day it was earned.
///
/// The payslip has always had a bonus box and a deduction box, both typed
/// at the moment of payment — which is the end of a long month, when
/// nobody remembers who came in late on the 12th. These are the same two
/// figures, recorded when they happen and with the reason attached, so the
/// month's total is arrived at rather than recalled.
class StaffAdjustment {
  const StaffAdjustment({
    required this.id,
    required this.kind,
    required this.kindLabel,
    required this.basis,
    required this.basisLabel,
    required this.value,
    required this.valueFormatted,
    required this.reason,
    required this.occurredOn,
    required this.isSettled,
    required this.isNoteOnly,
    this.userName,
    this.days,
  });

  final int id;

  /// 'reward' or 'penalty'.
  final String kind;
  final String kindLabel;

  /// 'amount', 'days' or 'note'.
  final String basis;

  /// «نیم روز», «بدون کسر», or empty when the figure speaks for itself.
  final String basisLabel;

  /// In the shop's display unit. Zero for a note-only entry, which is a
  /// different thing from an amount that happens to be zero — ask
  /// [isNoteOnly] rather than comparing this to zero.
  final double value;
  final String valueFormatted;

  final String reason;
  final String occurredOn;
  final bool isSettled;
  final bool isNoteOnly;
  final String? userName;
  final double? days;

  bool get isReward => kind == 'reward';

  factory StaffAdjustment.fromJson(Map<String, dynamic> json) => StaffAdjustment(
        id: (json['id'] as num?)?.toInt() ?? 0,
        kind: '${json['kind'] ?? 'reward'}',
        kindLabel: '${json['kind_label'] ?? ''}',
        basis: '${json['basis'] ?? 'amount'}',
        basisLabel: '${json['basis_label'] ?? ''}',
        value: _double(json['value']),
        valueFormatted: '${json['value_formatted'] ?? ''}',
        reason: '${json['reason'] ?? ''}',
        occurredOn: '${json['occurred_on_jalali'] ?? ''}',
        isSettled: json['is_settled'] == true,
        isNoteOnly: json['is_note_only'] == true,
        userName: (json['user'] as Map?)?['name'] as String?,
        days: json['days'] == null ? null : _double(json['days']),
      );

  static double _double(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
}

/// What one person's month came to, and what it is made of.
///
/// Two totals kept apart rather than netted: they land in two different
/// boxes on the payslip, and someone who earned a reward and took a
/// penalty in the same month is owed the sight of both.
class AdjustmentPeriod {
  const AdjustmentPeriod({
    required this.periodLabel,
    required this.rewardTotal,
    required this.rewardTotalFormatted,
    required this.penaltyTotal,
    required this.penaltyTotalFormatted,
    required this.items,
  });

  final String periodLabel;
  final double rewardTotal;
  final String rewardTotalFormatted;
  final double penaltyTotal;
  final String penaltyTotalFormatted;
  final List<StaffAdjustment> items;

  bool get isEmpty => items.isEmpty;

  factory AdjustmentPeriod.fromJson(Map<String, dynamic> json) => AdjustmentPeriod(
        periodLabel: '${json['period_label'] ?? ''}',
        rewardTotal: StaffAdjustment._double(json['reward_total']),
        rewardTotalFormatted: '${json['reward_total_formatted'] ?? ''}',
        penaltyTotal: StaffAdjustment._double(json['penalty_total']),
        penaltyTotalFormatted: '${json['penalty_total_formatted'] ?? ''}',
        items: ((json['items'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(StaffAdjustment.fromJson)
            .toList(),
      );
}
