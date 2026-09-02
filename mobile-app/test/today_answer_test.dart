import 'package:bakery_app/models/today_answer.dart';
import 'package:flutter_test/flutter_test.dart';

/// The phone draws what the server says and invents nothing.
///
/// Every sentence on «امروز» is composed in `TodayAnswer` on the server —
/// the same class the panel reads — so the two cannot come to different
/// conclusions about the same shop. What is tested here is the parsing
/// around that: the phone must not quietly substitute a default that
/// changes the meaning.
void main() {
  Map<String, dynamic> sound() => {
        'tone': 'sound',
        'system': 'مغازه امروز سالم است.',
        'yours': 'سه چیز کار شماست.',
        'cycles': 8,
        'sound': true,
        'failures': <String>[],
        'warnings': ['دورهٔ این ماه که رقم کارتخوانش وارد نشده: 3'],
        'needs': [
          {
            'key': 'loan-due-1',
            'severity': 'critical',
            'title': 'قسط «وام صادرات» عقب افتاده است',
            'detail': 'قسط ۴۰٬۰۰۰٬۰۰۰ ریال سررسید ۱۴۰۵/۰۶/۱۰',
            'suggestion': 'اگر پرداخت شده، ثبتش کنید.',
          },
        ],
        'figures': [
          {'label': 'آرد', 'value': '۶۵٫۲ کیسه'},
        ],
      };

  test('it carries the server\'s words through unchanged', () {
    final answer = TodayAnswer.fromJson(sound());

    expect(answer.system, 'مغازه امروز سالم است.');
    expect(answer.yours, 'سه چیز کار شماست.');
    expect(answer.tone, TodayTone.sound);
    expect(answer.cycles, 8);
  });

  test('a critical need is marked as one', () {
    final answer = TodayAnswer.fromJson(sound());

    expect(answer.needs.single.isCritical, isTrue);
    expect(answer.needs.single.isWarning, isFalse);
    expect(answer.needs.single.title, contains('وام صادرات'));
  });

  test('the three tones are told apart', () {
    expect(TodayTone.parse('clear'), TodayTone.clear);
    expect(TodayTone.parse('sound'), TodayTone.sound);
    expect(TodayTone.parse('fail'), TodayTone.fail);
  });

  /// An unknown tone must not read as «fail». A future tone the phone has
  /// not been taught would otherwise paint a healthy shop as broken, and
  /// an app that cries wolf after a server deploy is worse than one that
  /// stays quiet.
  test('a tone it has never seen reads as sound, not broken', () {
    expect(TodayTone.parse('something_new'), TodayTone.sound);
    expect(TodayTone.parse(null), TodayTone.sound);
  });

  test('a broken shop keeps its failures', () {
    final answer = TodayAnswer.fromJson({
      ...sound(),
      'tone': 'fail',
      'sound': false,
      'system': 'سیستم با خودش نمی‌خواند.',
      'failures': ['موجودی آرد منفی است: -500'],
    });

    expect(answer.sound, isFalse);
    expect(answer.tone, TodayTone.fail);
    expect(answer.failures.single, contains('منفی'));
  });

  /// A shop with nothing waiting is a real state, not an error, and the
  /// screen has a sentence for it.
  test('an empty answer parses to empty lists rather than nulls', () {
    final answer = TodayAnswer.fromJson({
      'tone': 'clear',
      'system': 'مغازه امروز سالم است.',
      'yours': 'هیچ چیز کار شما نیست.',
      'cycles': 8,
      'sound': true,
    });

    expect(answer.needs, isEmpty);
    expect(answer.warnings, isEmpty);
    expect(answer.failures, isEmpty);
    expect(answer.figures, isEmpty);
    expect(answer.tone, TodayTone.clear);
  });

  test('figures arrive ready to draw, already in Persian digits', () {
    final answer = TodayAnswer.fromJson(sound());

    expect(answer.figures.single.label, 'آرد');
    expect(answer.figures.single.value, '۶۵٫۲ کیسه');
  });
}
