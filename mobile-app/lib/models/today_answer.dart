/// The shop's answer for today: whether it is sound, and what is the
/// owner's to do.
///
/// Every string here is composed on the server, in `TodayAnswer` — the
/// same class the panel reads. The phone deliberately builds no sentence
/// of its own: the alternative is «سالم» on one screen and something else
/// on the other, and an owner who learns to trust neither.
library;

/// How the answer should be read at a glance.
enum TodayTone {
  /// Sound, and nothing waiting.
  clear,

  /// Sound, and the shop has business to attend to.
  sound,

  /// The records contradict each other. Nothing below can be trusted.
  fail;

  static TodayTone parse(String? raw) => switch (raw) {
        'clear' => TodayTone.clear,
        'fail' => TodayTone.fail,
        _ => TodayTone.sound,
      };
}

/// One thing waiting for the owner.
class TodayNeed {
  const TodayNeed({
    required this.key,
    required this.severity,
    required this.title,
    required this.detail,
    required this.suggestion,
  });

  final String key;

  /// `critical`, `warning` or `info`, as the issue centre grades them.
  final String severity;

  final String title;
  final String detail;
  final String suggestion;

  bool get isCritical => severity == 'critical';
  bool get isWarning => severity == 'warning';

  factory TodayNeed.fromJson(Map<String, dynamic> json) => TodayNeed(
        key: json['key'] as String? ?? '',
        severity: json['severity'] as String? ?? 'info',
        title: json['title'] as String? ?? '',
        detail: json['detail'] as String? ?? '',
        suggestion: json['suggestion'] as String? ?? '',
      );
}

/// A figure for the quiet line at the bottom.
class TodayFigure {
  const TodayFigure({required this.label, required this.value});

  final String label;
  final String value;

  factory TodayFigure.fromJson(Map<String, dynamic> json) => TodayFigure(
        label: json['label'] as String? ?? '',
        value: json['value'] as String? ?? '',
      );
}

class TodayAnswer {
  const TodayAnswer({
    required this.tone,
    required this.system,
    required this.yours,
    required this.cycles,
    required this.sound,
    required this.failures,
    required this.warnings,
    required this.needs,
    required this.figures,
  });

  final TodayTone tone;

  /// «مغازه امروز سالم است.»
  final String system;

  /// «سه چیز کار شماست.»
  final String yours;

  /// How many cycles were checked. Sent by the server rather than written
  /// here, so adding one does not need an app release to stop the phone
  /// claiming the old number.
  final int cycles;

  final bool sound;

  final List<String> failures;
  final List<String> warnings;
  final List<TodayNeed> needs;
  final List<TodayFigure> figures;

  factory TodayAnswer.fromJson(Map<String, dynamic> json) => TodayAnswer(
        tone: TodayTone.parse(json['tone'] as String?),
        system: json['system'] as String? ?? '',
        yours: json['yours'] as String? ?? '',
        cycles: (json['cycles'] as num?)?.toInt() ?? 0,
        sound: json['sound'] as bool? ?? true,
        failures: ((json['failures'] as List<dynamic>?) ?? const [])
            .map((e) => e.toString())
            .toList(),
        warnings: ((json['warnings'] as List<dynamic>?) ?? const [])
            .map((e) => e.toString())
            .toList(),
        needs: ((json['needs'] as List<dynamic>?) ?? const [])
            .map((e) => TodayNeed.fromJson(e as Map<String, dynamic>))
            .toList(),
        figures: ((json['figures'] as List<dynamic>?) ?? const [])
            .map((e) => TodayFigure.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}
