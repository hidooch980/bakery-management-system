import 'package:flutter/material.dart';

import '../../utils/formatters.dart';
import 'package:provider/provider.dart';

import '../../models/bakery.dart';
import '../../models/entries.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/common.dart';
import '../shared/settings_screen.dart';

/// Home screen for the seller: work the pending chane queue and review the
/// day's own sales.
class SellerHomeScreen extends StatefulWidget {
  const SellerHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerHomeScreen> createState() => _SellerHomeScreenState();
}

class _SellerHomeScreenState extends State<SellerHomeScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this);

  late Future<List<ChaneEntry>> _pending;
  late Future<({List<Sale> sales, int count, double total})> _today;

  /// Bread price configured by the admin; used to suggest a sale amount.
  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _pending = widget.api.pendingChane();
    _today = widget.api.todaySales();
    _loadBakery();
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The price is a convenience — the form still works without it.
    }
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  void _reload() {
    setState(() {
      _pending = widget.api.pendingChane();
      _today = widget.api.todaySales();
    });
  }

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

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

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
        bottom: TabBar(
          controller: _tabs,
          tabs: const [
            Tab(text: 'چانه‌های آماده', icon: Icon(Icons.storefront_rounded)),
            Tab(text: 'فروش امروز', icon: Icon(Icons.receipt_long_rounded)),
          ],
        ),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'سلام ${user?.name ?? ''}',
                    style: Theme.of(context)
                        .textTheme
                        .titleLarge
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 14),
                  AttendanceCard(api: widget.api),
                ],
              ),
            ),
            Expanded(
              child: TabBarView(
                controller: _tabs,
                children: [
                  _PendingChaneTab(
                    future: _pending,
                    onReload: _reload,
                    onSelect: _openSaleSheet,
                  ),
                  _TodaySalesTab(future: _today, onReload: _reload, bakery: _bakery),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PendingChaneTab extends StatelessWidget {
  const _PendingChaneTab({
    required this.future,
    required this.onReload,
    required this.onSelect,
  });

  final Future<List<ChaneEntry>> future;
  final VoidCallback onReload;
  final ValueChanged<ChaneEntry> onSelect;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => onReload(),
      child: FutureBuilder<List<ChaneEntry>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: onReload)],
            );
          }

          final entries = snapshot.data ?? const <ChaneEntry>[];

          if (entries.isEmpty) {
            return ListView(
              children: const [
                SizedBox(height: 40),
                EmptyState(
                  icon: Icons.done_all_rounded,
                  title: 'همه چانه‌ها فروخته شده‌اند',
                  subtitle: 'چانه جدیدی برای فروش موجود نیست.',
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
            itemCount: entries.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final entry = entries[index];

              return ActionCard(
                title: '${entry.chaneCount} چانه',
                subtitle:
                    'وزن: ${entry.weightKg.toStringAsFixed(2)} kg'
                    '${entry.userName != null ? '  •  ${entry.userName}' : ''}',
                icon: Icons.shopping_basket_rounded,
                color: const Color(0xFF3B82C4),
                onTap: () => onSelect(entry),
                trailing: const Icon(Icons.point_of_sale_rounded),
              );
            },
          );
        },
      ),
    );
  }
}

class _TodaySalesTab extends StatelessWidget {
  const _TodaySalesTab({
    required this.future,
    required this.onReload,
    this.bakery,
  });

  final Future<({List<Sale> sales, int count, double total})> future;
  final VoidCallback onReload;
  final Bakery? bakery;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final unit = bakery?.currency ?? Currency.toman;

    return RefreshIndicator(
      onRefresh: () async => onReload(),
      child: FutureBuilder<({List<Sale> sales, int count, double total})>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: onReload)],
            );
          }

          final data = snapshot.data;
          final sales = data?.sales ?? const <Sale>[];

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
            children: [
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'تعداد فروش',
                      value: '${data?.count ?? 0}',
                      icon: Icons.receipt_rounded,
                      color: scheme.primary,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: 'مجموع (${unit.label})',
                      value: MoneyFormat.plain(data?.total ?? 0, currency: unit),
                      icon: Icons.payments_rounded,
                      color: const Color(0xFF2E9E6B),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              if (sales.isEmpty)
                const EmptyState(
                  icon: Icons.receipt_long_outlined,
                  title: 'امروز هنوز فروشی ثبت نشده',
                )
              else
                for (final sale in sales) ...[
                  Card(
                    child: ListTile(
                      contentPadding:
                          const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      leading: CircleAvatar(
                        backgroundColor: scheme.primary.withValues(alpha: 0.14),
                        child: Icon(Icons.sell_rounded,
                            color: scheme.primary, size: 20),
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
                  ),
                  const SizedBox(height: 10),
                ],
            ],
          );
        },
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
  final _amount = TextEditingController();
  final _note = TextEditingController();

  PaymentType? _paymentType;
  bool _saving = false;

  @override
  void initState() {
    super.initState();

    // Pre-fill `chane count × bread price`; the seller can still override it.
    // The field shows the configured unit, so a Rial shop sees Rial.
    final price = widget.bakery?.breadPrice;
    if (price != null && price > 0) {
      final unit = widget.bakery?.currency ?? Currency.toman;
      final suggested = widget.chane.chaneCount * price * unit.multiplier;
      _amount.text = suggested.toStringAsFixed(0);
    }
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  /// Explains where the pre-filled amount came from.
  String? _priceHint() {
    final price = widget.bakery?.breadPrice;
    if (price == null || price <= 0) return null;

    final unit = widget.bakery?.currency ?? Currency.toman;

    return '${widget.chane.chaneCount} نان × ${MoneyFormat.format(price, currency: unit)}';
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    if (_paymentType == null) {
      showMessage(context, 'نوع پرداخت را انتخاب کنید.', isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final unit = widget.bakery?.currency ?? Currency.toman;
      final typed = _amount.text.isEmpty ? null : double.tryParse(_amount.text);

      await widget.api.recordSale(
        chaneEntryId: widget.chane.id,
        paymentType: _paymentType!,
        // The API always stores Toman, whatever unit the shop displays.
        amount: typed == null ? null : MoneyFormat.toToman(typed, currency: unit),
        note: _note.text.trim(),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(context, 'فروش ثبت شد.');
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
                  '(${widget.chane.weightKg.toStringAsFixed(2)} kg)',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
                const SizedBox(height: 22),
                Text(
                  'نوع پرداخت',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: [
                    for (final type in PaymentType.values)
                      ChoiceChip(
                        label: Text(type.label),
                        selected: _paymentType == type,
                        onSelected: (_) => setState(() => _paymentType = type),
                        labelPadding:
                            const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      ),
                  ],
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(
                    labelText: 'مبلغ (اختیاری)',
                    prefixIcon: const Icon(Icons.payments_outlined),
                    suffixText: (widget.bakery?.currency ?? Currency.toman).label,
                    helperText: _priceHint(),
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
