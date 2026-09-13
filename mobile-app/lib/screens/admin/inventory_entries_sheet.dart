import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';

/// A figure without a trailing zero, as the warehouse tab writes it.
String _fmt(num? value) {
  final number = value ?? 0;

  return number.toStringAsFixed(number == number.roundToDouble() ? 0 : 2);
}

/// Opens the entries behind a good, for a stretch or for everything recent.
Future<void> showInventoryEntries(
  BuildContext context, {
  required BakeryApi api,
  required String itemKey,
  required String itemName,
  required String subtitle,
  String? from,
  String? to,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (_) => InventoryEntriesSheet(
      api: api,
      itemKey: itemKey,
      itemName: itemName,
      subtitle: subtitle,
      from: from,
      to: to,
    ),
  );
}

/// Every entry behind a stretch, for one good — «ریز گردش».
///
/// A balance says how much is there and a day says how much moved. This
/// says each line on its own: the amount, the reason, whatever note was
/// left, and whose name is on it at what hour. A total nobody is named
/// against cannot be asked about, and «کجا رفت» eventually becomes «کی
/// نوشتش».
///
/// Opened from two places, because both raise the same next question: a
/// day in the journey below, and the balance at the top of the tab.
class InventoryEntriesSheet extends StatefulWidget {
  const InventoryEntriesSheet({
    super.key,
    required this.api,
    required this.itemKey,
    required this.itemName,
    required this.subtitle,
    this.from,
    this.to,
  });

  final BakeryApi api;
  final String itemKey;
  final String itemName;

  /// What stretch this is, in the shop's own words — one day, or «۳۰ روز
  /// اخیر». On screen beside the name so a list of entries is never read
  /// against the wrong dates.
  final String subtitle;

  /// Both null means whatever the server returns newest-first, which is
  /// what «ریز گردش» off a balance should show: the recent past, without
  /// the owner having to name a range to see anything at all.
  final String? from;
  final String? to;

  @override
  State<InventoryEntriesSheet> createState() => _InventoryEntriesSheetState();
}

class _InventoryEntriesSheetState extends State<InventoryEntriesSheet> {
  late final Future<List<Map<String, dynamic>>> _rows =
      widget.api.inventoryMovements(
    itemKey: widget.itemKey,
    from: widget.from,
    to: widget.to,
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
              widget.itemName,
              style: Theme.of(context).textTheme.titleMedium,
            ),
            Text(
              widget.subtitle,
              style: Theme.of(context).textTheme.bodySmall,
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
                      'ریز گردش خوانده نشد.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  );
                }

                final rows = snapshot.data ?? const <Map<String, dynamic>>[];

                if (rows.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'در این بازه حرکتی ثبت نشده.',
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
