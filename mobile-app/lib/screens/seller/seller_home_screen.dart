import 'package:flutter/material.dart';

import '../../utils/formatters.dart';
import 'package:provider/provider.dart';

import '../../models/bakery.dart';
import '../../models/chane_board.dart';
import '../../models/entries.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/sync_status_card.dart';
import '../../widgets/work_start_card.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import '../../models/flour_sale.dart';
import '../shared/settings_screen.dart';
import 'flour_sale_sheet.dart';

/// Home screen for the seller. One scrolling page rather than tabs, so the
/// day's numbers, the chane waiting to be sold and the sales already made
/// are all reachable without switching context.
class SellerHomeScreen extends StatefulWidget {
  const SellerHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerHomeScreen> createState() => _SellerHomeScreenState();
}

typedef _SellerData = ({
  List<ChaneEntry> pending,
  ({List<Sale> sales, int count, double total}) today,
  ChaneBoard? board,
  ({
    List<FlourSale> sales,
    int count,
    double totalWeightKg,
    String totalFormatted,
  })? flour,
});

class _SellerHomeScreenState extends State<SellerHomeScreen> {
  late Future<_SellerData> _data;

  /// Bread price and currency, used to suggest a sale amount.
  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _data = _load();
    _loadBakery();
  }

  Future<_SellerData> _load() async {
    final pending = await widget.api.pendingChane();
    final today = await widget.api.todaySales();

    ChaneBoard? board;
    try {
      board = await widget.api.chaneBoard();
    } on ApiException {
      // The comparison is informational; the rest of the page still works.
      board = null;
    }

    // Flour selling is a permission the seller may not hold, so a failure
    // here hides the section rather than breaking the page.
    ({
      List<FlourSale> sales,
      int count,
      double totalWeightKg,
      String totalFormatted,
    })? flour;
    try {
      flour = await widget.api.todayFlourSales();
    } on ApiException {
      flour = null;
    }

    return (pending: pending, today: today, board: board, flour: flour);
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The price is a convenience, not a requirement.
    }
  }

  void _reload() => setState(() => _data = _load());

  Future<void> _openSaleSheet(ChaneEntry chane) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _RecordSaleSheet(
        api: widget.api,
        chane: chane,
        bakery: _bakery,
      ),
    );

    if (saved == true) _reload();
  }

  Future<void> _openFlourSaleSheet() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => FlourSaleSheet(api: widget.api, bakery: _bakery),
    );

    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final scheme = Theme.of(context).colorScheme;
    final unit = _bakery?.currency ?? Currency.toman;

    return Scaffold(
      appBar: AppBar(
        title: const Text('فروش'),
        actions: [
          const ThemeToggleButton(),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const SettingsScreen()),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => _reload(),
          child: FutureBuilder<_SellerData>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }

              if (snapshot.hasError) {
                return ListView(
                  padding: const EdgeInsets.all(20),
                  children: [
                    ErrorBox(message: '${snapshot.error}', onRetry: _reload),
                  ],
                );
              }

              final data = snapshot.data!;
              final pending = data.pending;
              final today = data.today;

              return ListView(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
                children: [
                  Text(
                    'سلام ${user?.name ?? ''}',
                    style: Theme.of(context)
                        .textTheme
                        .titleLarge
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 14),
                  SyncStatusCard(api: widget.api),
              const SizedBox(height: 14),
              AttendanceCard(api: widget.api),

                  const SizedBox(height: 14),
                  WorkStartCard(api: widget.api),

                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: StatTile(
                          label: 'فروش امروز',
                          value: '${today.count}',
                          icon: Icons.receipt_rounded,
                          color: scheme.primary,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: StatTile(
                          label: 'مجموع (${unit.label})',
                          value: MoneyFormat.plain(today.total, currency: unit),
                          icon: Icons.payments_rounded,
                          color: const Color(0xFF2E9E6B),
                        ),
                      ),
                    ],
                  ),

                  if (data.board != null) ...[
                    const SizedBox(height: 16),
                    ChaneComparison(board: data.board!),
                  ],

                  const SizedBox(height: 22),
                  _SectionHeader(
                    title: 'چانه‌های آماده فروش',
                    count: pending.length,
                    icon: Icons.storefront_rounded,
                  ),
                  const SizedBox(height: 10),
                  if (pending.isEmpty)
                    const _InlineEmpty(
                      icon: Icons.done_all_rounded,
                      text: 'همه چانه‌ها فروخته شده‌اند.',
                    )
                  else
                    for (final entry in pending) ...[
                      ActionCard(
                        title: '${entry.chaneCount} چانه',
                        subtitle: 'وزن: ${entry.weightKg.toStringAsFixed(2)} کیلوگرم'
                            '${entry.userName != null ? '  •  ${entry.userName}' : ''}',
                        icon: Icons.shopping_basket_rounded,
                        color: const Color(0xFF3B82C4),
                        onTap: () => _openSaleSheet(entry),
                        trailing: const Icon(Icons.point_of_sale_rounded),
                      ),
                      const SizedBox(height: 10),
                    ],

                  if (data.flour != null) ...[
                    const SizedBox(height: 22),
                    _SectionHeader(
                      title: 'فروش آرد امروز',
                      count: data.flour!.count,
                      icon: Icons.inventory_2_rounded,
                    ),
                    const SizedBox(height: 10),
                    ActionCard(
                      title: 'فروش آرد (کیلویی یا کیسه‌ای)',
                      subtitle: data.flour!.count == 0
                          ? 'امروز آردی فروخته نشده است'
                          : '${data.flour!.count} فروش  •  '
                              '${data.flour!.totalWeightKg.toStringAsFixed(1)} کیلوگرم  •  '
                              '${data.flour!.totalFormatted}',
                      icon: Icons.local_shipping_rounded,
                      color: const Color(0xFFE8952D),
                      onTap: _openFlourSaleSheet,
                      trailing: const Icon(Icons.add_rounded),
                    ),
                    for (final sale in data.flour!.sales) ...[
                      const SizedBox(height: 10),
                      _FlourSaleTile(sale: sale),
                    ],
                  ],

                  const SizedBox(height: 16),
                  _SectionHeader(
                    title: 'فروش‌های امروز',
                    count: today.sales.length,
                    icon: Icons.receipt_long_rounded,
                  ),
                  const SizedBox(height: 10),
                  if (today.sales.isEmpty)
                    const _InlineEmpty(
                      icon: Icons.receipt_long_outlined,
                      text: 'امروز هنوز فروشی ثبت نشده است.',
                    )
                  else
                    for (final sale in today.sales) ...[
                      _SaleTile(sale: sale, unit: unit),
                      const SizedBox(height: 10),
                    ],
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

/// A small titled divider between the page's sections.
class _SectionHeader extends StatelessWidget {
  const _SectionHeader({
    required this.title,
    required this.count,
    required this.icon,
  });

  final String title;
  final int count;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Row(
      children: [
        Icon(icon, size: 18, color: scheme.primary),
        const SizedBox(width: 8),
        Text(
          title,
          style: Theme.of(context)
              .textTheme
              .titleSmall
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(width: 8),
        if (count > 0)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(
              color: scheme.primary.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text(
              '$count',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.primary,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ),
      ],
    );
  }
}

/// Compact empty state for a section inside a longer page.
class _InlineEmpty extends StatelessWidget {
  const _InlineEmpty({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 20),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(icon, color: scheme.onSurfaceVariant, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              text,
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ),
        ],
      ),
    );
  }
}

