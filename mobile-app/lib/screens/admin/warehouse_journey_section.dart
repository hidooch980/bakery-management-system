import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import '../../widgets/jalali_date_range.dart';
import 'admin_home_screen.dart';

/// A figure without a pointless trailing zero, as the warehouse tab above
/// writes it. Latin digits, like every other number on that screen.
String _fmt(num? value) {
  final number = value ?? 0;

  return number.toStringAsFixed(number == number.roundToDouble() ? 0 : 2);
}

/// Where the stock went, not just how much is left.
///
/// Every other part of the shop had a report — production, sales, flour,
/// wages, debts, sellers, profit and loss. The warehouse had a list of
/// balances and nothing else, which answers «چقدر داریم» and never «کجا
/// رفت». The second question is the one that catches stock leaving by a
/// door nobody opened, and it was the one the phone could not ask.
///
/// The arithmetic is the server's, the same `itemJourney` the panel's
/// flour report uses. A second opinion on where the flour went is worse
/// than one answer.
class WarehouseJourneySection extends StatefulWidget {
  const WarehouseJourneySection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<WarehouseJourneySection> createState() =>
      _WarehouseJourneySectionState();
}

/// How far back to look. Days rather than named months, because the
/// question «این هفته چقدر آرد رفت» is asked against today and not against
/// a calendar the shop does not keep its stock by.
enum _Window {
  week(7, '۷ روز'),
  month(30, '۳۰ روز'),
  quarter(90, '۹۰ روز'),

  /// Two days the owner picked. `days` is unused here and is only a
  /// fallback for the first load before anything has been chosen.
  custom(30, 'بازهٔ دلخواه');

  const _Window(this.days, this.label);

  final int days;
  final String label;
}

class _WarehouseJourneySectionState extends State<WarehouseJourneySection> {
  _Window _window = _Window.month;

  /// Only set once «بازهٔ دلخواه» has been answered. Kept when the owner
  /// switches to a preset and back, so picking two dates again to correct
  /// one of them does not mean picking both.
  DateTime? _customFrom;
  DateTime? _customTo;

  late Future<Map<String, dynamic>> _report = _load();

  String _iso(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';

  ({DateTime from, DateTime to}) get _range {
    final now = DateTime.now();

    if (_window == _Window.custom && _customFrom != null) {
      return (from: _customFrom!, to: _customTo ?? now);
    }

    return (from: now.subtract(Duration(days: _window.days - 1)), to: now);
  }

  Future<Map<String, dynamic>> _load() {
    final range = _range;

    return widget.api.inventoryJourney(
      from: _iso(range.from),
      to: _iso(range.to),
    );
  }

  Future<void> _choose(_Window window) async {
    // Asked every time «بازهٔ دلخواه» is tapped, including when it is
    // already chosen: that is the only way back to the picker once it is
    // selected, and a chip that does nothing when pressed reads as broken.
    if (window == _Window.custom) {
      if (!await _askForDates()) return;
    } else if (window == _window) {
      return;
    }

    setState(() {
      _window = window;
      _report = _load();
    });
  }

  /// Two days, from and to, in the calendar the shop actually reads.
  ///
  /// Returns false when either is dismissed, so the chip stays where it
  /// was rather than landing on a range nobody chose.
  Future<bool> _askForDates() async {
    final now = DateTime.now();

    final from = await pickJalaliDay(
      context,
      title: 'از تاریخ',
      initial: _customFrom ?? now.subtract(const Duration(days: 29)),
      last: now,
    );

    if (from == null || !mounted) return false;

    final to = await pickJalaliDay(
      context,
      title: 'تا تاریخ',
      initial: _customTo != null && _customTo!.isAfter(from) ? _customTo! : now,
      // Not before the day already chosen, so the range cannot come out
      // backwards and quietly report nothing.
      first: from,
      last: now,
    );

    if (to == null) return false;

    _customFrom = from;
    _customTo = to;

    return true;
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        final children = <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 4),
            child: Wrap(
              spacing: 8,
              children: [
                for (final window in _Window.values)
                  ChoiceChip(
                    label: Text(window.label),
                    selected: window == _window,
                    onSelected: (_) => _choose(window),
                  ),
              ],
            ),
          ),
        ];

        if (snapshot.connectionState == ConnectionState.waiting) {
          children.add(const AdminRow(label: 'در حال بارگذاری', value: '…'));
        } else if (snapshot.hasError || snapshot.data == null) {
          children.add(const AdminRow(label: 'گزارش خوانده نشد', value: '—'));
        } else {
          final items = rowList(snapshot.data!['items'])
              // A good that neither moved nor has any stock is noise on a
              // small screen. One that has stock still belongs here even
              // in a quiet month — «هیچ نمکی مصرف نشد» is an answer.
              .where((item) =>
                  (item['in_kg'] as num?) != 0 ||
                  (item['out_kg'] as num?) != 0 ||
                  (item['closing_kg'] as num?) != 0)
              .toList();

          if (items.isEmpty) {
            children.add(const AdminRow(
              label: 'در این بازه چیزی وارد یا خارج نشد',
              value: '—',
            ));
          }

          for (var i = 0; i < items.length; i++) {
            if (i > 0) children.add(const Divider(height: 1));
            children.add(_ItemJourney(api: widget.api, item: items[i]));
          }
        }

        return AdminSection(
          title: 'گردش انبار',
          icon: Icons.swap_vert_rounded,
          children: children,
        );
      },
    );
  }
}

