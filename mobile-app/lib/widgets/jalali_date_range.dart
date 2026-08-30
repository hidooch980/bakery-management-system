import 'package:flutter/material.dart';
import 'package:shamsi_date/shamsi_date.dart';

import '../utils/formatters.dart';

/// Picking a Jalali day, and a Jalali span of days.
///
/// Flutter's own picker is Gregorian, and a shop that thinks in «۸ شهریور»
/// cannot use it: the person has to convert in their head, twice, and any
/// mistake produces a report that looks fine and covers the wrong days.
///
/// Built rather than pulled in, because `shamsi_date` — already a
/// dependency for every date the app prints — does the arithmetic, and
/// what is left is three dropdowns.
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

  static const _months = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
  ];

  @override
  void initState() {
    super.initState();
    _value = Jalali.fromDateTime(widget.initial.toLocal());
  }

  /// Days in the chosen month — 29, 30 or 31, and 30 in a leap Esfand.
  int get _daysInMonth => _value.monthLength;

  void _set({int? year, int? month, int? day}) {
    final y = year ?? _value.year;
    final m = month ?? _value.month;
    // Moving from a 31-day month to a shorter one must not leave the day
    // past the end of it.
    final maxDay = Jalali(y, m, 1).monthLength;
    final d = (day ?? _value.day).clamp(1, maxDay);

    setState(() => _value = Jalali(y, m, d));
  }

  bool get _inBounds {
    final picked = _value.toDateTime();
    final first = widget.first;
    final last = widget.last;

    if (first != null && picked.isBefore(DateUtils.dateOnly(first))) return false;
    if (last != null && picked.isAfter(DateUtils.dateOnly(last))) return false;

    return true;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final thisYear = Jalali.now().year;

    return AlertDialog(
      title: Text(widget.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                flex: 3,
                child: DropdownButtonFormField<int>(
                  // Keyed on the value, because `initialValue` is read
                  // once and then owned by the field's own state.
                  // Changing month to a shorter one clamps the day — and
                  // without this the field would still hold the old one,
                  // which is no longer in `items`. Flutter asserts on
                  // that: «There should be exactly one item with this
                  // value». A crash, not a wrong label.
                  key: ValueKey('day-${_value.day}-$_daysInMonth'),
                  initialValue: _value.day,
                  decoration: const InputDecoration(labelText: 'روز'),
                  items: [
                    for (var d = 1; d <= _daysInMonth; d++)
                      DropdownMenuItem(value: d, child: Text('$d')),
                  ],
                  onChanged: (v) => _set(day: v),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 5,
                child: DropdownButtonFormField<int>(
                  key: ValueKey('month-${_value.month}'),
                  initialValue: _value.month,
                  decoration: const InputDecoration(labelText: 'ماه'),
                  items: [
                    for (var m = 1; m <= 12; m++)
                      DropdownMenuItem(value: m, child: Text(_months[m - 1])),
                  ],
                  onChanged: (v) => _set(month: v),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 4,
                child: DropdownButtonFormField<int>(
                  key: ValueKey('year-${_value.year}'),
                  initialValue: _value.year,
                  decoration: const InputDecoration(labelText: 'سال'),
                  items: [
                    for (var y = thisYear - 3; y <= thisYear; y++)
                      DropdownMenuItem(value: y, child: Text('$y')),
                  ],
                  onChanged: (v) => _set(year: v),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            JalaliFormat.longDate(_value.toDateTime()),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: _inBounds ? scheme.onSurface : scheme.error,
                ),
          ),
          if (!_inBounds) ...[
            const SizedBox(height: 6),
            Text(
              'این تاریخ بیرون از بازهٔ مجاز است.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.error,
                  ),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          // Disabled rather than accepted-then-swapped: a picker that
          // silently reorders the dates leaves the person sure they asked
          // for something else.
          onPressed: _inBounds
              ? () => Navigator.pop(context, _value.toDateTime())
              : null,
          child: const Text('تأیید'),
        ),
      ],
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
