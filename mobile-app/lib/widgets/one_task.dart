/// One question, the whole screen, and nothing else to touch.
///
/// The production roles — خمیرگیر, چانه‌گیر — record one or two numbers a
/// day and nothing more. They were given the same shape as the seller's
/// screen: a scrolling page with sections, a queue, a history, a menu.
/// Everything on it was a place to tap wrongly, and the number they came
/// to enter was one field among several.
///
/// So the app asks. «چند کیسه خمیر گرفتی؟» fills the screen, the answer is
/// a number the size of a fist, and there is exactly one button. Answer it
/// and the next question arrives. Nothing is hidden behind a menu because
/// there is no menu — at each moment there is one thing to do, which is
/// what makes it hard to do the wrong one.
///
/// The pieces here are deliberately dumb: they hold a value and report it.
/// What the questions are, and what happens to the answers, belongs to the
/// screen that uses them.
library;

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_theme.dart';

/// The frame every question shares: the step counter, the question, the
/// body, and one button along the bottom.
class OneTaskScaffold extends StatelessWidget {
  const OneTaskScaffold({
    super.key,
    required this.question,
    required this.child,
    required this.actionLabel,
    this.onAction,
    this.step,
    this.of,
    this.hint,
    this.onBack,
    this.busy = false,
    this.secondary,
  });

  /// Asked in the second person, as a person would ask it.
  final String question;

  /// Whatever answers it — a stepper, a keypad, a list to choose from.
  final Widget child;

  final String actionLabel;

  /// Null disables the button, which is how a question with no answer yet
  /// refuses to be submitted.
  final VoidCallback? onAction;

  /// «۱ از ۲». Both null on a flow of one step, which then shows nothing
  /// rather than a pointless «۱ از ۱».
  final int? step;
  final int? of;

  /// One line under the answer — what was recorded last time, what the
  /// batch should yield. Never an instruction.
  final String? hint;

  /// Shown as a back arrow. Null on the first question.
  final VoidCallback? onBack;

  final bool busy;

  /// A quiet way out, under the button — «امروز چیزی نگرفتم».
  final Widget? secondary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurface.withValues(alpha: 0.62);

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                if (onBack != null)
                  IconButton(
                    onPressed: busy ? null : onBack,
                    icon: const Icon(Icons.arrow_forward_rounded),
                    tooltip: 'برگشت',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(minWidth: 44, minHeight: 44),
                  ),
                if (step != null && of != null && of! > 1)
                  Text(
                    '$step از $of',
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: AppColors.signalFor(theme.brightness),
                      fontWeight: FontWeight.w500,
                      letterSpacing: 1.2,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              question,
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w700,
                height: 1.4,
              ),
            ),
            Expanded(child: Center(child: child)),
            if (hint != null) ...[
              Text(
                hint!,
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              const SizedBox(height: 14),
            ],
            _BigButton(
              label: actionLabel,
              onPressed: busy ? null : onAction,
              busy: busy,
            ),
            if (secondary != null) ...[
              const SizedBox(height: 6),
              Center(child: secondary!),
            ],
          ],
        ),
      ),
    );
  }
}

/// The one button. Tall enough to hit without looking.
class _BigButton extends StatelessWidget {
  const _BigButton({required this.label, this.onPressed, this.busy = false});

  final String label;
  final VoidCallback? onPressed;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 62,
      child: FilledButton(
        onPressed: onPressed,
        style: FilledButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
        ),
        child: busy
            ? const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2.4),
              )
            : Text(label),
      ),
    );
  }
}

/// A number answered by tapping, for the counts that barely move.
///
/// The shop bakes thirteen bags most days, so the answer opens on what was
/// used last time and the usual day needs no typing at all. Holding a
/// button repeats, because the day that is not usual should not cost
/// twenty taps.
class OneTaskCounter extends StatelessWidget {
  const OneTaskCounter({
    super.key,
    required this.value,
    required this.onChanged,
    this.min = 1,
    this.max = 99,
    this.unit,
  });

  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;
  final String? unit;

