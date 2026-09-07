import 'package:flutter/material.dart';
import 'package:shamsi_date/shamsi_date.dart';

import '../utils/formatters.dart';

/// Picking a Jalali day.
///
/// Flutter's own picker is Gregorian, and a shop that thinks in «۸ شهریور»
/// cannot use it: the person converts in their head, twice, and any slip
/// produces a report that looks right and covers the wrong days.
///
/// This was three dropdowns side by side. The day one was narrow enough
/// that the number did not fit in it — the field showed an arrow and no
/// value, so the one thing the picker is for was the one thing invisible.
/// Widening it would have fixed that and left the rest: three separate
/// choices to make, none of them showing what day of the week anything
/// falls on, for a shop whose month runs 5th to 4th and whose Fridays
/// matter.
///
/// So it is a calendar. The day is picked by looking at it.
class JalaliDayPicker extends StatefulWidget {
  const JalaliDayPicker({
    super.key,
    required this.title,
    required this.initial,
    this.first,
    this.last,
  });

  final String title;
  final DateTime initial;

  /// Bounds, so a range cannot be picked back to front.
  final DateTime? first;
  final DateTime? last;

  @override
  State<JalaliDayPicker> createState() => _JalaliDayPickerState();
}

class _JalaliDayPickerState extends State<JalaliDayPicker> {
  late Jalali _value;

  /// The month on screen, which is not always the month of the chosen day:
  /// somebody paging back to look does not lose their selection.
  late Jalali _shown;

  static const _months = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
  ];

  /// Saturday first, as the week runs here.
  static const _weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

  @override
  void initState() {
    super.initState();
    _value = Jalali.fromDateTime(widget.initial.toLocal());
    _shown = Jalali(_value.year, _value.month, 1);
  }

  void _page(int months) {
    var year = _shown.year;
    var month = _shown.month + months;

    while (month > 12) {
      month -= 12;
      year++;
    }
    while (month < 1) {
      month += 12;
      year--;
    }

    setState(() => _shown = Jalali(year, month, 1));
  }

  bool _allowed(Jalali day) {
    final picked = day.toDateTime();
    final first = widget.first;
    final last = widget.last;

    if (first != null && picked.isBefore(DateUtils.dateOnly(first))) return false;
    if (last != null && picked.isAfter(DateUtils.dateOnly(last))) return false;

    return true;
  }

  /// Whether paging that way could reach anything pickable at all.
  bool _canPage(int months) {
    var year = _shown.year;
    var month = _shown.month + months;

    if (month > 12) {
      month -= 12;
      year++;
    }
    if (month < 1) {
      month += 12;
      year--;
    }

    final candidate = Jalali(year, month, 1);
    final lastOfMonth = Jalali(year, month, candidate.monthLength);

    // Any day in that month being in bounds is enough: the arrow only has
    // to know whether there is something over there.
    return _allowed(candidate) || _allowed(lastOfMonth);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return AlertDialog(
      title: Text(widget.title),
      contentPadding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      content: SizedBox(
        width: 320,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _MonthBar(
              label: '${_months[_shown.month - 1]} ${_shown.year}',
              // In an RTL layout «قبل» is the arrow pointing right.
              onPrevious: _canPage(-1) ? () => _page(-1) : null,
              onNext: _canPage(1) ? () => _page(1) : null,
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                for (final day in _weekDays)
                  Expanded(
                    child: Text(
                      day,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 4),
            _DayGrid(
              shown: _shown,
              selected: _value,
              isAllowed: _allowed,
              onPick: (day) => setState(() => _value = day),
            ),
            const SizedBox(height: 10),
            Text(
              JalaliFormat.longDate(_value.toDateTime()),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurface,
                  ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          // Always in bounds now: an out-of-range day cannot be tapped in
          // the first place, so there is no state where the button has to
          // refuse what the calendar just accepted.
          onPressed: () => Navigator.pop(context, _value.toDateTime()),
          child: const Text('تأیید'),
        ),
      ],
    );
  }
}

class _MonthBar extends StatelessWidget {
  const _MonthBar({
    required this.label,
    required this.onPrevious,
    required this.onNext,
  });

  final String label;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        IconButton(
          onPressed: onPrevious,
          icon: const Icon(Icons.chevron_right_rounded),
          tooltip: 'ماه قبل',
        ),
        Expanded(
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: Theme.of(context).colorScheme.onSurface,
                ),
          ),
        ),
        IconButton(
          onPressed: onNext,
          icon: const Icon(Icons.chevron_left_rounded),
          tooltip: 'ماه بعد',
        ),
      ],
    );
  }
}

class _DayGrid extends StatelessWidget {
  const _DayGrid({
    required this.shown,
    required this.selected,
    required this.isAllowed,
    required this.onPick,
  });

  final Jalali shown;
  final Jalali selected;
  final bool Function(Jalali) isAllowed;
  final ValueChanged<Jalali> onPick;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final today = Jalali.now();

    // `weekDay` is 1 for Saturday, so the first of the month sits that
    // many cells in.
    final lead = Jalali(shown.year, shown.month, 1).weekDay - 1;
    final length = shown.monthLength;
    final cells = <Widget>[];

    for (var i = 0; i < lead; i++) {
      cells.add(const SizedBox.shrink());
    }

    for (var day = 1; day <= length; day++) {
      final date = Jalali(shown.year, shown.month, day);
      final allowed = isAllowed(date);
      final isSelected = date.year == selected.year &&
          date.month == selected.month &&
          date.day == selected.day;
      final isToday = date.year == today.year &&
          date.month == today.month &&
          date.day == today.day;

      cells.add(_DayCell(
        day: day,
        selected: isSelected,
        today: isToday,
        // A day outside the range is shown and not tappable, rather than
        // hidden: a gap in a calendar reads as a fault in the calendar.
        onTap: allowed ? () => onPick(date) : null,
        scheme: scheme,
      ));
    }

    return GridView.count(
      crossAxisCount: 7,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      childAspectRatio: 1.1,
      children: cells,
    );
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    required this.day,
    required this.selected,
    required this.today,
    required this.onTap,
    required this.scheme,
  });

  final int day;
  final bool selected;
  final bool today;
  final VoidCallback? onTap;
  final ColorScheme scheme;

  @override
  Widget build(BuildContext context) {
    final disabled = onTap == null;

    return Padding(
      padding: const EdgeInsets.all(2),
      child: Material(
        color: selected ? scheme.primary : Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: today && !selected
              ? BorderSide(color: scheme.primary, width: 1.4)
              : BorderSide.none,
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Center(
            child: Text(
              '$day',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight:
                        selected || today ? FontWeight.w700 : FontWeight.w400,
                    // Every state names its own colour. This draws over a
                    // filled square when selected, and an inherited one
                    // comes out unreadable on it.
                    color: selected
                        ? scheme.onPrimary
                        : disabled
                            ? scheme.onSurfaceVariant.withValues(alpha: 0.38)
                            : scheme.onSurface,
                  ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Asks for one Jalali day. Returns null if the person backed out.
Future<DateTime?> pickJalaliDay(
  BuildContext context, {
  required String title,
  required DateTime initial,
  DateTime? first,
  DateTime? last,
}) {
  return showDialog<DateTime>(
    context: context,
    builder: (_) => JalaliDayPicker(
      title: title,
      initial: initial,
      first: first,
      last: last,
    ),
  );
}
