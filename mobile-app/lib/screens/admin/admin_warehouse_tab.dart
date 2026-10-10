import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../models/flour_sale.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../../widgets/admin_detail_group.dart';
import '../shared/purchase_sheet.dart';
import 'admin_home_screen.dart';
import 'diesel_section.dart';
import 'inventory_entries_sheet.dart';
import 'stock_count_sheet.dart';

typedef _FlourSalesToday = ({
  List<FlourSale> sales,
  int count,
  double totalBags,
  String totalFormatted,
});

typedef _WarehouseData = ({
  List<Map<String, dynamic>> items,
  Map<String, dynamic>? quota,
  _FlourSalesToday? flour,
});

/// موجودی، فروش امروز آرد و سهمیه دوره جاری؛ عددها بدون صفر اعشاری غیرضروری نمایش داده می‌شوند.
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
    // موجودی، خودِ صفحه است: اگر این نیاید چیزی برای نشان دادن نمانده و
    // خطا درست است.
    //
    // سهمیه و فروش آرد امروز، هر دو کنارش‌اند. سهمیه تا امروز داخل همان
    // `Future.wait` بود، پس یک شکست در آن کل تب را خطا می‌کرد و
    // موجودی انبار — تنها چیزی که مالک این تب را برایش باز می‌کند — با
    // خودش می‌برد. و صفحه از قبل حالت «سهمیه‌ای تعریف نشده» را دارد، پس
    // نبودنش وضعیت شناخته‌شده‌ای است نه خرابی.
    final results = await Future.wait([
      widget.api.inventory(),
      _orNull(widget.api.currentFlourAllocation()),
      _orNull(widget.api.todayFlourSales()),
    ]);

    return (
      items: results[0] as List<Map<String, dynamic>>,
      quota: results[1] as Map<String, dynamic>?,
      flour: results[2] as _FlourSalesToday?,
    );
  }

  /// چیزی که اگر نیامد، صفحه بدونش کار می‌کند.
  ///
  /// فقط `ApiException` — خطای برنامه‌نویسی نباید اینجا بلعیده شود و
  /// به‌جای دیده شدن، به شکل یک کارت خالی دربیاید.
  static Future<T?> _orNull<T>(Future<T?> call) async {
    try {
      return await call;
    } on ApiException {
      return null;
    }
  }

  void _reload() => setState(() {
        _data = _load();
      });

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
              children: [
                ErrorBox(message: '${snapshot.error}', onRetry: _reload)
              ],
            );
          }

          final items = snapshot.data!.items;
          final quota = snapshot.data!.quota;
          final flour = snapshot.data!.flour;

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: [
              // ثبت محموله کنار موجودی قرار دارد تا ورود کیسه و تغییر موجودی در یک بخش باشد.
              Row(
                children: [
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () async {
                        if (await PurchaseSheet.open(context, widget.api)) {
                          _reload();
                        }
                      },
                      icon: const Icon(Icons.local_shipping_rounded,
                          size: IconSize.button),
                      label: const Text('ثبت محموله'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  // کنار «ثبت محموله»، چون همان‌جا که کیسه‌های تازه نوشته
                  // می‌شوند، همان‌جا هم معلوم می‌شود دفتر با قفسه می‌خواند
                  // یا نه.
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        if (await showStockCountSheet(context, widget.api) ==
                            true) {
                          _reload();
                        }
                      },
                      icon: const Icon(Icons.fact_check_rounded,
                          size: IconSize.button),
                      label: const Text('شمارش انبار'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              AdminDetailGroup(
                  title: 'سوخت و گازوئیل',
                  subtitle: 'موجودی و خرید سوخت',
                  icon: Icons.local_gas_station_outlined,
                  builder: (_) => DieselSection(api: widget.api)),
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
                      // فقط موجودی کم با رنگ هشدار مشخص می‌شود.
                      color: items[i]['is_low'] == true
                          ? AppColors.moneyOut
                          : null,
                      emphasise: true,
                      // با لمس موجودی، گردش همان کالا باز می‌شود تا علت مقدار موجود قابل بررسی باشد.
                      onTap: () => showInventoryEntries(
                        context,
                        api: widget.api,
                        itemKey: '${items[i]['key']}',
                        itemName: '${items[i]['name']}',
                        subtitle: 'آخرین گردش‌ها',
                      ),
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
                          : '${flourBags(flour.totalBags)}'
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
                AdminDetailGroup(
                    title: 'سهمیه آرد',
                    subtitle:
                        '${quota['month_label'] ?? 'دوره جاری'} — ریز سهمیه و مانده',
                    icon: Icons.calendar_month_outlined,
                    builder: (_) =>
                        Column(children: _buildQuota(context, quota))),
            ],
          );
        },
      ),
    );
  }

  List<Widget> _buildQuota(BuildContext context, Map<String, dynamic> quota) {
    final periods = rowList(quota['periods']);

    return [
      AdminSection(
        title: 'سهمیه آرد — ${quota['month_label'] ?? ''}',
        icon: Icons.calendar_month_rounded,
        children: [
          AdminRow(
            label: 'کل سهمیه ماه',
            value: '${_fmt(quota['total_bags'])} کیسه',
            icon: Icons.scale_rounded,
            emphasise: true,
          ),
          // مانده قابل دریافت با انتقال سهمیه بین دوره‌ها محاسبه می‌شود؛ مبنای برنامه‌ریزی همین عدد است.
          if (quota['carried_balance'] is Map<String, dynamic>)
            AdminRow(
              label: 'ماندهٔ سهمیه — منتقل می‌شود',
              value: _carriedBalance(
                keyedGroup(quota['carried_balance']),
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
      // جمع سه دوره برای ماه نانوایی از پنجم تا چهارم، با محاسبه سرور نمایش داده می‌شود.
      if (quota['whole_period'] is Map<String, dynamic>)
        _PeriodCard(
          period: keyedGroup(quota['whole_period']),
          isTotal: true,
        ),
    ];
  }

  /// تعداد کیسه و وزن؛ اگر اندازه کیسه مشخص نباشد فقط وزن نمایش داده می‌شود.
  static String _carriedBalance(Map<String, dynamic> balance) {
    final bags = balance['remaining_bags'];

    if (bags == null) return '${_fmt(balance['remaining_kg'])} کیلوگرم';

    return '${_fmt(bags)} کیسه';
  }

  static IconData _iconFor(String key) => switch (key) {
        'flour' => Icons.grain_rounded,
        'salt' => Icons.scatter_plot_rounded,
        'dough' => Icons.bakery_dining_rounded,
        _ => Icons.inventory_rounded,
      };

  /// آرد فقط با تعداد کیسه نمایش داده می‌شود، چون واحد شمارش و سفارش مغازه است.
  /// نمک و خمیرمایه کیسه ثابت ندارند و با وزن نمایش داده می‌شوند.
  static String _balanceLabel(Map<String, dynamic> item) {
    final bags = item['balance_bags'];

    if (bags == null) return '${_fmt(item['balance'])} ${item['unit']}';

    return '${_fmt(bags)} کیسه';
  }
}

/// کارت سهمیه یکی از سه دوره یا جمع کل آن‌ها با نوار مصرف.
class _PeriodCard extends StatelessWidget {
  const _PeriodCard({required this.period, this.isTotal = false});

  /// جمع ماه پنجم تا چهارم، دوره جاری نیست و کادر دوره جاری را نمی‌گیرد.
  final bool isTotal;

  final Map<String, dynamic> period;

  /// تعداد کیسه بدون صفر اعشاری غیرضروری نمایش داده می‌شود.
  static String _bags(dynamic value) {
    final bags =
        value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

    return bags % 1 == 0 ? bags.toStringAsFixed(0) : bags.toStringAsFixed(1);
  }

  /// مصرف و مانده با وزن کیسه سهمیه به تعداد کیسه تبدیل می‌شوند.
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

    // رنگ پیوسته نوار میزان مصرف را نشان می‌دهد؛ عبور از سهمیه رنگ هشدار جدا دارد.
    final color = isOver
        ? Theme.of(context).colorScheme.error
        : AppColors.emberAt((percent / 100).clamp(0.0, 1.0));

    // رنگ طیفی فقط برای نوار است؛ متن باید در هر دو پوسته خوانا بماند.
    final wordsColour = isOver
        ? Theme.of(context).colorScheme.error
        : (percent >= 80 ? AppColors.attention : null);

    final card = Card(
      // دوره جاری با کادر مشخص می‌شود.
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
                backgroundColor:
                    Theme.of(context).dividerColor.withValues(alpha: 0.4),
              ),
            ),
            const SizedBox(height: 12),
            // تعداد کیسه واحد اصلی است و وزن برای اطلاعات حسابداری در پرانتز می‌آید.
            _FlourRow(
              label: 'سهمیه دوره',
              bags: _bags(period['allocated_bags']),
            ),
            _FlourRow(
              label: 'مصرف شده',
              bags:
                  _bags(period['used_bags'] ?? _bagsFromKg(period, 'used_kg')),
            ),
            _FlourRow(
              // مانده دوره با مانده کل قابل دریافت تفاوت دارد و عنوان آن این تفاوت را روشن می‌کند.
              label: isOver ? 'بیش از سهمیهٔ دوره' : 'باقی‌ماندهٔ دوره',
              bags: _bags(period['remaining_bags'] ??
                  _bagsFromKg(period, 'remaining_kg')),
              colour: wordsColour,
              emphasise: true,
            ),
            _BreadReconciliation(period: period),
          ],
        ),
      ),
    );

    if (!isTotal) return card;

    // جمع ماه با جداکننده مشخص می‌شود تا دوره چهارم برداشت نشود.
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

/// سهمیه فقط به کیسه نمایش داده می‌شود؛ وزن کنارش نوشته نمی‌شود.
class _FlourRow extends StatelessWidget {
  const _FlourRow({
    required this.label,
    required this.bags,
    this.colour,
    this.emphasise = false,
  });

  final String label;
  final String bags;
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
            ],
          ),
        ],
      ),
    );
  }
}

/// سهمیه آرد به تعداد نان نانینو تبدیل و با فروش کارت‌خوان مقایسه می‌شود.
/// وزن نان نانینو مبنای مقایسه است، چون شمارش مستقل فروش بر اساس همان انجام می‌شود.
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

    // بدون وزن نان نانینو، امکان محاسبه وجود ندارد.
    if (quota == 0) return const SizedBox.shrink();

    // فروش بیش از ظرفیت سهمیه باید با هشدار مشخص شود.
    final remainderColor =
        remainder < 0 ? AppColors.moneyOut : scheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(top: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Divider(height: 1, color: scheme.outlineVariant),
          const SizedBox(height: 12),
          Row(
            children: [
              Icon(Icons.bakery_dining_rounded,
                  size: IconSize.inline, color: scheme.primary),
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
