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

/// Home screen for the chane gir: work through the pending dough queue and
/// record the three weights for each batch.
class ChaneHomeScreen extends StatefulWidget {
  const ChaneHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ChaneHomeScreen> createState() => _ChaneHomeScreenState();
}

class _ChaneHomeScreenState extends State<ChaneHomeScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this);

  late Future<List<DoughEntry>> _pending;
  late Future<List<ChaneEntry>> _history;

  /// Reference weights configured by the admin; used to pre-fill the form.
  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _pending = widget.api.pendingDough();
    _history = widget.api.myChaneHistory();
    _loadBakery();
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // Settings are a convenience — the form still works without them.
    }
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  void _reload() {
    setState(() {
      _pending = widget.api.pendingDough();
      _history = widget.api.myChaneHistory();
    });
  }

  Future<void> _openRecordSheet(DoughEntry dough) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _RecordChaneSheet(
        api: widget.api,
        dough: dough,
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
        title: const Text('چانه‌گیری'),
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
            Tab(text: 'خمیرهای در انتظار', icon: Icon(Icons.pending_actions_rounded)),
            Tab(text: 'ثبت‌های من', icon: Icon(Icons.history_rounded)),
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
                  _PendingDoughTab(
                    future: _pending,
                    onReload: _reload,
                    onSelect: _openRecordSheet,
                  ),
                  _ChaneHistoryTab(future: _history, onReload: _reload),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PendingDoughTab extends StatelessWidget {
  const _PendingDoughTab({
    required this.future,
    required this.onReload,
    required this.onSelect,
  });

  final Future<List<DoughEntry>> future;
  final VoidCallback onReload;
  final ValueChanged<DoughEntry> onSelect;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => onReload(),
      child: FutureBuilder<List<DoughEntry>>(
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

          final entries = snapshot.data ?? const <DoughEntry>[];

          if (entries.isEmpty) {
            return ListView(
              children: const [
                SizedBox(height: 40),
                EmptyState(
                  icon: Icons.check_circle_outline_rounded,
                  title: 'همه خمیرها چانه شده‌اند',
                  subtitle: 'خمیر جدیدی در انتظار چانه‌گیری نیست.',
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
                title: '${entry.bagCount} کیسه خمیر',
                subtitle: [
                  if (entry.userName != null) entry.userName!,
                  if (entry.createdAt != null)
                    JalaliFormat.dateTime(entry.createdAt!),
                ].join('  •  '),
                icon: Icons.inventory_2_rounded,
                color: const Color(0xFFE8952D),
                onTap: () => onSelect(entry),
                trailing: const Icon(Icons.add_circle_outline_rounded),
              );
            },
          );
        },
      ),
    );
  }
}

class _ChaneHistoryTab extends StatelessWidget {
  const _ChaneHistoryTab({required this.future, required this.onReload});

  final Future<List<ChaneEntry>> future;
  final VoidCallback onReload;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

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
                  icon: Icons.history_rounded,
                  title: 'هنوز چانه‌ای ثبت نکرده‌اید',
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

              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.grain_rounded, color: scheme.primary),
                          const SizedBox(width: 10),
                          Text(
                            '${entry.chaneCount} چانه',
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                          const Spacer(),
                          Chip(
                            label: Text(
                              entry.isPending ? 'در انتظار فروش' : 'فروخته شده',
                            ),
                            visualDensity: VisualDensity.compact,
                            backgroundColor: (entry.isPending
                                    ? const Color(0xFFE8952D)
                                    : const Color(0xFF2E9E6B))
                                .withValues(alpha: 0.15),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          _WeightPill(
                            label: 'عادی',
                            value: entry.normalWeightKg,
                            color: scheme.primary,
                          ),
                          _WeightPill(
                            label: 'نانینو (نمایشی)',
                            value: entry.naninoWeightKg,
                            color: const Color(0xFF3B82C4),
                          ),
                          _WeightPill(
                            label: 'آرد پاششی',
                            value: entry.sprayFlourKg,
                            color: const Color(0xFFD1495B),
                          ),
                          _WeightPill(
                            label: 'وزن ملاک',
                            value: entry.weightKg,
                            color: const Color(0xFF2E9E6B),
                          ),
                        ],
                      ),
                      if (entry.createdAt != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          JalaliFormat.dateTime(entry.createdAt!),
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: scheme.onSurfaceVariant),
                        ),
                      ],
                    ],
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class _WeightPill extends StatelessWidget {
  const _WeightPill({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        '$label: ${value.toStringAsFixed(2)} kg',
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: color,
              fontWeight: FontWeight.w700,
            ),
      ),
    );
  }
}

class _RecordChaneSheet extends StatefulWidget {
  const _RecordChaneSheet({
    required this.api,
    required this.dough,
    this.bakery,
  });

  final BakeryApi api;
  final DoughEntry dough;
  final Bakery? bakery;

  @override
  State<_RecordChaneSheet> createState() => _RecordChaneSheetState();
}

class _RecordChaneSheetState extends State<_RecordChaneSheet> {
  final _formKey = GlobalKey<FormState>();
  final _count = TextEditingController();
  final _naninoCount = TextEditingController();
  final _spray = TextEditingController();

  bool _saving = false;

  @override
  void initState() {
    super.initState();
    // The weights below are derived, so redraw whenever a count changes.
    _count.addListener(_refreshTotal);
    _naninoCount.addListener(_refreshTotal);
  }

  /// Weight of the normal chane entered so far, from the shop's formula.
  double get _normalWeight {
    final perChane = widget.bakery?.normalChaneWeightKg ?? 0;
    final count = int.tryParse(_count.text) ?? 0;

    return count * perChane;
  }

