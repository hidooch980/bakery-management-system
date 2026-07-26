/// Which shaping system a figure belongs to.
enum ChaneSystem {
  normal('چانه عادی'),
  nanino('چانه نانینو');

  const ChaneSystem(this.label);

  final String label;
}

/// The production board: what is waiting for the oven, and how today's
/// output splits between the nanino system and normal shaping.
class ChaneBoard {
  const ChaneBoard({
    required this.dateDisplay,
    required this.waitingChane,
    required this.waitingBatches,
    required this.normalCount,
    required this.naninoCount,
    required this.normalWeightKg,
    required this.naninoWeightKg,
    required this.pendingDoughBatches,
    required this.pendingDoughBags,
    this.naninoEquivalent,
    this.naninoAnnouncement,
    this.doughBagsToday = 0,
    this.doughAsNaninoCount,
    this.doughAsNaninoAnnouncement,
  });

  final String dateDisplay;

  final int waitingChane;
  final int waitingBatches;

  final int normalCount;
  final int naninoCount;
  final double normalWeightKg;
  final double naninoWeightKg;

  final int pendingDoughBatches;
  final int pendingDoughBags;

  /// What-if: how many nanino loaves today's normal chane would be, had it
  /// been shaped as nanino instead. Null when the two chane weights are not
  /// both configured. Not a count of anything actually baked.
  final int? naninoEquivalent;

  final String? naninoAnnouncement;

  /// Today's kneaded dough, and how many nanino loaves it could make. A
  /// display figure covering the whole day's raw material — including
  /// dough not yet shaped — rather than what was actually produced.
  final int doughBagsToday;
  final int? doughAsNaninoCount;
  final String? doughAsNaninoAnnouncement;

  int get totalCount => normalCount + naninoCount;

  double get totalWeightKg => normalWeightKg + naninoWeightKg;

  /// Share of today's chane made the normal way, as a 0-1 fraction.
  double get normalShare => totalCount > 0 ? normalCount / totalCount : 0;

  double get naninoShare => totalCount > 0 ? naninoCount / totalCount : 0;

  /// How many more chane one system produced than the other.
  int get countDifference => (normalCount - naninoCount).abs();

  double get weightDifferenceKg => (normalWeightKg - naninoWeightKg).abs();

  /// Which system produced more, or null when they are level.
  ChaneSystem? get leader {
    if (normalCount == naninoCount) return null;

    return normalCount > naninoCount ? ChaneSystem.normal : ChaneSystem.nanino;
  }

  /// Normal output as a multiple of nanino output, e.g. 3.0 means three times
  /// as many. Null when there is no nanino output to compare against.
  double? get normalToNaninoRatio =>
      naninoCount > 0 ? normalCount / naninoCount : null;

  factory ChaneBoard.fromJson(Map<String, dynamic> json) {
    final waiting = _section(json['waiting']);
    final today = _section(json['today']);
    final queues = _section(json['queues']);
    final doughToday = _section(json['dough_today']);

    return ChaneBoard(
      dateDisplay: json['date_display'] as String? ?? '',
      waitingChane: _int(waiting['chane_count']),
      waitingBatches: _int(waiting['batches']),
      normalCount: _int(today['normal_count']),
      naninoCount: _int(today['nanino_count']),
      normalWeightKg: _double(today['normal_weight_kg']),
      naninoWeightKg: _double(today['nanino_weight_kg']),
      pendingDoughBatches: _int(queues['pending_dough_batches']),
      pendingDoughBags: _int(queues['pending_dough_bags']),
      naninoEquivalent: today['normal_as_nanino_equivalent'] == null
          ? null
          : _int(today['normal_as_nanino_equivalent']),
      naninoAnnouncement: today['normal_as_nanino_announcement'] as String?,
      doughBagsToday: _int(doughToday['bags']),
      doughAsNaninoCount: doughToday['as_nanino_count'] == null
          ? null
          : _int(doughToday['as_nanino_count']),
      doughAsNaninoAnnouncement: doughToday['as_nanino_announcement'] as String?,
    );
  }

  /// Nested objects are read defensively, so a missing or oddly typed
  /// section degrades to zeros instead of throwing.
  static Map<String, dynamic> _section(dynamic value) {
    if (value is Map) return value.map((k, v) => MapEntry('$k', v));

    return const {};
  }

  static int _int(dynamic value) {
    if (value is num) return value.toInt();

    return int.tryParse('$value') ?? 0;
  }

  static double _double(dynamic value) {
    if (value is num) return value.toDouble();

    return double.tryParse('$value') ?? 0;
  }
}
