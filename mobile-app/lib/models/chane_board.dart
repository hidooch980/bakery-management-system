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

  int get totalCount => normalCount + naninoCount;

  double get totalWeightKg => normalWeightKg + naninoWeightKg;

  /// Share of today's chane made the normal way, as a 0-1 fraction.
  double get normalShare => totalCount > 0 ? normalCount / totalCount : 0;

  double get naninoShare => totalCount > 0 ? naninoCount / totalCount : 0;

  factory ChaneBoard.fromJson(Map<String, dynamic> json) {
    final waiting = _section(json['waiting']);
    final today = _section(json['today']);
    final queues = _section(json['queues']);

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