  double get _naninoWeight {
    final perChane = widget.bakery?.naninoChaneWeightKg ?? 0;
    final count = int.tryParse(_naninoCount.text) ?? 0;

    return count * perChane;
  }

  @override
  void dispose() {
    _count.dispose();
    _naninoCount.dispose();
    _spray.dispose();
    super.dispose();
  }

  void _refreshTotal() => setState(() {});

  double get _total => _normalWeight + _naninoWeight;

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final total = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: int.parse(_count.text),
        naninoChaneCount: int.tryParse(_naninoCount.text) ?? 0,
        sprayFlourKg: double.parse(_spray.text),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(context,
          'ثبت چانه انجام شد. وزن کل: ${total.toStringAsFixed(2)} کیلوگرم');
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  String? _requiredNumber(String? value, {bool allowZero = true}) {
    final parsed = double.tryParse(value ?? '');
    if (parsed == null) return 'یک عدد معتبر وارد کنید';
    if (parsed < 0) return 'مقدار نمی‌تواند منفی باشد';
    if (!allowZero && parsed == 0) return 'مقدار باید بیشتر از صفر باشد';
    return null;
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
                  'ثبت چانه',
                  style: Theme.of(context)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 6),
                Text(
                  'برای خمیر #${widget.dough.id} — ${widget.dough.bagCount} کیسه',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
                const SizedBox(height: 22),
                TextFormField(
                  controller: _count,
                  keyboardType: TextInputType.number,
                  autofocus: true,
                  decoration: const InputDecoration(
                    labelText: 'تعداد چانه',
                    prefixIcon: Icon(Icons.grain_rounded),
                    suffixText: 'عدد',
                  ),
                  validator: (value) {
                    final parsed = int.tryParse(value ?? '');
                    if (parsed == null) return 'یک عدد معتبر وارد کنید';
                    if (parsed < 1) return 'تعداد باید حداقل ۱ باشد';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _naninoCount,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'تعداد چانه نانینو (اختیاری)',
                    prefixIcon: Icon(Icons.precision_manufacturing_rounded),
                    suffixText: 'عدد',
                  ),
                  validator: (value) {
                    if (value == null || value.isEmpty) return null;
                    final parsed = int.tryParse(value);
                    if (parsed == null || parsed < 0) return 'یک عدد معتبر وارد کنید';
                    return null;
                  },
                ),
                const SizedBox(height: 20),

                // Weights come from the admin's dough formula and cannot be
                // edited here, so they are shown rather than entered.
                _DerivedWeights(
                  normalWeight: _normalWeight,
                  naninoWeight: _naninoWeight,
                  normalPerChane: widget.bakery?.normalChaneWeightKg,
                  naninoPerChane: widget.bakery?.naninoChaneWeightKg,
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _spray,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'وزن آرد پاششی مصرف‌شده',
                    prefixIcon: Icon(Icons.grass_rounded),
                    suffixText: 'کیلوگرم',
                  ),
                  validator: _requiredNumber,
                ),
                const SizedBox(height: 20),
                AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: scheme.primary.withValues(alpha: 0.10),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.summarize_rounded, color: scheme.primary),
                      const SizedBox(width: 12),
                      Text(
                        'وزن کل چانه‌های تولیدشده',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const Spacer(),
                      Text(
                        '${_total.toStringAsFixed(2)} kg',
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(
                              fontWeight: FontWeight.w800,
                              color: scheme.primary,
                            ),
                      ),
                    ],
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
                  label: Text(_saving ? 'در حال ثبت…' : 'ثبت چانه'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Read-only weights derived from the admin's dough formula.
///
/// The chane gir enters counts; the weights follow from the recipe, so they
/// are displayed rather than typed and cannot drift from it.
class _DerivedWeights extends StatelessWidget {
  const _DerivedWeights({
    required this.normalWeight,
    required this.naninoWeight,
    this.normalPerChane,
    this.naninoPerChane,
  });

  final double normalWeight;
  final double naninoWeight;
  final double? normalPerChane;
  final double? naninoPerChane;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    if ((normalPerChane ?? 0) <= 0) {
      return Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: scheme.errorContainer.withValues(alpha: 0.4),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(
          children: [
            Icon(Icons.warning_amber_rounded, color: scheme.error),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                'وزن هر چانه در تنظیمات نانوایی تعریف نشده است. '
                'تا تعریف نشود، ثبت چانه ممکن نیست.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.lock_outline_rounded, size: 16, color: scheme.onSurfaceVariant),
              const SizedBox(width: 8),
              Text(
                'وزن‌های محاسبه‌شده از فرمول',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          _WeightRow(
            label: 'وزن چانه عادی',
            weight: normalWeight,
            perChane: normalPerChane,
            color: const Color(0xFFE8952D),
          ),
          if ((naninoPerChane ?? 0) > 0) ...[
            const SizedBox(height: 10),
            _WeightRow(
              label: 'وزن چانه نانینو',
              weight: naninoWeight,
              perChane: naninoPerChane,
              color: const Color(0xFF3B82C4),
            ),
          ],
        ],
      ),
    );
  }
}

class _WeightRow extends StatelessWidget {
  const _WeightRow({
    required this.label,
    required this.weight,
    required this.color,
    this.perChane,
  });

  final String label;
  final double weight;
  final Color color;
  final double? perChane;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: Theme.of(context).textTheme.bodyMedium),
              if (perChane != null)
                Text(
                  'هر چانه ${perChane!.toStringAsFixed(3)} کیلوگرم',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                ),
            ],
          ),
        ),
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 250),
          child: Text(
            '${weight.toStringAsFixed(2)} kg',
            key: ValueKey(weight),
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: color,
                ),
          ),
        ),
      ],
    );
  }
}
