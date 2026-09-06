import 'package:bakery_app/models/today_answer.dart';
import 'package:flutter_test/flutter_test.dart';

/// «امروز» now says what the next few days look like.
///
/// The phone draws it and invents nothing, like every other line on that
/// screen. What is tested is the parsing: the basis must travel with the
/// number, an older server that sends no outlook must draw nothing, and a
/// tone this build has not been taught must not paint a fine forecast as
/// a warning.
void main() {
  Map<String, dynamic> answer({List<Map<String, dynamic>>? outlook}) => {
        'tone': 'sound',
        'system': 'مغازه امروز سالم است.',
        'yours': 'هیچ چیز کار شما نیست.',
        'cycles': 8,
        'sound': true,
        'failures': <String>[],
        'warnings': <String>[],
        'needs': <Map<String, dynamic>>[],
        'figures': <Map<String, dynamic>>[],
        if (outlook != null) 'outlook': outlook,
      };

  test('a forecast carries its basis with it', () {
    final parsed = TodayAnswer.fromJson(answer(outlook: [
      {
        'key': 'flour-days',
        'tone': 'attention',
        'title': 'آرد با این روند حدود ۲ روز کافی است.',
        'basis': 'میانگین ۱۴ روز اخیر: ۱۱۲ کیلو در روز (۱۲ روز پخت).',
      },
    ]));

    final line = parsed.outlook.single;

    expect(line.title, contains('۲ روز'));
    expect(line.basis, contains('۱۴ روز اخیر'));
    expect(line.needsAttention, isTrue);
  });

  test('an older server that sends no outlook draws nothing', () {
    expect(TodayAnswer.fromJson(answer()).outlook, isEmpty);
  });

  test('a tone this build does not know reads as calm', () {
    final parsed = TodayAnswer.fromJson(answer(outlook: [
      {'key': 'x', 'tone': 'urgent-new-thing', 'title': 'چیزی', 'basis': ''},
    ]));

    expect(parsed.outlook.single.needsAttention, isFalse);
  });
}
