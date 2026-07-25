import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
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
                    DateFormat('MM/dd — HH:mm').format(entry.createdAt!),
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
                            label: 'نانینو',
                            value: entry.naninoWeightKg,
                            color: const Color(0xFF3B82C4),
                          ),
                          _WeightPill(
                            label: 'آرد پاششی',
                            value: entry.sprayFlourKg,
                            color: const Color(0xFFD1495B),
                          ),
                          _WeightPill(
                            label: 'وزن کل',
                            value: entry.totalWeightKg,
                            color: const Color(0xFF2E9E6B),
                          ),
                        ],
                      ),
                      if (entry.createdAt != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          DateFormat('yyyy/MM/dd — HH:mm').format(entry.createdAt!),
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
  final _normal = TextEditingController();
  final _nanino = TextEditingController();
  final _spray = TextEditingController();

  bool _saving = false;

  /// Once the user edits a weight by hand, stop overwriting it from the count.
  bool _weightsTouched = false;

  @override
  void initState() {
    super.initState();
    // Live total as the two chane weights are typed.
    _normal.addListener(_refreshTotal);
    _nanino.addListener(_refreshTotal);
    _count.addListener(_suggestWeights);
  }

  /// Fills the two weights from `count × per-chane weight` configured by the
  /// admin, so the common case needs one number instead of three.
  void _suggestWeights() {
    if (_weightsTouched) return;

    final bakery = widget.bakery;
    if (bakery == null || !bakery.hasChaneWeights) return;

    final count = int.tryParse(_count.text);
    if (count == null || count <= 0) return;

    final normal = bakery.normalChaneWeightKg;
    final nanino = bakery.naninoChaneWeightKg;

    if (normal != null && normal > 0) {
      _normal.text = (count * normal).toStringAsFixed(2);
    }
    if (nanino != null && nanino > 0) {
      _nanino.text = (count * nanino).toStringAsFixed(2);
    }
  }

  @override
  void dispose() {
    _count.dispose();
    _normal.dispose();
    _nanino.dispose();
    _spray.dispose();
    super.dispose();
  }

  void _refreshTotal() => setState(() {});

  double get _total =>
      (double.tryParse(_normal.text) ?? 0) + (double.tryParse(_nanino.text) ?? 0);

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final total = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: int.parse(_count.text),
        normalWeightKg: double.parse(_normal.text),
        naninoWeightKg: double.parse(_nanino.text),
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

  /// Shows the configured per-chane weight under the field, so the user can
  /// see where the pre-filled number came from.
  String? _perChaneHint(double? perChane) {
    if (perChane == null || perChane <= 0) return null;

    return 'محاسبه‌شده از ${perChane.toStringAsFixed(3)} کیلوگرم برای هر چانه';
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
                  controller: _normal,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  onChanged: (_) => _weightsTouched = true,
                  decoration: InputDecoration(
                    labelText: 'وزن چانه عادی',
                    prefixIcon: const Icon(Icons.scale_rounded),
                    suffixText: 'کیلوگرم',
                    helperText: _perChaneHint(widget.bakery?.normalChaneWeightKg),
                  ),
                  validator: _requiredNumber,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _nanino,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  onChanged: (_) => _weightsTouched = true,
                  decoration: InputDecoration(
                    labelText: 'وزن چانه سیستم نانینو',
                    prefixIcon: const Icon(Icons.precision_manufacturing_rounded),
                    suffixText: 'کیلوگرم',
                    helperText: _perChaneHint(widget.bakery?.naninoChaneWeightKg),
                  ),
                  validator: _requiredNumber,
                ),
                const SizedBox(height: 16),
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