class _SaleTile extends StatelessWidget {
  const _SaleTile({required this.sale, required this.unit});

  final Sale sale;
  final Currency unit;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: CircleAvatar(
          backgroundColor: scheme.primary.withValues(alpha: 0.14),
          child: Icon(Icons.sell_rounded, color: scheme.primary, size: 20),
        ),
        title: Text(
          sale.amount != null
              ? MoneyFormat.format(sale.amount, currency: unit)
              : 'بدون مبلغ',
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Text(JalaliFormat.time(sale.createdAt)),
        trailing: Chip(
          label: Text(sale.paymentType.label),
          visualDensity: VisualDensity.compact,
        ),
      ),
    );
  }
}

class _RecordSaleSheet extends StatefulWidget {
  const _RecordSaleSheet({
    required this.api,
    required this.chane,
    this.bakery,
  });

  final BakeryApi api;
  final ChaneEntry chane;
  final Bakery? bakery;

  @override
  State<_RecordSaleSheet> createState() => _RecordSaleSheetState();
}

class _RecordSaleSheetState extends State<_RecordSaleSheet> {
  final _formKey = GlobalKey<FormState>();
  final _breadCount = TextEditingController();
  final _amount = TextEditingController();
  final _note = TextEditingController();

  PaymentType? _paymentType;
  bool _saving = false;

  /// True once the seller has typed their own amount, so entering the bread
  /// count afterwards does not silently overwrite what they set.
  bool _amountEditedByHand = false;

  @override
  void initState() {
    super.initState();

    // Defaults to the full batch; the seller can sell fewer.
    _breadCount.text = '${widget.chane.chaneCount}';
    _recomputeAmount();

    _breadCount.addListener(() {
      _recomputeAmount();
      setState(() {}); // Refreshes the shortfall notice below the field.
    });
  }

