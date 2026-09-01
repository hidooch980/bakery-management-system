import 'package:flutter/material.dart';

import '../../models/flour_sale.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';
import 'diesel_section.dart';

typedef _FlourSalesToday = ({
  List<FlourSale> sales,
  int count,
  double totalWeightKg,
  String totalFormatted,
});

typedef _WarehouseData = ({
  List<Map<String, dynamic>> items,
  Map<String, dynamic>? quota,
  _FlourSalesToday? flour,
});

/// Stock levels, today's flour sales, and the quota for the current period.
/// A figure without a pointless trailing zero: «۴٬۶۰۰», not «۴٬۶۰۰٫۰۰».
String _fmt(dynamic value) {
  final number = value is num ? value : (num.tryParse('$value') ?? 0);

  return number.toStringAsFixed(number == number.roundToDouble() ? 0 : 2);
}

class AdminWarehouseTab extends StatefulWidget {
  const AdminWarehouseTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminWarehouseTab> createState() => _AdminWarehouseTabState();
}

class _AdminWarehouseTabState extends State<AdminWarehouseTab> {
  late Future<_WarehouseData> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_WarehouseData> _load() async {
    final results = await Future.wait([
      widget.api.inventory(),
      widget.api.currentFlourAllocation(),
    ]);

    // Today's flour sales explain movement in the balance above, but the
    // rest of the page must still render if the call fails.
    _FlourSalesToday? flour;
    try {
      flour = await widget.api.todayFlourSales();
    } on ApiException {
      flour = null;
    }

    return (
      items: results[0] as List<Map<String, dynamic>>,
      quota: results[1] as Map<String, dynamic>?,
      flour: flour,
    );
  }

  void _reload() => setState(() => _data = _load());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<_WarehouseData>(
        future: _data,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: _reload)],
            );
          }

          final items = snapshot.data!.items;
          final quota = snapshot.data!.quota;
          final flour = snapshot.data!.flour;

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: [
              DieselSection(api: widget.api),
              const SizedBox(height: 12),
              AdminSection(
                title: 'موجودی انبار',
                icon: Icons.warehouse_rounded,
                children: [
                  for (var i = 0; i < items.length; i++) ...[
                    if (i > 0) const Divider(height: 1),
                    AdminRow(
                      label: '${items[i]['name']}',
                      value: _balanceLabel(items[i]),
                      icon: _iconFor('${items[i]['key']}'),
                      // A low balance is the one thing worth colouring.
                      color: items[i]['is_low'] == true
                          ? AppColors.moneyOut
                          : null,
                      emphasise: true,
                    ),
                  ],
                ],
              ),

              if (flour != null) ...[
                const SizedBox(height: 22),
                AdminSection(
                  title: 'فروش آرد امروز',
                  icon: Icons.local_shipping_rounded,
                  children: [
                    AdminRow(
                      label: 'مجموع فروش',
                      value: flour.count == 0
                          ? 'موردی ثبت نشده'
                          : '${flour.totalWeightKg.toStringAsFixed(1)} کیلوگرم'
                              '  •  ${flour.totalFormatted}',
                      icon: Icons.inventory_2_rounded,
                      color: AppColors.stock,
                      emphasise: true,
                    ),
                    for (final sale in flour.sales) ...[
                      const Divider(height: 1),
                      AdminRow(
                        label: sale.quantityLabel,
                        value: sale.amountFormatted,
                        icon: sale.unit == FlourUnit.bag
                            ? Icons.shopping_bag_rounded
                            : Icons.scale_rounded,
                      ),
                    ],
                  ],
                ),
              ],

              const SizedBox(height: 22),

              if (quota == null)
                const EmptyState(
                  icon: Icons.calendar_today_rounded,
                  title: 'سهمیه‌ای تعریف نشده',
                  subtitle: 'سهمیه ماهانه آرد را از پنل مدیریت ثبت کنید.',
                )
              else
                ..._buildQuota(context, quota),
            ],
          );
        },
      ),
    );
  }

  List<Widget> _buildQuota(BuildContext context, Map<String, dynamic> quota) {
    final periods = (quota['periods'] as List?) ?? const [];

    return [
      AdminSection(
        title: 'سهمیه آرد — ${quota['month_label'] ?? ''}',
        icon: Icons.calendar_month_rounded,
        children: [
          AdminRow(
            label: 'کل سهمیه ماه',
            value: '${_fmt(quota['total_kg'])} کیلوگرم',
            icon: Icons.scale_rounded,
            emphasise: true,
          ),
          // What the shop may actually still take. Quota rolls forward —
          // period after period, month after month — so this is the
          // figure to plan against, not any one period's leftover below.
          if (quota['carried_balance'] is Map<String, dynamic>)
            AdminRow(
              label: 'ماندهٔ سهمیه — منتقل می‌شود',
              value: _carriedBalance(
                quota['carried_balance'] as Map<String, dynamic>,
              ),
              icon: Icons.savings_rounded,
              emphasise: true,
            ),
        ],
      ),
      const SizedBox(height: 14),
      for (final period in periods.cast<Map<String, dynamic>>())
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _PeriodCard(period: period),
        ),
      // The three added up: the shop's own month, 5th to 4th. The three
      // above answer «may I draw more this week»; this answers «how did
      // the month go», which until now had to be added up in someone's
      // head off three cards. Drawn with the same card so it reads as the
      // same kind of thing, and summed by the server off those very
      // periods so it can never disagree with them.
      if (quota['whole_period'] is Map<String, dynamic>)
        _PeriodCard(
          period: quota['whole_period'] as Map<String, dynamic>,
          isTotal: true,
        ),
    ];
  }

  /// «N کیسه · M کیلوگرم», or just the kilos when no sack size is
  /// known — sacks are what the shop counts flour in.
  static String _carriedBalance(Map<String, dynamic> balance) {
    final kg = _fmt(balance['remaining_kg']);
    final bags = balance['remaining_bags'];

    if (bags == null) return '$kg کیلوگرم';

    return '${_fmt(bags)} کیسه · $kg کیلوگرم';
  }

  static IconData _iconFor(String key) => switch (key) {
        'flour' => Icons.grain_rounded,
        'salt' => Icons.scatter_plot_rounded,
        'dough' => Icons.bakery_dining_rounded,
        _ => Icons.inventory_rounded,
      };


  /// "۴ کیسه" — sacks alone, where the item has a sack.
  ///
  /// «کیلو در انبار معنی نداره، فقط کیسه بیاد». The shop counts flour in
  /// sacks, orders it in sacks and lends it in sacks; the weight beside
  /// the count was the same fact in a unit nobody uses at the door.
  ///
  /// Salt and yeast arrive in no fixed sack, so the server sends no bag
  /// count for them and their weight is all there is to say.
  static String _balanceLabel(Map<String, dynamic> item) {
    final bags = item['balance_bags'];

    if (bags == null) return '${_fmt(item['balance'])} ${item['unit']}';

    return '${_fmt(bags)} کیسه';
  }
}