  void _step(int by) {
    final next = (value + by).clamp(min, max);

    if (next != value) {
      HapticFeedback.selectionClick();
      onChanged(next);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          '$value',
          style: theme.textTheme.displayLarge?.copyWith(
            fontSize: 104,
            height: 1,
            fontWeight: FontWeight.w700,
            letterSpacing: -3,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        if (unit != null)
          Text(
            unit!,
            style: theme.textTheme.titleMedium?.copyWith(
              color: theme.colorScheme.onSurface.withValues(alpha: 0.55),
            ),
          ),
        const SizedBox(height: 26),
        Row(
          children: [
            Expanded(
              child: _StepButton(
                icon: Icons.remove_rounded,
                label: 'یکی کمتر',
                onPressed: value > min ? () => _step(-1) : null,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _StepButton(
                icon: Icons.add_rounded,
                label: 'یکی بیشتر',
                onPressed: value < max ? () => _step(1) : null,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// Holds to repeat.
///
/// Thirteen bags to thirty is seventeen taps otherwise, and the day that
/// is not the usual day is exactly the day nobody has a spare hand.
class _StepButton extends StatefulWidget {
  const _StepButton({required this.icon, required this.label, this.onPressed});

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  State<_StepButton> createState() => _StepButtonState();
}

class _StepButtonState extends State<_StepButton> {
  /// Long enough that an ordinary press is never mistaken for a hold.
  static const _beforeRepeating = Duration(milliseconds: 420);

  static const _between = Duration(milliseconds: 90);

  Timer? _timer;

  void _startRepeating() {
    if (widget.onPressed == null) return;

    _timer?.cancel();
    _timer = Timer(_beforeRepeating, () {
      _timer = Timer.periodic(_between, (_) {
        // The button disables itself at the end of its range, and a timer
        // that kept firing into a disabled button would spin forever.
        if (widget.onPressed == null) {
          _stop();

          return;
        }

        widget.onPressed!();
      });
    });
  }

  void _stop() {
    _timer?.cancel();
    _timer = null;
  }

  @override
  void dispose() {
    _stop();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 66,
      child: Listener(
        onPointerDown: (_) => _startRepeating(),
        onPointerUp: (_) => _stop(),
        onPointerCancel: (_) => _stop(),
        child: OutlinedButton(
          onPressed: widget.onPressed,
          style: OutlinedButton.styleFrom(
            side: BorderSide(
              color: widget.onPressed == null
                  ? Theme.of(context).colorScheme.outlineVariant
                  : AppColors.signalFor(Theme.of(context).brightness),
              width: 2,
            ),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
          child: Icon(widget.icon, size: 32, semanticLabel: widget.label),
        ),
      ),
    );
  }
}

/// A number answered by typing, for the counts that are different every
/// time — the chane out of a batch is never the same twice.
///
/// The app's own keypad rather than the system keyboard: the digits are
/// Persian, the keys are the size of a thumb, and no keyboard slides up
/// over the answer as it is being typed.
class OneTaskKeypad extends StatelessWidget {
  const OneTaskKeypad({
    super.key,
    required this.value,
    required this.onChanged,
    this.max = 99999,
    this.unit,
    this.looksWrong = false,
  });

  final int value;
  final ValueChanged<int> onChanged;
  final int max;
  final String? unit;

  /// Drawn in the warning colour rather than refused. The shop knows its
  /// own trade better than the formula does; it is being asked, not told.
  final bool looksWrong;

  void _press(String key) {
    HapticFeedback.selectionClick();

    if (key == '⌫') {
      onChanged(value ~/ 10);

      return;
    }

    final next = value * 10 + int.parse(key);

    if (next <= max) onChanged(next);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colour = looksWrong ? AppColors.warning : theme.colorScheme.onSurface;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          value == 0 ? '—' : '$value',
          style: theme.textTheme.displayLarge?.copyWith(
            fontSize: 76,
            height: 1,
            fontWeight: FontWeight.w700,
            letterSpacing: -2,
            color: colour,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        if (unit != null)
          Text(
            unit!,
            style: theme.textTheme.titleMedium?.copyWith(
              color: theme.colorScheme.onSurface.withValues(alpha: 0.55),
            ),
          ),
        const SizedBox(height: 18),
        for (final row in const [
          ['۷', '۸', '۹'],
          ['۴', '۵', '۶'],
          ['۱', '۲', '۳'],
        ])
          _KeyRow(keys: row, onPress: _press),
        _KeyRow(keys: const ['۰', '⌫'], onPress: _press, wideFirst: true),
      ],
    );
  }
}

class _KeyRow extends StatelessWidget {
  const _KeyRow({required this.keys, required this.onPress, this.wideFirst = false});

  static const _digits = {
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };

  final List<String> keys;
  final ValueChanged<String> onPress;
  final bool wideFirst;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        children: [
          for (final key in keys) ...[
            Expanded(
              flex: wideFirst && key == keys.first ? 2 : 1,
              child: SizedBox(
                height: 54,
                child: Material(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(10),
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: () => onPress(_digits[key] ?? key),
                    child: Center(
                      child: Text(
                        key,
                        style: theme.textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w500,
                          color: key == '⌫'
                              ? AppColors.signalFor(theme.brightness)
                              : theme.colorScheme.onSurface,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
            if (key != keys.last) const SizedBox(width: 8),
          ],
        ],
      ),
    );
  }
}

/// What the app says once the answer is in.
///
/// Recording is the moment a person most wants telling that it worked, and
/// the moment they are least likely to read anything. So: a tick, the
/// figures they just gave back to them in words, and one way onward.
class OneTaskDone extends StatelessWidget {
  const OneTaskDone({
    super.key,
    required this.headline,
    required this.summary,
    required this.actionLabel,
    required this.onAction,
    this.warning,
  });

  final String headline;

  /// What was recorded, in the shop's own words — «۱۳ کیسه خمیر».
  final List<String> summary;

  final String actionLabel;
  final VoidCallback onAction;

  /// Anything the record raised, shown under it rather than instead of it.
  final Widget? warning;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Spacer(),
            Center(
              child: Container(
                width: 76,
                height: 76,
                decoration: const BoxDecoration(
                  color: AppColors.success,
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.check_rounded, size: 42, color: Colors.white),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              headline,
              textAlign: TextAlign.center,
              style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 10),
            for (final line in summary)
              Text(
                line,
                textAlign: TextAlign.center,
                style: theme.textTheme.titleMedium?.copyWith(
                  color: theme.colorScheme.onSurface.withValues(alpha: 0.7),
                  height: 1.9,
                ),
              ),
            if (warning != null) ...[
              const SizedBox(height: 22),
              warning!,
            ],
            const Spacer(),
            _BigButton(label: actionLabel, onPressed: onAction),
          ],
        ),
      ),
    );
  }
}

/// The question the app asks back when the same batch arrives twice.
///
/// On 24 Mordad the seller pressed «ثبت خمیر» three times in thirty-five
/// minutes for one thirteen-bag batch, and the two that were never shaped
/// spent 1,040 kg of flour that never left the sack. The server refuses a
/// repeat within the quarter hour; this is how the refusal is put to the
/// person, and it is a question rather than an error because sometimes the
/// answer really is yes.
class OneTaskRepeatWarning extends StatelessWidget {
  const OneTaskRepeatWarning({
    super.key,
    required this.message,
    required this.onCancel,
    required this.onConfirm,
  });

  final String message;
  final VoidCallback onCancel;
  final VoidCallback onConfirm;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.signalFor(theme.brightness), width: 2),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(message, style: theme.textTheme.bodyMedium?.copyWith(height: 1.8)),
          const SizedBox(height: 6),
          Text(
            'دستهٔ تازه‌ای است؟',
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
              color: AppColors.signalFor(theme.brightness),
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: onCancel,
                  child: const Text('نه، اشتباه شد'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton(
                  onPressed: onConfirm,
                  child: const Text('بله، تازه است'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
