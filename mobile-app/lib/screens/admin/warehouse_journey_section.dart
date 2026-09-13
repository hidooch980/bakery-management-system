import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
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
  quarter(90, '۹۰ روز');

  const _Window(this.days, this.label);

  final int days;
  final String label;
}

class _WarehouseJourneySectionState extends State<WarehouseJourneySection> {
  _Window _window = _Window.month;
  late Future<Map<String, dynamic>> _report = _load();

  String _iso(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() {
    final now = DateTime.now();

    return widget.api.inventoryJourney(
      from: _iso(now.subtract(Duration(days: _window.days - 1))),
      to: _iso(now),
    );
  }

  void _choose(_Window window) {
    if (window == _window) return;

    setState(() {
      _window = window;
      _report = _load();
    });
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
            children.add(_ItemJourney(item: items[i]));
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

class _ItemJourney extends StatelessWidget {
  const _ItemJourney({required this.item});

  final Map<String, dynamic> item;

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
