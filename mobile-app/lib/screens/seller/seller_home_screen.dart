import 'package:flutter/material.dart';

import '../../utils/formatters.dart';
import 'package:provider/provider.dart';

import '../../models/bakery.dart';
import '../../models/chane_board.dart';
import '../../models/customer.dart';
import '../../models/entries.dart';
import '../../models/work_start.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/seller_account_card.dart';
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
                  WorkStartCard(
                    api: widget.api,
                    // Shaping start is the chane gir's tick — the seller
                    // only records the start of baking.
                    visibleTypes: const {WorkStartType.baking},
                  ),

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

                  const SizedBox(height: 14),
                  SellerAccountCard(api: widget.api),

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
  final _note = TextEditingController();

  /// Loaves entered against each payment type. A type stays out of the map
  /// until it is actually used, so the summary only names what was paid.
  final Map<PaymentType, int> _counts = {};

  /// Buyer per payment type, needed for نسیه and مدارس.
  final Map<PaymentType, int?> _customers = {};

  List<Customer> _customerOptions = const [];
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    // The common case is the whole batch paid in cash, so start there and
    // let the seller move loaves onto other rows as needed.
    _counts[PaymentType.cash] = widget.chane.chaneCount;
    _loadCustomers();
  }

  Future<void> _loadCustomers() async {
    try {
      final list = await widget.api.customers();
      if (mounted) setState(() => _customerOptions = list);
    } on ApiException {
      // Only نسیه and مدارس need it; the rest of the sheet still works.
    }
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  double get _unitPrice => widget.bakery?.breadPrice ?? 0;

  Currency get _unit => widget.bakery?.currency ?? Currency.toman;

  int get _totalCount =>
      _counts.values.fold(0, (sum, count) => sum + count);

  double get _totalAmount => _totalCount * _unitPrice * _unit.multiplier;

  /// Loaves of the batch not yet placed on any payment row. Recorded as a
  /// temporary debt against the seller, so it is worth showing plainly.
  int get _unassigned => widget.chane.chaneCount - _totalCount;

  int _countFor(PaymentType type) => _counts[type] ?? 0;

  void _setCount(PaymentType type, int value) {
    setState(() {
      final clamped = value.clamp(0, widget.chane.chaneCount + 100000);

      if (clamped == 0) {
        _counts.remove(type);
        _customers.remove(type);
      } else {
        _counts[type] = clamped;
      }
    });
  }

  /// Puts every loaf still unassigned onto this row — the usual gesture
  /// when one payment type covers the rest of the batch.
  void _fill(PaymentType type) {
    if (_unassigned > 0) _setCount(type, _countFor(type) + _unassigned);
  }

  String? _blockingProblem() {
    if (_totalCount == 0) return 'برای حداقل یک نوع پرداخت تعداد نان وارد کنید.';

    if (_totalCount > widget.chane.chaneCount) {
      return 'مجموع تعداد نان از ${widget.chane.chaneCount} عدد این چانه بیشتر است.';
    }

    for (final type in _counts.keys) {
      if (type.needsCustomer && _customers[type] == null) {
        return 'برای «${type.label}» مشتری را انتخاب کنید.';
      }
    }

    return null;
  }

  Future<void> _save() async {
    final problem = _blockingProblem();

    if (problem != null) {
      showMessage(context, problem, isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final payments = _counts.entries
          .map((entry) => SalePaymentLine(
                paymentType: entry.key,
                breadCount: entry.value,
                // The API always stores Toman, whatever the shop displays.
                amount: MoneyFormat.toToman(
                  entry.value * _unitPrice * _unit.multiplier,
                  currency: _unit,
                ),
                customerId: _customers[entry.key],
              ))
          .toList();

      final queued = await widget.api.recordSplitSale(
        chaneEntryId: widget.chane.id,
        payments: payments,
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
                'چانه #${widget.chane.id} — ${widget.chane.chaneCount} عدد',
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(color: scheme.onSurfaceVariant),
              ),

              const SizedBox(height: 18),
              _RemainingBanner(
                unassigned: _unassigned,
                batchCount: widget.chane.chaneCount,
              ),

              const SizedBox(height: 18),
              Text(
                'تعداد نان به تفکیک نوع پرداخت',
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 10),

              for (final type in PaymentType.values)
                _PaymentRow(
                  type: type,
                  count: _countFor(type),
                  unitPrice: _unitPrice,
                  unit: _unit,
                  customers: _customerOptions,
                  selectedCustomer: _customers[type],
                  canFill: _unassigned > 0,
                  onChanged: (value) => _setCount(type, value),
                  onFill: () => _fill(type),
                  onCustomerChanged: (id) =>
                      setState(() => _customers[type] = id),
                ),

              const SizedBox(height: 16),
              _TotalRow(
                count: _totalCount,
                amount: _totalAmount,
                unit: _unit,
                hasPrice: _unitPrice > 0,
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

              const SizedBox(height: 20),
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
    );
  }
}

/// How much of the batch is still unaccounted for, stated plainly because
/// anything left over is recorded as a debt against the seller.
class _RemainingBanner extends StatelessWidget {
  const _RemainingBanner({required this.unassigned, required this.batchCount});

  final int unassigned;
  final int batchCount;

  @override
  Widget build(BuildContext context) {
    final (color, icon, text) = switch (unassigned) {
      0 => (
          const Color(0xFF2E9E6B),
          Icons.check_circle_rounded,
          'همه $batchCount نان این چانه ثبت شد.',
        ),
      < 0 => (
          const Color(0xFFD1495B),
          Icons.error_rounded,
          '${-unassigned} نان بیشتر از این چانه وارد شده است.',
        ),
      _ => (
          const Color(0xFFE8952D),
          Icons.info_rounded,
          '$unassigned نان باقی مانده — اگر ثبت نشود، بدهی موقت فروشنده می‌شود.',
        ),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 20, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: color,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One payment type with its loaf count, a stepper either side, and the
/// money it comes to. Tapping the row's + button sweeps up whatever is
/// left of the batch, which is the usual case.
class _PaymentRow extends StatelessWidget {
  const _PaymentRow({
    required this.type,
    required this.count,
    required this.unitPrice,
    required this.unit,
    required this.customers,
    required this.selectedCustomer,
    required this.canFill,
    required this.onChanged,
    required this.onFill,
    required this.onCustomerChanged,
  });

  final PaymentType type;
  final int count;
  final double unitPrice;
  final Currency unit;
  final List<Customer> customers;
  final int? selectedCustomer;
  final bool canFill;
  final ValueChanged<int> onChanged;
  final VoidCallback onFill;
  final ValueChanged<int?> onCustomerChanged;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final active = count > 0;
    final amount = count * unitPrice * unit.multiplier;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: active
            ? scheme.primary.withValues(alpha: 0.08)
            : scheme.surfaceContainerHighest.withValues(alpha: 0.35),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: active ? scheme.primary.withValues(alpha: 0.4) : Colors.transparent,
        ),
      ),
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      type.label,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    if (active && unitPrice > 0)
                      Text(
                        MoneyFormat.format(amount, currency: unit),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      ),
                  ],
                ),
              ),
              IconButton(
                onPressed: count > 0 ? () => onChanged(count - 1) : null,
                icon: const Icon(Icons.remove_circle_outline_rounded),
                visualDensity: VisualDensity.compact,
                tooltip: 'یکی کمتر',
              ),
              SizedBox(
                width: 52,
                child: Text(
                  '$count',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: active ? scheme.primary : scheme.onSurfaceVariant,
                      ),
                ),
              ),
              IconButton(
                onPressed: () => onChanged(count + 1),
                icon: const Icon(Icons.add_circle_outline_rounded),
                visualDensity: VisualDensity.compact,
                tooltip: 'یکی بیشتر',
              ),
              IconButton(
                onPressed: canFill ? onFill : null,
                icon: const Icon(Icons.playlist_add_rounded),
                visualDensity: VisualDensity.compact,
                tooltip: 'باقی‌مانده را اینجا بگذار',
              ),
            ],
          ),

          // Only نسیه and مدارس need a named buyer, and only once used.
          if (active && type.needsCustomer)
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 4),
              child: DropdownButtonFormField<int>(
                initialValue: selectedCustomer,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'مشتری',
                  isDense: true,
                  prefixIcon: Icon(Icons.account_balance_rounded, size: 20),
                ),
                items: [
                  for (final customer in customers)
                    DropdownMenuItem(
                      value: customer.id,
                      child: Text(customer.name),
                    ),
                ],
                onChanged: onCustomerChanged,
              ),
            ),
        ],
      ),
    );
  }
}

/// The batch total, which is what the seller actually hands over.
class _TotalRow extends StatelessWidget {
  const _TotalRow({
    required this.count,
    required this.amount,
    required this.unit,
    required this.hasPrice,
  });

  final int count;
  final double amount;
  final Currency unit;
  final bool hasPrice;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(
        color: scheme.primary.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          Icon(Icons.payments_rounded, color: scheme.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'جمع کل — $count نان',
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ),
          Text(
            hasPrice
                ? MoneyFormat.format(amount, currency: unit)
                : 'قیمت نان ثبت نشده',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: scheme.primary,
                ),
          ),
        ],
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