class _ItemJourney extends StatefulWidget {
  const _ItemJourney({required this.api, required this.item});

  final BakeryApi api;
  final Map<String, dynamic> item;

  @override
  State<_ItemJourney> createState() => _ItemJourneyState();
}

class _ItemJourneyState extends State<_ItemJourney> {
  /// Folded away to start. The totals answer «کجا رفت» and the days
  /// answer «کدام روز» — the second question is only asked once the first
  /// one's answer looks wrong, and three goods' worth of days opened at
  /// once is a screen nobody can find anything in.
  bool _showDays = false;

  Map<String, dynamic> get item => widget.item;

  /// The entries behind one day's figure.
  ///
  /// The day already says how much and to where. This says who wrote each
  /// line and at what time — which is what «حتماً من جای اشتباه کردم»
  /// actually needs, because the mistake has a name and an hour on it.
  void _showEntries(Map<String, dynamic> day) {
    final date = '${day['date'] ?? ''}';

    if (date.isEmpty) return;

    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _EntriesSheet(
        api: widget.api,
        itemKey: '${item['key'] ?? ''}',
        itemName: '${item['name'] ?? ''}',
        date: date,
        dateLabel: '${day['date_display'] ?? date}',
      ),
    );
  }

  /// Sacks where the shop has said what a sack weighs, weight where it has
  /// not — «کیلو در انبار معنی نداره، فقط کیسه بیاد», the same rule the
  /// balances above follow. Inventing a sack size would put a number on the
  /// screen that nothing in the shop can be counted against.
  String _amount(num? kg, num? bags) =>
      bags != null ? '${_fmt(bags)} کیسه' : '${_fmt(kg)} ${item['unit'] ?? ''}';

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final balances = item['balances'] == true;
    final days = rowList(item['days']);

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${item['name'] ?? ''}',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: scheme.onSurface,
                      ),
                ),
              ),
              Text(
                _amount(item['closing_kg'] as num?, item['closing_bags'] as num?),
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurface,
                    ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            'اول دوره ${_amount(item['opening_kg'] as num?, item['opening_bags'] as num?)}'
            '  •  آمد ${_amount(item['in_kg'] as num?, item['in_bags'] as num?)}'
            '  •  رفت ${_amount(item['out_kg'] as num?, item['out_bags'] as num?)}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          for (final row in rowList(item['out']))
            _ReasonLine(row: row, outbound: true, amount: _amount),
          for (final row in rowList(item['in']))
            _ReasonLine(row: row, outbound: false, amount: _amount),

          if (days.isNotEmpty) ...[
            const SizedBox(height: 4),
            InkWell(
              onTap: () => setState(() => _showDays = !_showDays),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Icon(
                      _showDays
                          ? Icons.expand_less_rounded
                          : Icons.expand_more_rounded,
                      size: IconSize.inline,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      _showDays
                          ? 'بستن روزها'
                          : 'روز به روز (${_fmt(days.length)} روز)',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: Theme.of(context).colorScheme.primary,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                  ],
                ),
              ),
            ),
            if (_showDays)
              for (final day in days)
                _DayRow(
                  day: day,
                  amount: _amount,
                  onOpen: () => _showEntries(day),
                ),
          ],

          // Derived from one ledger, so this cannot fail by arithmetic. It
          // is on the screen because the day it does fail is the day
          // something wrote a movement the report cannot place, and a
          // report that quietly drops stock is worse than none.
          if (!balances)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'این ردیف جمع نمی‌خورد — یک حرکت ثبت شده که گزارش نمی‌شناسد.',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AppColors.moneyOut,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ReasonLine extends StatelessWidget {
  const _ReasonLine({
    required this.row,
    required this.outbound,
    required this.amount,
  });

  final Map<String, dynamic> row;
  final bool outbound;
  final String Function(num?, num?) amount;

  @override
  Widget build(BuildContext context) {
    final share = (row['share'] as num?)?.toDouble() ?? 0;

    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Row(
        children: [
          Icon(
            outbound ? Icons.arrow_back_rounded : Icons.arrow_forward_rounded,
            size: IconSize.inline,
            color: outbound ? AppColors.moneyOut : AppColors.moneyIn,
          ),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              '${row['label'] ?? row['reason'] ?? ''}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ),
          Text(
            '${amount(row['kg'] as num?, row['bags'] as num?)}'
            '${share > 0 ? '  •  ${_fmt(share)}٪' : ''}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// One day, with what came in, what went out, and what it ended on.
///
/// Newest first, as the server sends them: the question is almost always
/// about the recent past and a handset opens at the top.
class _DayRow extends StatelessWidget {
  const _DayRow({
    required this.day,
    required this.amount,
    required this.onOpen,
  });

  final Map<String, dynamic> day;
  final String Function(num?, num?) amount;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final inKg = (day['in_kg'] as num?)?.toDouble() ?? 0;
    final outKg = (day['out_kg'] as num?)?.toDouble() ?? 0;

    return InkWell(
      onTap: onOpen,
      child: Padding(
        padding: const EdgeInsetsDirectional.only(start: 10, top: 8, bottom: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
          Row(
            children: [
              Icon(Icons.chevron_left_rounded,
                  size: IconSize.inline, color: scheme.primary),
              const SizedBox(width: 2),
              Expanded(
                child: Text(
                  '${day['date_display'] ?? day['date'] ?? ''}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: scheme.onSurface,
                      ),
                ),
              ),
              // The closing balance of the day, not the movement. Reading
              // down this column is how a day that does not make sense is
              // spotted without adding anything up by hand.
              Text(
                amount(day['closing_kg'] as num?, day['closing_bags'] as num?),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurface,
                    ),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsetsDirectional.only(start: 2, top: 2),
            child: Row(
              children: [
                if (inKg > 0) ...[
                  const Icon(Icons.arrow_forward_rounded,
                      size: IconSize.inline, color: AppColors.moneyIn),
                  const SizedBox(width: 4),
                  Text(
                    amount(day['in_kg'] as num?, day['in_bags'] as num?),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.moneyIn,
                        ),
                  ),
                  const SizedBox(width: 12),
                ],
                if (outKg > 0) ...[
                  const Icon(Icons.arrow_back_rounded,
                      size: IconSize.inline, color: AppColors.moneyOut),
                  const SizedBox(width: 4),
                  Text(
                    amount(day['out_kg'] as num?, day['out_bags'] as num?),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AppColors.moneyOut,
                        ),
                  ),
                ],
              ],
            ),
          ),

          // Where that day's stock actually went. Without this the day
          // says «۱۶۰ کیلو رفت» and the next question is «کجا», which is
          // the whole reason somebody opened the days.
          for (final row in [...rowList(day['out']), ...rowList(day['in'])])
            Padding(
              padding: const EdgeInsetsDirectional.only(start: 18, top: 2),
              child: Text(
                '${row['label'] ?? row['reason'] ?? ''}'
                '  •  ${amount(row['kg'] as num?, row['bags'] as num?)}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Every entry behind one day, for one good — «ریز مصرف».
///
/// The day above says how much went and roughly where. This says each line
/// on its own: the amount, the reason, whatever note was left, and whose
/// name is on it at what hour. A total nobody is named against cannot be
/// asked about, and «کجا رفت» eventually becomes «کی نوشتش».
class _EntriesSheet extends StatefulWidget {
  const _EntriesSheet({
    required this.api,
    required this.itemKey,
    required this.itemName,
    required this.date,
    required this.dateLabel,
  });

  final BakeryApi api;
  final String itemKey;
  final String itemName;
  final String date;
  final String dateLabel;

  @override
  State<_EntriesSheet> createState() => _EntriesSheetState();
}

class _EntriesSheetState extends State<_EntriesSheet> {
  late final Future<List<Map<String, dynamic>>> _rows =
      widget.api.inventoryMovements(
    itemKey: widget.itemKey,
    from: widget.date,
    to: widget.date,
  );

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '${widget.itemName} — ${widget.dateLabel}',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            FutureBuilder<List<Map<String, dynamic>>>(
              future: _rows,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 28),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }

                if (snapshot.hasError) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'ریز این روز خوانده نشد.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  );
                }

                final rows = snapshot.data ?? const <Map<String, dynamic>>[];

                if (rows.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'در این روز حرکتی ثبت نشده.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  );
                }

                return ConstrainedBox(
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.6,
                  ),
                  child: ListView.separated(
                    shrinkWrap: true,
                    itemCount: rows.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (_, i) => _EntryRow(row: rows[i]),
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _EntryRow extends StatelessWidget {
  const _EntryRow({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final outbound = row['direction'] == 'out';
    final person = keyedGroup(row['user']);
    final note = '${row['note'] ?? ''}'.trim();
    final unit = '${keyedGroup(row['item'])['unit'] ?? ''}';

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            outbound ? Icons.arrow_back_rounded : Icons.arrow_forward_rounded,
            size: IconSize.row,
            color: outbound ? AppColors.moneyOut : AppColors.moneyIn,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${row['reason_label'] ?? row['reason'] ?? ''}',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: Theme.of(context).colorScheme.onSurface,
                      ),
                ),
                Text(
                  // The hour and the name. Whichever of the two is
                  // missing, the other still narrows the question.
                  [
                    '${row['created_at_display'] ?? ''}',
                    personName(person, fallbackId: person['id']),
                  ].where((part) => part.trim().isNotEmpty).join('  •  '),
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                if (note.isNotEmpty)
                  Text(note, style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
          Text(
            '${_fmt(row['quantity'] as num?)} $unit',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: outbound ? AppColors.moneyOut : AppColors.moneyIn,
                ),
          ),
        ],
      ),
    );
  }
}
