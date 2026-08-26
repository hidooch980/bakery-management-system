import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Text with no colour inherits from the ambient DefaultTextStyle, whose
/// fallback is black — not from the theme. On this app's near-black ground
/// that is an invisible label, and it looks like missing data rather than
/// like a styling mistake.
///
/// Found 1405/06/04, reported as «لیست افراد شیشه‌ای هست»: the attendance
/// list arrived, the check-in times showed (they set a colour explicitly)
/// and the names beside them did not. The request was fine. The paint was
/// not.
///
/// A widget test would have to be written per screen and would still miss
/// the next one, so this reads the source instead. It is a lint, and it is
/// deliberately narrow: `const TextStyle` cannot reference the theme, so
/// on this codebase a const style without a colour is always this bug.
void main() {
  test('no const TextStyle is left without a colour', () {
    final offenders = <String>[];

    for (final file in Directory('lib').listSync(recursive: true)) {
      if (file is! File || !file.path.endsWith('.dart')) continue;

      final lines = file.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        final line = lines[i];
        if (!line.contains('const TextStyle(')) continue;

        // `textStyle:` inside a ButtonStyle or a theme is not this bug.
        // There the widget supplies the colour itself — a FilledButton
        // paints its label with foregroundColor — and a colour written
        // into the TextStyle would fight it. Only a `style:` handed to a
        // Text has nowhere else to get one.
        //
        // The first version of this test flagged one of those and called
        // it invisible, which it is not.
        if (line.contains('textStyle:')) continue;

        // The declaration may wrap; look at the next few lines too.
        final window = lines.skip(i).take(4).join(' ');
        if (window.contains('color:')) continue;

        offenders.add('${file.path}:${i + 1}');
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'These render black on the app\'s dark ground, so the text is '
          'there and cannot be read. Use '
          'Theme.of(context).textTheme.…copyWith(color: scheme.onSurface) '
          'instead:\n${offenders.join('\n')}',
    );
  });
}