/// One of the three delivery periods, with a usage bar — or all three
/// added together, which is drawn the same way with a rule above it.
class _PeriodCard extends StatelessWidget {
  const _PeriodCard({required this.period, this.isTotal = false});

  /// The whole 5th-to-4th window rather than a slice of it. It never
  /// carries the «current period» outline, because it is not the slice the
  /// shop is drawing from today — it is all of them.
  final bool isTotal;

  final Map<String, dynamic> period;


  /// A sack count reads better without a trailing zero: «۱۱۵ کیسه», not
  /// «۱۱۵٫۰».
  static String _bags(dynamic value) {
    final bags = value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

    return bags % 1 == 0 ? bags.toStringAsFixed(0) : bags.toStringAsFixed(1);
  }

  /// The server sends sacks for the allocation; for what was used and what
  /// is left, they are the same weight over the same sack size.
  static double _bagsFromKg(Map<String, dynamic> period, String key) {
    final kg = (period[key] as num?)?.toDouble() ?? 0;
    final allocatedKg = (period['allocated_kg'] as num?)?.toDouble() ?? 0;
    final allocatedBags = (period['allocated_bags'] as num?)?.toDouble() ?? 0;

    if (allocatedKg <= 0 || allocatedBags <= 0) return 0;

    return kg / (allocatedKg / allocatedBags);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final percent = (period['usage_percent'] as num?)?.toDouble() ?? 0;
    final isCurrent = period['is_current'] == true;
    final isOver = period['is_over'] == true;

    // The same reading as the diesel meter, so the two are read the same
    // way: how far along the period is *is* how hot the bar runs. Three
    // steps could only say "fine / nearly / over", and the useful question
    // in the middle of a period is how far along, not which bucket.
    // Over-quota leaves the ramp — past the end of a scale is a different
    // kind of fact, not a hotter shade of the same one.
    final color = isOver
        ? Theme.of(context).colorScheme.error
        : AppColors.emberAt((percent / 100).clamp(0.0, 1.0));

    // Same rule as the diesel meter: the ramp draws the bar, never the
    // words. A ramp runs dark to light, so one end of it always vanishes
    // into one of the two grounds.
    final wordsColour = isOver
        ? Theme.of(context).colorScheme.error
        : (percent >= 80 ? AppColors.attention : null);

    final card = Card(
      // The period in progress is the one that matters most.
      shape: isCurrent
          ? RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(Corner.card),
              side: BorderSide(color: scheme.primary, width: 2),
            )
          : null,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${period['label']}',
                    style: Theme.of(context)
                        .textTheme
                        .titleSmall
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                if (isCurrent)
                  Chip(
                    label: const Text('جاری'),
                    visualDensity: VisualDensity.compact,
                    backgroundColor: scheme.primary.withValues(alpha: 0.15),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${period['starts_on_display']} تا ${period['ends_on_display']}',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(Corner.hair),
              child: LinearProgressIndicator(
                value: (percent / 100).clamp(0.0, 1.0),
                minHeight: 10,
                color: color,
                backgroundColor: Theme.of(context)
                    .dividerColor
                    .withValues(alpha: 0.4),
              ),
            ),
            const SizedBox(height: 12),
            // Sacks, because sacks are what the shop counts flour in — the
            // kilos follow in brackets for the books. «هر آرد ورودی کیسه
            // است نه ریال», and the same goes for what goes out.
            _FlourRow(
              label: 'سهمیه دوره',
              bags: _bags(period['allocated_bags']),
              kg: period['allocated_kg'],
            ),
            _FlourRow(
              label: 'مصرف شده',
              bags: _bags(period['used_bags'] ?? _bagsFromKg(period, 'used_kg')),
              kg: period['used_kg'],
            ),
            _FlourRow(
              // «دوره» said out loud, because this is what the period
              // itself has left — not what the shop may still take. That
              // total is on the card above and does not expire.
              label: isOver ? 'بیش از سهمیهٔ دوره' : 'باقی‌ماندهٔ دوره',
              bags: _bags(period['remaining_bags'] ?? _bagsFromKg(period, 'remaining_kg')),
              kg: period['remaining_kg'],
              colour: wordsColour,
              emphasise: true,
            ),
            _BreadReconciliation(period: period),
          ],
        ),
      ),
    );

    if (!isTotal) return card;

    // A rule and a word, because a fourth card in a row of three reads as
    // a fourth period unless something says otherwise.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Row(
            children: [
              Expanded(child: Divider(color: scheme.outlineVariant)),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                child: Text(
                  'جمع هر سه دوره',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ),
              Expanded(child: Divider(color: scheme.outlineVariant)),
            ],
          ),
        ),
        card,
      ],
    );
  }
}

