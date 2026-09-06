import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';

/// What each bench got out of a sack, against the formula.
///
/// The shop has always recorded who shaped which batch and how many sacks
/// went into it; nothing ever put the two together. «هدف در برابر واقعی»
/// has been the named gap in the audit's staff clause since the first day.
///
/// This is a figure about a person, so the rules it keeps are on the
/// screen with it. «چرا اسم فلانی نیست» has one answer — a shared batch
/// counts for nobody, and a small sample is not reported — and it belongs
/// on the same page as the numbers, not in a docblock.
class StaffYieldSection extends StatefulWidget {
  const StaffYieldSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
  });

  final BakeryApi api;
  final String from;
  final String to;

  @override
  State<StaffYieldSection> createState() => _StaffYieldSectionState();
}

class _StaffYieldSectionState extends State<StaffYieldSection> {
  late Future<Map<String, dynamic>> _report;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  @override
  void didUpdateWidget(StaffYieldSection oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.from != widget.from || oldWidget.to != widget.to) {
      setState(() => _report = _load());
    }
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.staffYield(from: widget.from, to: widget.to);

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'بازده هر چانه‌گیر',
            icon: Icons.speed_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError) return const SizedBox.shrink();

        final data = snapshot.data!;
        final rows = rowList(data['rows'])
            .whereType<Map<String, dynamic>>()
            .toList();
        final note = '${data['note'] ?? ''}';

        // Nobody met the bar. Said plainly, with why — an empty section
        // would read as «nobody worked», which is a different claim.
        if (rows.isEmpty) {
          return AdminSection(
            title: 'بازده هر چانه‌گیر',
            icon: Icons.speed_rounded,
            children: [
              const AdminRow(
                label: 'وضعیت',
                value: 'در این بازه نمونهٔ کافی نبود',
              ),
              if (note.isNotEmpty) _Note(text: note),
            ],
          );
        }

        return AdminSection(
          title: 'بازده هر چانه‌گیر',
          icon: Icons.speed_rounded,
          children: [
            for (final row in rows) _Bench(row: row),
            if (note.isNotEmpty) _Note(text: note),
          ],
        );
      },
    );
  }
}

/// One bench: the yield, the target beside it, and the sample it rests on.
class _Bench extends StatelessWidget {
  const _Bench({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;
    final isLow = row['isLow'] == true;

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${row['user'] ?? '—'}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 2),
                // The sample, never dropped: «۴۲ از هر کیسه» means one
                // thing over six sacks and another over sixty.
                Text(
                  '${_n(row['bags'])} کیسه در ${_n(row['batches'])} خمیر',
                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '${_n(row['perBag'])} از هر کیسه',
                style: theme.textTheme.bodyLarge?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: isLow ? AppColors.emberWarm : null,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                'فرمول: ${_n(row['expectedPerBag'])}',
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 4, 18, 14),
      child: Text(
        text,
        style: theme.textTheme.bodySmall?.copyWith(
          color: theme.textTheme.bodySmall?.color,
        ),
      ),
    );
  }
}

/// A figure without a pointless trailing zero.
String _n(dynamic value) {
  final number =
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

  return number == number.roundToDouble()
      ? number.toStringAsFixed(0)
      : number.toStringAsFixed(1);
}
