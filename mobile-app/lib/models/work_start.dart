/// The two daily activities that have a start deadline.
enum WorkStartType {
  chane('chane', 'شروع چانه‌گیری'),
  baking('baking', 'شروع پخت');

  const WorkStartType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static WorkStartType fromApi(String? value) => WorkStartType.values.firstWhere(
        (t) => t.apiValue == value,
        orElse: () => WorkStartType.chane,
      );
}

/// One activity's state for today: whether it has been ticked, whether it
/// was late, and how long is left before the deadline.
class WorkStartItem {
  const WorkStartItem({
    required this.type,
    required this.label,
    required this.deadline,
    required this.started,
    required this.isLate,
    required this.lateMinutes,
    required this.overdue,
    required this.isHoliday,
    this.startedAt,
    this.startedBy,
    this.warning,
    this.minutesRemaining,
  });

  final WorkStartType type;
  final String label;
  final String deadline;
  final bool started;
  final bool isLate;
  final int lateMinutes;
  final bool overdue;
  final bool isHoliday;
  final String? startedAt;
  final String? startedBy;
  final String? warning;
  final int? minutesRemaining;

  /// True when the deadline is close enough to be worth warning about.
  bool get isApproaching =>
      !started &&
      !isHoliday &&
      minutesRemaining != null &&
      minutesRemaining! >= 0 &&
      minutesRemaining! <= 20;

  factory WorkStartItem.fromJson(Map<String, dynamic> json) {
    return WorkStartItem(
      type: WorkStartType.fromApi(json['type'] as String?),
      label: json['label'] as String? ?? '',
      deadline: json['deadline'] as String? ?? '',
      started: json['started'] == true,
      isLate: json['is_late'] == true,
      lateMinutes: _int(json['late_minutes']),
      overdue: json['overdue'] == true,
      isHoliday: json['is_holiday'] == true,
      startedAt: json['started_at'] as String?,
      startedBy: json['started_by'] as String?,
      warning: json['warning'] as String?,
      minutesRemaining: json['minutes_remaining'] == null
          ? null
          : _int(json['minutes_remaining']),
    );
  }

  static int _int(dynamic value) =>
      value is num ? value.toInt() : int.tryParse('$value') ?? 0;
}

/// The published late-start rules, shown to every member of staff so the
/// tariff is known in advance rather than discovered on payday.
class LateTariff {
  const LateTariff({
    required this.freeDays,
    required this.summary,
    required this.tier1Formatted,
    required this.tier2Formatted,
  });

  final int freeDays;
  final String summary;
  final String tier1Formatted;
  final String tier2Formatted;

  factory LateTariff.fromJson(Map<String, dynamic> json) {
    return LateTariff(
      freeDays: WorkStartItem._int(json['free_days']),
      summary: json['summary'] as String? ?? '',
      tier1Formatted: json['tier1_amount_formatted'] as String? ?? '',
      tier2Formatted: json['tier2_amount_formatted'] as String? ?? '',
    );
  }
}

/// This month's late days and what they have cost so far.
class LateMonthSummary {
  const LateMonthSummary({
    required this.periodLabel,
    required this.lateDays,
    required this.warningsRemaining,
    required this.penaltyFormatted,
    required this.nextDayAmountFormatted,
  });

  final String periodLabel;
  final int lateDays;
  final int warningsRemaining;
  final String penaltyFormatted;
  final String nextDayAmountFormatted;

  factory LateMonthSummary.fromJson(Map<String, dynamic> json) {
    return LateMonthSummary(
      periodLabel: json['period_label'] as String? ?? '',
      lateDays: WorkStartItem._int(json['late_days']),
      warningsRemaining: WorkStartItem._int(json['warnings_remaining']),
      penaltyFormatted: json['penalty_total_formatted'] as String? ?? '',
      nextDayAmountFormatted:
          json['next_day_amount_formatted'] as String? ?? '',
    );
  }
}

/// Today's board for both activities.
class WorkStartBoard {
  const WorkStartBoard({
    required this.dateDisplay,
    required this.isHoliday,
    required this.items,
    this.tariff,
    this.monthSummary,
  });

  final String dateDisplay;
  final bool isHoliday;
  final List<WorkStartItem> items;
  final LateTariff? tariff;
  final LateMonthSummary? monthSummary;

  WorkStartItem? of(WorkStartType type) {
    for (final item in items) {
      if (item.type == type) return item;
    }
    return null;
  }

  /// Anything late or already past its deadline, for the warning banner.
  List<WorkStartItem> get problems =>
      items.where((i) => i.isLate || i.overdue).toList();

  factory WorkStartBoard.fromJson(Map<String, dynamic> json) {
    return WorkStartBoard(
      dateDisplay: json['date_display'] as String? ?? '',
      isHoliday: json['is_holiday'] == true,
      items: ((json['items'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(WorkStartItem.fromJson)
          .toList(),
      tariff: json['tariff'] is Map
          ? LateTariff.fromJson(
              (json['tariff'] as Map).cast<String, dynamic>())
          : null,
      monthSummary: json['month_summary'] is Map
          ? LateMonthSummary.fromJson(
              (json['month_summary'] as Map).cast<String, dynamic>())
          : null,
    );
  }
}
