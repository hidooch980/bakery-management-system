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
import '../../widgets/pay_card.dart';
import '../../widgets/seller_account_card.dart';
import '../../widgets/seller_ask.dart';
import '../../widgets/seller_collections_card.dart';
import '../../widgets/station_rail.dart';
import '../../widgets/role_home_scaffold.dart';
import '../../widgets/work_start_card.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import '../../models/flour_sale.dart';
import 'flour_sale_sheet.dart';
import 'seller_workbench.dart';
import '../../theme/app_theme.dart';

/// Home screen for the seller.
///
/// It used to be one scrolling page of everything: the greeting, attendance,
/// the workbench, chane waiting to be sold, flour, the account, the day's
/// sales. Reaching the sales meant scrolling past the lot. The same content
/// is now three pages — what the day looks like, the selling itself, and
/// what the seller is answerable for — laid out the way the admin's screen
/// already was.
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

  /// Set while the one-button answer is in flight, so the button cannot be
  /// pressed twice into two sales.
  bool _confirming = false;

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

  /// The whole batch, cash — the answer nearly every day has, and the one
  /// the old sheet pre-filled and then made the seller scroll past five
  /// more fields to agree with.
  ///
  /// It posts through `recordSplitSale` like the sheet does, with one line
  /// instead of six. Anything else — a shortfall, a school, bread taken
  /// home — is a real division and goes through the sheet, unchanged.
  Future<void> _recordAllCash(ChaneEntry chane) async {
    setState(() => _confirming = true);

    final price = _bakery?.breadPrice ?? 0;

    try {
      final queued = await widget.api.recordSplitSale(
        chaneEntryId: chane.id,
        payments: [
          SalePaymentLine(
            paymentType: PaymentType.cash,
            breadCount: chane.chaneCount,
            amount: chane.chaneCount * price,
          ),
        ],
      );

      if (!mounted) return;

      showMessage(
        context,
        queued
            ? 'اینترنت وصل نیست؛ فروش ذخیره شد و با اتصال بعدی ارسال می‌شود.'
            : 'فروش ثبت شد.',
      );

      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _confirming = false);
    }
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
    return RoleHomeScaffold(
      api: widget.api,
      bakery: _bakery,
      tabs: [
        HomeTab(
          label: 'خلاصه',
          title: 'خلاصه امروز',
          icon: Icons.dashboard_outlined,
          selectedIcon: Icons.dashboard_rounded,
          builder: (_) => _withData(_overview),
        ),
        HomeTab(
          label: 'فروش',
          title: 'فروش',
          icon: Icons.point_of_sale_outlined,
          selectedIcon: Icons.point_of_sale_rounded,
          builder: (_) => _withData(_selling),
        ),
        HomeTab(
          label: 'حساب من',
          title: 'حساب من',
          icon: Icons.account_balance_wallet_outlined,
          selectedIcon: Icons.account_balance_wallet_rounded,
          builder: (_) => _withData(_account),
        ),
      ],
    );
  }

  /// Every page reads the same fetch and pulls to refresh the same way, so
  /// switching pages does not re-ask the server and the three cannot show
  /// figures from different moments.
  Widget _withData(List<Widget> Function(_SellerData data) children) {
    return RefreshIndicator(
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

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: children(snapshot.data!),
          );
        },
      ),
    );
  }

  // ------------------------------------------------------------ خلاصه

  List<Widget> _overview(_SellerData data) {
    final user = context.watch<AuthProvider>().user;
    final scheme = Theme.of(context).colorScheme;
    final unit = _bakery?.currency ?? Currency.toman;
    final today = data.today;
    final pending = data.pending;

    return [
      Text(
        'سلام ${user?.name ?? ''}',
        style: Theme.of(context)
            .textTheme
            .titleLarge
            ?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 14),
      StationRail(
        trailing: data.board == null
            ? null
            : '${data.board!.waitingChane} چانه در انتظار پخت',
        stations: [
          Station(
            label: 'خمیر',
            value: '${data.board?.doughBagsToday ?? 0}',
            state: StationState.done,
          ),
          Station(
            label: 'چانه',
            value: '${pending.fold<int>(0, (a, c) => a + c.chaneCount)}',
            state: pending.isEmpty ? StationState.done : StationState.active,
          ),
          Station(
            label: 'فروش',
            value: '${today.count}',
            state: today.count > 0 ? StationState.done : StationState.idle,
          ),
        ],
      ),
      const SizedBox(height: 14),
      // The seller is the one on the floor holding a phone, so they mark
      // in the bakers who are not.
      AttendanceCard(api: widget.api, canRecordForOthers: true),
      const SizedBox(height: 14),
      PayCard(api: widget.api),
      const SizedBox(height: 14),
      WorkStartCard(
        api: widget.api,
        // Shaping start is the chane gir's tick — the seller only records
        // the start of baking.
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
              color: AppColors.moneyIn,
            ),
          ),
        ],
      ),
      if (data.board != null) ...[
        const SizedBox(height: 16),
        ChaneComparison(board: data.board!),
      ],
    ];
  }

  // ------------------------------------------------------------- فروش

  List<Widget> _selling(_SellerData data) {
    final unit = _bakery?.currency ?? Currency.toman;
    final pending = data.pending;
    final today = data.today;

    return [
      // Kneading, shaping, flour and who is in today. Sections hide
      // themselves when the permission is not held, so a shop that keeps
      // the roles separate sees nothing new.
      SellerWorkbench(
        api: widget.api,
        bakery: _bakery,
        onChanged: _reload,
      ),
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
      // One batch is not a choice, so it is not offered as one: the screen
      // asks the question instead. Several batches is a real choice and
      // gets the list — the same rule the chane maker's screen follows.
      else if (pending.length == 1)
        SellerAsk(
          chane: pending.single,
          bakery: _bakery,
          saving: _confirming,
          onAllCash: () => _recordAllCash(pending.single),
          onSplit: () => _openSaleSheet(pending.single),
        )
      else
        for (final entry in pending) ...[
          ActionCard(
            title: '${entry.chaneCount} چانه',
            subtitle: 'وزن: ${entry.weightKg.toStringAsFixed(2)} کیلوگرم'
                '${entry.userName != null ? '  •  ${entry.userName}' : ''}',
            icon: Icons.shopping_basket_rounded,
            color: AppColors.moneyNeutral,
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
          color: AppColors.stock,
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
          icon: Icons.receipt_long_rounded,
          text: 'امروز هنوز فروشی ثبت نشده است.',
        )
      else
        for (final sale in today.sales) ...[
          _SaleTile(sale: sale, unit: unit),
          const SizedBox(height: 10),
        ],
    ];
  }

  // --------------------------------------------------------- حساب من

  List<Widget> _account(_SellerData data) {
    return [
      SellerAccountCard(api: widget.api),
      // Money the buyers who run an account still owe, and what they have
      // handed back.
      const SizedBox(height: 14),
      SellerCollectionsCard(api: widget.api),
    ];
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
        Icon(icon, size: IconSize.row, color: scheme.primary),
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
              borderRadius: BorderRadius.circular(Corner.chip),
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
        borderRadius: BorderRadius.circular(Corner.control),
      ),
      child: Row(
        children: [
          Icon(icon, color: scheme.onSurfaceVariant, size: IconSize.button),
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
          child: Icon(Icons.sell_rounded, color: scheme.primary, size: IconSize.button),
        ),
        title: Text(
          sale.amount != null
              ? MoneyFormat.format(sale.amount, currency: unit)
              : 'بدون مبلغ',
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: Theme.of(context).colorScheme.onSurface,
                    ),
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

  /// One field per payment type. A sale can run to hundreds of loaves,
  /// so the count is typed rather than stepped.
  final Map<PaymentType, TextEditingController> _fields = {
    for (final type in PaymentType.choices) type: TextEditingController(),
  };

  /// Buyer per payment type, needed for نسیه and مدارس.
  final Map<PaymentType, int?> _customers = {};

  /// Who took the bread, for the «منزل» row. Left empty it behaves as it
  /// always has — bread owed by nobody — so an older habit still works.
  int? _consumedBy;

  List<Customer> _customerOptions = const [];
  List<StaffName> _staff = const [];
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    // The common case is the whole batch paid in cash, so start there and
    // let the seller move loaves onto other rows as needed.
    _fields[PaymentType.cash]!.text = '${widget.chane.chaneCount}';

    // Every keystroke moves the running total and the banner above it.
    for (final field in _fields.values) {
      field.addListener(() => setState(() {}));
    }

    _loadCustomers();
    _loadStaff();
  }

  Future<void> _loadCustomers() async {
    try {
      final list = await widget.api.customers();
      if (mounted) setState(() => _customerOptions = list);
    } on ApiException {
      // Only نسیه and مدارس need it; the rest of the sheet still works.
    }
  }

  Future<void> _loadStaff() async {
    try {
      final list = await widget.api.saleStaff();
      if (mounted) setState(() => _staff = list);
    } on ApiException {
      // Only «منزل» needs it, and naming somebody is optional there, so
      // a failure here must not stop a sale being recorded.
    }
  }

  @override
  void dispose() {
    for (final field in _fields.values) {
      field.dispose();
    }
    _note.dispose();
    super.dispose();
  }

  double get _unitPrice => widget.bakery?.breadPrice ?? 0;

  Currency get _unit => widget.bakery?.currency ?? Currency.toman;

  int get _totalCount => PaymentType.choices
      .fold(0, (sum, type) => sum + _countFor(type));

  /// In Toman, the unit everything is stored in. MoneyFormat converts
  /// to the shop's display unit when it renders — doing it here too
  /// showed every figure ten times over on a Rial shop.
  double get _totalAmount => _usedTypes
      .where((type) => !type.expectsNoAmount)
      .fold(0.0, (sum, type) => sum + _countFor(type) * _unitPrice);

  /// Loaves of the batch not yet placed on any payment row. Recorded as a
  /// temporary debt against the seller, so it is worth showing plainly.
  int get _unassigned => widget.chane.chaneCount - _totalCount;

  int _countFor(PaymentType type) =>
      int.tryParse(_fields[type]!.text.trim()) ?? 0;

  /// Puts every loaf still unassigned onto this row — the usual gesture
  /// when one payment type covers the rest of the batch.
  void _fill(PaymentType type) {
    if (_unassigned <= 0) return;

    _fields[type]!.text = '${_countFor(type) + _unassigned}';
  }

  /// Payment types actually used, so the summary names only what was paid.
  Iterable<PaymentType> get _usedTypes =>
      PaymentType.choices.where((type) => _countFor(type) > 0);

  String? _blockingProblem() {
    if (_totalCount == 0) return 'برای حداقل یک نوع پرداخت تعداد نان وارد کنید.';

    if (_totalCount > widget.chane.chaneCount) {
      return 'مجموع تعداد نان از ${widget.chane.chaneCount} عدد این چانه بیشتر است.';
    }

    for (final type in _usedTypes) {
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
      final payments = _usedTypes
          .map((type) => SalePaymentLine(
                paymentType: type,
                breadCount: _countFor(type),
                // The API always stores Toman, whatever the shop displays,
                // and the bread price is already in it. Bread given away
                // is sent with no amount rather than a zero, which would
                // read as money that went missing.
                amount: type.expectsNoAmount ? null : _countFor(type) * _unitPrice,
                customerId: _customers[type],
                // Only «منزل» carries a person. Charity is a gift and is
                // owed by nobody, so naming one there would charge
                // somebody for bread they gave away.
                consumedByUserId:
                    type == PaymentType.home ? _consumedBy : null,
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
                    borderRadius: BorderRadius.circular(Corner.hair),
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

              for (final type in PaymentType.choices)
                _PaymentRow(
                  key: ValueKey(type),
                  type: type,
                  controller: _fields[type]!,
                  count: _countFor(type),
                  unitPrice: _unitPrice,
                  unit: _unit,
                  customers: _customerOptions,
                  selectedCustomer: _customers[type],
                  canFill: _unassigned > 0,
                  onFill: () => _fill(type),
                  onCustomerChanged: (id) =>
                      setState(() => _customers[type] = id),
                  staff: _staff,
                  selectedConsumer: _consumedBy,
                  onConsumerChanged: (id) => setState(() => _consumedBy = id),
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
                    ? SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Theme.of(context).colorScheme.onPrimary),
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
          AppColors.moneyIn,
          Icons.check_circle_rounded,
          'همه $batchCount نان این چانه ثبت شد.',
        ),
      < 0 => (
          AppColors.moneyOut,
          Icons.error_rounded,
          '${-unassigned} نان بیشتر از این چانه وارد شده است.',
        ),
      _ => (
          AppColors.attention,
          Icons.info_rounded,
          '$unassigned نان باقی مانده — اگر ثبت نشود، بدهی موقت فروشنده می‌شود.',
        ),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(Corner.chip),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          Icon(icon, size: IconSize.button, color: color),
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
/// One payment type with its loaf count typed in, and the money it comes
/// to. A sale can run to hundreds of loaves, so the count is entered
/// rather than stepped; the button beside it sweeps up whatever is left of
/// the batch, which is the usual case.
class _PaymentRow extends StatelessWidget {
  const _PaymentRow({
    super.key,
    required this.type,
    required this.controller,
    required this.count,
    required this.unitPrice,
    required this.unit,
    required this.customers,
    required this.selectedCustomer,
    required this.canFill,
    required this.onFill,
    required this.onCustomerChanged,
    required this.staff,
    required this.selectedConsumer,
    required this.onConsumerChanged,
  });

  final PaymentType type;
  final TextEditingController controller;
  final int count;
  final double unitPrice;
  final Currency unit;
  final List<Customer> customers;
  final int? selectedCustomer;
  final bool canFill;
  final VoidCallback onFill;
  final ValueChanged<int?> onCustomerChanged;
  final List<StaffName> staff;
  final int? selectedConsumer;
  final ValueChanged<int?> onConsumerChanged;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final active = count > 0;

    // Toman, the unit everything is stored in. MoneyFormat converts to the
    // shop's display unit when it renders.
    final amount = type.expectsNoAmount ? 0.0 : count * unitPrice;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: active
            ? scheme.primary.withValues(alpha: 0.08)
            : scheme.surfaceContainerHighest.withValues(alpha: 0.35),
        borderRadius: BorderRadius.circular(Corner.control),
        border: Border.all(
          color: active ? scheme.primary.withValues(alpha: 0.4) : Colors.transparent,
        ),
      ),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
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
                    if (active && type.expectsNoAmount)
                      Text(
                        'بدون دریافت وجه',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      )
                    else if (active && unitPrice > 0)
                      Text(
                        MoneyFormat.format(amount, currency: unit),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      ),
                  ],
                ),
              ),
              SizedBox(
                width: 92,
                child: TextFormField(
                  controller: controller,
                  keyboardType: TextInputType.number,
                  textAlign: TextAlign.center,
                  decoration: const InputDecoration(
                    isDense: true,
                    hintText: '۰',
                    suffixText: 'نان',
                  ),
                  validator: (value) {
                    final text = value?.trim() ?? '';
                    if (text.isEmpty) return null;

                    final parsed = int.tryParse(text);
                    if (parsed == null || parsed < 0) return 'عدد';
                    return null;
                  },
                ),
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
                  prefixIcon: Icon(Icons.account_balance_rounded, size: IconSize.button),
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

          // «منزل» only, and only once used. Optional on purpose: leaving
          // it empty records what the shop has always recorded — bread
          // owed by nobody. Naming somebody charges their payslip.
          if (active && type == PaymentType.home && staff.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4, bottom: 4),
              child: DropdownButtonFormField<int>(
                initialValue: selectedConsumer,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'چه کسی برد (اختیاری)',
                  helperText: 'اگر انتخاب کنید، آخر ماه از حقوقش کسر می‌شود',
                  isDense: true,
                  prefixIcon: Icon(Icons.person_rounded, size: IconSize.button),
                ),
                items: [
                  const DropdownMenuItem(value: null, child: Text('—')),
                  for (final person in staff)
                    DropdownMenuItem(
                      value: person.id,
                      child: Text(person.name),
                    ),
                ],
                onChanged: onConsumerChanged,
              ),
            ),
        ],
      ),
    );
  }
}

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
        borderRadius: BorderRadius.circular(Corner.control),
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
        borderRadius: BorderRadius.circular(Corner.chip),
      ),
      child: Row(
        children: [
          Icon(
            sale.unit == FlourUnit.bag
                ? Icons.shopping_bag_rounded
                : Icons.scale_rounded,
            size: IconSize.button,
            color: AppColors.stock,
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