/// «سهمیه دوره        ۱۱۵ کیسه · ۴٬۶۰۰ کگ»
///
/// Sacks lead because that is the unit the shop trades, counts and argues
/// in; the weight follows quietly for the books.
class _FlourRow extends StatelessWidget {
  const _FlourRow({
    required this.label,
    required this.bags,
    required this.kg,
    this.colour,
    this.emphasise = false,
  });

  final String label;
  final String bags;
  final dynamic kg;
  final Color? colour;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: theme.textTheme.bodySmall?.copyWith(
              color: colour ?? scheme.onSurfaceVariant,
              fontWeight: emphasise ? FontWeight.w700 : null,
            ),
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            textBaseline: TextBaseline.alphabetic,
            crossAxisAlignment: CrossAxisAlignment.baseline,
            children: [
              Text(
                '$bags کیسه',
                style: theme.textTheme.bodySmall?.copyWith(
                  fontWeight: emphasise ? FontWeight.w800 : FontWeight.w600,
                  color: colour ?? scheme.onSurface,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
              Text(
                '  ·  ${_fmt(kg)} کگ',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// The period's flour restated as loaves, against what the card reader sold.
///
/// Nanino is the measure because the reader is wired into it, so its loaf is
/// the one counted outside the shop — 115 sacks at 64 loaves a sack is 7,360
/// loaves for the period, whatever shape they were actually baked in.
class _BreadReconciliation extends StatelessWidget {
  const _BreadReconciliation({required this.period});

  final Map<String, dynamic> period;

  static int _int(dynamic value) =>
      value is num ? value.toInt() : int.tryParse('$value') ?? 0;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final quota = _int(period['allocated_bread_count']);
    final sold = _int(period['card_bread_count']);
    final remainder = _int(period['bread_remainder']);

    // Nothing to say until the nanino loaf weight is configured.
    if (quota == 0) return const SizedBox.shrink();

    // More sold than the quota allows is the figure worth noticing.
    final remainderColor = remainder < 0
        ? AppColors.moneyOut
        : scheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(top: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Divider(height: 1, color: scheme.outlineVariant),
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.bakery_dining_rounded, size: IconSize.inline, color: scheme.primary),
              const SizedBox(width: 6),
              Text(
                'نان دوره',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _BreadRow(label: 'سهمیه دوره', value: '$quota نان'),
          _BreadRow(
            label: 'فروش کارتخوان',
            value: '$sold نان  •  ${period['card_amount_formatted'] ?? '—'}',
          ),
          _BreadRow(
            label: 'باقی‌مانده',
            value: '$remainder نان',
            color: remainderColor,
            emphasise: true,
          ),
        ],
      ),
    );
  }
}

class _BreadRow extends StatelessWidget {
  const _BreadRow({
    required this.label,
    required this.value,
    this.color,
    this.emphasise = false,
  });

  final String label;
  final String value;
  final Color? color;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: scheme.onSurfaceVariant),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: emphasise ? FontWeight.w800 : FontWeight.w600,
                  color: color ?? scheme.onSurface,
                ),
          ),
        ],
      ),
    );
  }
}
