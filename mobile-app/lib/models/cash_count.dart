/// A count of the notes in the drawer, against what the books expected.
///
/// The difference is the only figure that matters, and the server has
/// already worked it out and said it in words — «کسری»، «اضافه»،
/// «می‌خواند». The app does not recompute it: a sign glanced at on a busy
/// morning is the one figure nobody should misread, and two places
/// deciding what a minus means is how they come to disagree.
class CashCount {
  const CashCount({
    required this.id,
    required this.countedFormatted,
    required this.expectedFormatted,
    required this.differenceFormatted,
    required this.differenceLabel,
    required this.isExact,
    required this.adjusted,
    required this.countedAt,
    this.countedBy,
    this.note,
  });

  final int id;
  final String countedFormatted;
  final String expectedFormatted;

  /// Always the size of the gap, never its sign — [differenceLabel] carries
  /// the direction in words.
  final String differenceFormatted;
  final String differenceLabel;

  final bool isExact;

  /// Whether the books were brought into line with this count.
  final bool adjusted;

  final String countedAt;
  final String? countedBy;
  final String? note;

  static String _text(dynamic value) => value is String ? value : '';

  static bool _bool(dynamic value) => value == true || value == 1 || value == '1';

  factory CashCount.fromJson(Map<String, dynamic> json) => CashCount(
        id: (json['id'] as num?)?.toInt() ?? 0,
        countedFormatted: _text(json['counted_formatted']),
        expectedFormatted: _text(json['expected_formatted']),
        differenceFormatted: _text(json['difference_formatted']),
        differenceLabel: _text(json['difference_label']),
        isExact: _bool(json['is_exact']),
        adjusted: _bool(json['adjusted']),
        countedAt: _text(json['counted_at']),
        countedBy: json['counted_by'] is String ? json['counted_by'] as String : null,
        note: json['note'] is String ? json['note'] as String : null,
      );
}

/// The drawer as the books see it, plus every count made against it.
class CashCountBook {
  const CashCountBook({
    required this.expectedFormatted,
    required this.counts,
    this.lastCountedAt,
    this.daysSinceCount,
  });

  final String expectedFormatted;
  final List<CashCount> counts;
  final String? lastCountedAt;
  final int? daysSinceCount;

  bool get neverCounted => lastCountedAt == null;

  factory CashCountBook.fromJson(Map<String, dynamic> json) => CashCountBook(
        expectedFormatted: json['expected_formatted'] is String
            ? json['expected_formatted'] as String
            : '',
        lastCountedAt:
            json['last_counted_at'] is String ? json['last_counted_at'] as String : null,
        daysSinceCount: (json['days_since_count'] as num?)?.toInt(),
        counts: [
          for (final row in (json['counts'] as List?) ?? const [])
            if (row is Map<String, dynamic>) CashCount.fromJson(row),
        ],
      );
}
