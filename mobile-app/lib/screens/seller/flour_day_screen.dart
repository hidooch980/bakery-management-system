import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../utils/json.dart';
import '../../widgets/jalali_date_range.dart';

/// «گردش روزانه آرد»: یک روز انبار آرد، فقط به کیسه و فقط خواندنی.
///
/// موجودی اول روز، آنچه آمد (خرید، دریافت از همکار)، آنچه رفت (خمیر،
/// پاششی، فروش آرد، تحویل به همکار)، موجودی آخر روز، و ریز حرکت‌ها با
/// ساعت. روز با دکمه‌های قبل/بعد یا تقویم شمسی عوض می‌شود.
class FlourDayScreen extends StatefulWidget {
  const FlourDayScreen({super.key, required this.api, this.initialDay});

  final BakeryApi api;

  /// برای تست؛ خالی یعنی امروز.
  final DateTime? initialDay;

  @override
  State<FlourDayScreen> createState() => _FlourDayScreenState();
}

class _FlourDayScreenState extends State<FlourDayScreen> {
  DateTime? _day;
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _day = widget.initialDay;
    _future = widget.api.flourDay(day: _day);
  }

  void _go(DateTime? day) {
    setState(() {
      _day = day;
      _future = widget.api.flourDay(day: day);
    });
  }

  DateTime? _parse(Object? value) =>
      value == null ? null : DateTime.tryParse('$value');

  Future<void> _pick(DateTime current) async {
    final now = DateTime.now();
    final picked = await pickJalaliDay(
      context,
      title: 'کدام روز؟',
      initial: current,
      last: DateTime(now.year, now.month, now.day),
    );

    if (picked != null && mounted) _go(picked);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('گردش روزانه آرد')),
      body: RefreshIndicator(
        onRefresh: () async {
          _go(_day);
          await _future;
        },
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              final error = snapshot.error;

              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        children: [
                          Text(
                            error is ApiException
                                ? error.message
                                : 'گردش آرد خوانده نشد.',
                            textAlign: TextAlign.center,
                          ),
                          TextButton(
                            onPressed: () => _go(_day),
                            child: const Text('دوباره'),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            final day = _parse(data['date']) ?? DateTime.now();
            final previous = _parse(data['previous_date']);
            final next = _parse(data['next_date']);
            final movements = rowList(data['movements']);

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _DayBar(
                  label: data['is_today'] == true
                      ? 'امروز  •  ${data['date_display']}'
                      : '${data['date_display']}',
                  onPrevious: previous == null ? null : () => _go(previous),
                  onNext: next == null ? null : () => _go(next),
                  onPick: () => _pick(day),
                ),
                const SizedBox(height: 12),
                _Summary(data: data),
                const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: _Breakdown(
                        title: 'ورودی',
                        rows: rowList(data['in']),
                        color: AppColors.moneyIn,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _Breakdown(
                        title: 'خروجی',
                        rows: rowList(data['out']),
                        color: AppColors.moneyOut,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 18),
                Text(
                  'ریز حرکت‌ها',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                ),
                const SizedBox(height: 8),
                if (movements.isEmpty)
                  const Card(
                    child: Padding(
                      padding: EdgeInsets.all(18),
                      child: Text(
                        'در این روز آردی جابه‌جا نشده است.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                else
                  Card(
                    child: Column(
                      children: [
                        for (var i = 0; i < movements.length; i++) ...[
                          if (i > 0) const Divider(height: 1),
                          _MovementRow(
                            movement: movements[i],
                          ),
                        ],
                      ],
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

double _num(Object? value) => (value as num?)?.toDouble() ?? 0;

/// «‎+20 کیسه»، «‎−5.5 کیسه» — عدد علامت‌دار جدا از متن راست‌به‌چپ.
@visibleForTesting
String signedBags(double bags, {required bool incoming}) {
  final number = flourBags(bags).replaceAll(' کیسه', '');

  return '\u2066${incoming ? '+' : '−'}$number\u2069 کیسه';
}

class _DayBar extends StatelessWidget {
  const _DayBar({
    required this.label,
    required this.onPick,
    this.onPrevious,
    this.onNext,
  });

  final String label;
  final VoidCallback onPick;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
        child: Row(
          children: [
            // راست‌به‌چپ: روز قبل سمت راست است.
            IconButton(
              tooltip: 'روز قبل',
              onPressed: onPrevious,
              icon: const Icon(Icons.chevron_right_rounded),
            ),
            Expanded(
              child: TextButton.icon(
                onPressed: onPick,
                icon: const Icon(Icons.calendar_month_rounded, size: 18),
                label: Text(
                  label,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
              ),
            ),
            IconButton(
              tooltip: 'روز بعد',
              onPressed: onNext,
              icon: const Icon(Icons.chevron_left_rounded),
            ),
          ],
        ),
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    Widget cell(String label, double bags, {Color? color, bool big = false}) {
      return Expanded(
        child: Column(
          children: [
            Text(
              flourBags(bags),
              style: (big
                      ? Theme.of(context).textTheme.titleLarge
                      : Theme.of(context).textTheme.titleMedium)
                  ?.copyWith(
                fontWeight: FontWeight.w800,
                color: color ?? scheme.onSurface,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Row(
              children: [
                cell('موجودی اول روز', _num(data['opening_bags'])),
                cell('آمد', _num(data['in_bags']), color: AppColors.moneyIn),
                cell('رفت', _num(data['out_bags']), color: AppColors.moneyOut),
              ],
            ),
            const Divider(height: 26),
            Row(
              children: [
                cell('موجودی آخر روز', _num(data['closing_bags']), big: true),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Breakdown extends StatelessWidget {
  const _Breakdown({
    required this.title,
    required this.rows,
    required this.color,
  });

  final String title;
  final List<Map<String, dynamic>> rows;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final small = Theme.of(context).textTheme.bodySmall;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              title,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: color,
                  ),
            ),
            const SizedBox(height: 6),
            if (rows.isEmpty)
              Text('—', style: small?.copyWith(color: scheme.onSurfaceVariant))
            else
              for (final row in rows)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(
                    children: [
                      Expanded(child: Text('${row['label']}', style: small)),
                      Text(
                        flourBags(_num(row['bags'])),
                        style: small?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

class _MovementRow extends StatelessWidget {
  const _MovementRow({required this.movement});

  final Map<String, dynamic> movement;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final incoming = movement['direction'] == 'in';
    final tone = incoming ? AppColors.moneyIn : AppColors.moneyOut;
    final note = (movement['note'] ?? '').toString();
    final user = (movement['user'] ?? '').toString();

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      child: Row(
        children: [
          SizedBox(
            width: 46,
            child: Text(
              '${movement['time'] ?? ''}',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
          ),
          Icon(
            incoming ? Icons.south_west_rounded : Icons.north_east_rounded,
            size: 18,
            color: tone,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${movement['label']}',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                if (note.isNotEmpty || user.isNotEmpty)
                  Text(
                    [if (note.isNotEmpty) note, if (user.isNotEmpty) user]
                        .join('  •  '),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
              ],
            ),
          ),
          Text(
            // علامت و عدد چپ‌به‌راست جدا می‌شوند تا «کیسه» درست کنارشان بماند.
            signedBags(_num(movement['bags']), incoming: incoming),
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tone,
                ),
          ),
        ],
      ),
    );
  }
}