  @override
  void dispose() {
    _breadCount.dispose();
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  double get _unitPrice => widget.bakery?.breadPrice ?? 0;

  Currency get _unit => widget.bakery?.currency ?? Currency.toman;

  int? get _enteredBreadCount => int.tryParse(_breadCount.text.trim());

  /// How many of this batch are left unsold once this amount is entered.
  /// Selling the whole batch marks it "sold" regardless, so this is the
  /// seller's own record of what did not get counted.
  int? get _shortfall {
    final entered = _enteredBreadCount;
    if (entered == null) return null;

    final remainder = widget.chane.chaneCount - entered;
    return remainder > 0 ? remainder : null;
  }

  /// Bread count × unit price — recalculated on every keystroke, unless the
  /// seller has already typed their own amount by hand.
  void _recomputeAmount() {
    if (_amountEditedByHand || _unitPrice <= 0) return;

    final count = _enteredBreadCount ?? widget.chane.chaneCount;
    final suggested = count * _unitPrice * _unit.multiplier;

    _amount.value = _amount.value.copyWith(text: suggested.toStringAsFixed(0));
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    if (_paymentType == null) {
      showMessage(context, 'نوع پرداخت را انتخاب کنید.', isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final typed = _amount.text.isEmpty ? null : double.tryParse(_amount.text);

      final queued = await widget.api.recordSale(
        chaneEntryId: widget.chane.id,
        paymentType: _paymentType!,
        breadCount: _enteredBreadCount,
        // The API always stores Toman, whatever unit the shop displays.
        amount: typed == null ? null : MoneyFormat.toToman(typed, currency: _unit),
        note: _note.text.trim(),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        queued
            ? 'اینترنت وصل نیست؛ فروش ذخیره شد و با اتصال بعدی ارسال می‌شود.'
            : 'فروش ثبت شد.',
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 44,
                    height: 4,
                    decoration: BoxDecoration(
                      color: scheme.outlineVariant,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 22),
                Text(
                  'ثبت فروش',
                  style: Theme.of(context)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 6),
                Text(
                  'چانه #${widget.chane.id} — ${widget.chane.chaneCount} عدد '
                  '(${widget.chane.weightKg.toStringAsFixed(2)} کیلوگرم)',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
                const SizedBox(height: 22),
                Text(
                  'تعداد نان',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 10),
                TextFormField(
                  controller: _breadCount,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: 'تعداد',
                    prefixIcon: const Icon(Icons.bakery_dining_outlined),
                    suffixText: 'از ${widget.chane.chaneCount} عدد',
                  ),
                  validator: (value) {
                    if (value == null || value.trim().isEmpty) {
                      return 'تعداد نان را وارد کنید';
                    }
                    final parsed = int.tryParse(value.trim());
                    if (parsed == null || parsed < 0) {
                      return 'عددی معتبر وارد کنید';
                    }
                    if (parsed > widget.chane.chaneCount) {
                      return 'از تعداد این چانه بیشتر است';
                    }
                    return null;
                  },
                ),
                if (_shortfall != null) ...[
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    decoration: BoxDecoration(
                      color: const Color(0xFFE8952D).withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.info_outline_rounded,
                            size: 18, color: Color(0xFFE8952D)),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'کسری این فروش: $_shortfall عدد، به عنوان بدهی موقت ثبت می‌شود.',
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: const Color(0xFFE8952D),
                                  fontWeight: FontWeight.w700,
                                ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],

                const SizedBox(height: 22),
                Text(
                  'نوع پرداخت',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                Card(
                  margin: EdgeInsets.zero,
                  child: RadioGroup<PaymentType>(
                    groupValue: _paymentType,
                    onChanged: (value) => setState(() => _paymentType = value),
                    child: Column(
                      children: [
                        for (final type in PaymentType.values) ...[
                          RadioListTile<PaymentType>(
                            value: type,
                            title: Text(type.label),
                            dense: true,
                          ),
                          if (type != PaymentType.values.last)
                            const Divider(height: 1),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  onChanged: (_) => setState(() => _amountEditedByHand = true),
                  decoration: InputDecoration(
                    labelText: 'مبلغ نهایی',
                    prefixIcon: const Icon(Icons.payments_outlined),
                    suffixText: _unit.label,
                    helperText: _unitPrice > 0
                        ? '${_enteredBreadCount ?? widget.chane.chaneCount} نان × '
                            '${MoneyFormat.format(_unitPrice, currency: _unit)}'
                        : 'قیمت نان در تنظیمات نانوایی ثبت نشده است',
                  ),
                  validator: (value) {
                    if (value == null || value.isEmpty) return null;
                    final parsed = double.tryParse(value);
                    if (parsed == null) return 'یک عدد معتبر وارد کنید';
                    if (parsed < 0) return 'مبلغ نمی‌تواند منفی باشد';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _note,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'توضیحات (اختیاری)',
                    prefixIcon: Icon(Icons.notes_rounded),
                  ),
                ),
                const SizedBox(height: 24),
                FilledButton.icon(
                  onPressed: _saving ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.check_rounded),
                  label: Text(_saving ? 'در حال ثبت…' : 'ثبت فروش'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}


/// One flour sale in the day's list.
class _FlourSaleTile extends StatelessWidget {
  const _FlourSaleTile({required this.sale});

  final FlourSale sale;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(
            sale.unit == FlourUnit.bag
                ? Icons.shopping_bag_rounded
                : Icons.scale_rounded,
            size: 20,
            color: const Color(0xFFE8952D),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  sale.quantityLabel,
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                Text(
                  [
                    sale.paymentType.label,
                    if (sale.customerName != null) sale.customerName!,
                  ].join('  •  '),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
          ),
          Text(
            sale.amountFormatted,
            style: Theme.of(context)
                .textTheme
                .bodyMedium
                ?.copyWith(fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}
