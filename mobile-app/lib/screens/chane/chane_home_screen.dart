import 'package:flutter/material.dart';

import '../../utils/formatters.dart';
import 'package:provider/provider.dart';

import '../../models/bakery.dart';
import '../../models/chane_board.dart';
import '../../models/entries.dart';
import '../../models/work_start.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/sync_status_card.dart';
import '../../widgets/work_start_card.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import '../shared/settings_screen.dart';

/// Home screen for the chane gir. One scrolling page: the dough waiting to be
/// shaped, today's production split, and the entries already recorded.
class ChaneHomeScreen extends StatefulWidget {
  const ChaneHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ChaneHomeScreen> createState() => _ChaneHomeScreenState();
}

typedef _ChaneData = ({
  List<DoughEntry> pending,
  List<ChaneEntry> history,
  ChaneBoard? board,
});

class _ChaneHomeScreenState extends State<ChaneHomeScreen> {
  late Future<_ChaneData> _data;

  /// Chane weights the form derives its read-only figures from.
  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _data = _load();
    _loadBakery();
  }

  Future<_ChaneData> _load() async {
    final pending = await widget.api.pendingDough();
    final history = await widget.api.myChaneHistory();

    ChaneBoard? board;
    try {
      board = await widget.api.chaneBoard();
    } on ApiException {
      board = null;
    }

    return (pending: pending, history: history, board: board);
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // Without settings the form warns and blocks submission.
    }
  }

  void _reload() => setState(() => _data = _load());

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
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => _reload(),
          child: FutureBuilder<_ChaneData>(
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
                    // Baking start is the seller's tick, not the chane
                    // gir's — each role sees only the one it records.
                    visibleTypes: const {WorkStartType.chane},
                  ),

                  if (data.board != null) ...[
                    const SizedBox(height: 16),
                    ChaneComparison(board: data.board!),
                  ],

                  const SizedBox(height: 22),
                  _SectionHeader(
                    title: 'خمیرهای در انتظار',
                    count: data.pending.length,
                    icon: Icons.pending_actions_rounded,
                  ),
                  const SizedBox(height: 10),
                  if (data.pending.isEmpty)
                    const _InlineEmpty(
                      icon: Icons.check_circle_outline_rounded,
                      text: 'همه خمیرها چانه شده‌اند.',
                    )
                  else
                    for (final entry in data.pending) ...[
                      ActionCard(
                        title: '${entry.bagCount} کیسه خمیر',
                        subtitle: [
                          if (entry.userName != null) entry.userName!,
                          if (entry.createdAt != null)
                            JalaliFormat.dateTime(entry.createdAt),
                        ].join('  •  '),
                        icon: Icons.inventory_2_rounded,
                        color: const Color(0xFFE8952D),
                        onTap: () => _openRecordSheet(entry),
                        trailing: const Icon(Icons.add_circle_outline_rounded),
                      ),
                      const SizedBox(height: 10),
                    ],

                  const SizedBox(height: 16),
                  _SectionHeader(
                    title: 'ثبت‌های من',
                    count: data.history.length,
                    icon: Icons.history_rounded,
                  ),
                  const SizedBox(height: 10),
                  if (data.history.isEmpty)
                    const _InlineEmpty(
                      icon: Icons.history_rounded,
                      text: 'هنوز چانه‌ای ثبت نکرده‌اید.',
                    )
                  else
                    for (final entry in data.history) ...[
                      _ChaneTile(entry: entry),
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

/// One recorded chane batch, with its authoritative weight and the nanino
/// figure shown separately.
class _ChaneTile extends StatelessWidget {
  const _ChaneTile({required this.entry});

  final ChaneEntry entry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.grain_rounded, color: scheme.primary, size: 20),
                const SizedBox(width: 8),
                Text(
                  '${entry.chaneCount} چانه',
                  style: Theme.of(context)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const Spacer(),
                Chip(
                  label: Text(entry.isPending ? 'در انتظار فروش' : 'فروخته شده'),
                  visualDensity: VisualDensity.compact,
                  backgroundColor: (entry.isPending
                          ? const Color(0xFFE8952D)
                          : const Color(0xFF2E9E6B))
                      .withValues(alpha: 0.15),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _WeightPill(
                  label: 'وزن ملاک',
                  value: entry.weightKg,
                  color: const Color(0xFF2E9E6B),
                ),
                if (entry.naninoWeightKg > 0)
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
              ],
            ),
            if (entry.createdAt != null) ...[
              const SizedBox(height: 10),
              Text(
                JalaliFormat.dateTime(entry.createdAt),
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
        '$label: ${value.toStringAsFixed(2)} کیلوگرم',
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
  final _naninoCount = TextEditingController();
  final _spray = TextEditingController();

  /// Chane counted into each tray, in the order they were filled. The shop
  /// counts a tray at a time, so this is the real record; the batch total
  /// is just their sum.
  final List<int> _trays = [];

  bool _saving = false;

  @override
  void initState() {
    super.initState();

    // Start on the first tray, already filled to the shop's tray size.
    _trays.add(_trayStep);
    _naninoCount.addListener(_refreshTotal);
  }

  @override
  void dispose() {
    _naninoCount.dispose();
    _spray.dispose();
    super.dispose();
  }

  void _refreshTotal() => setState(() {});

  int get _trayStep => widget.bakery?.trayStep ?? 1;

  int get _count => _trays.fold(0, (sum, tray) => sum + tray);

  /// Roughly what this dough should yield, so a miscount shows up here
  /// rather than in a report at the end of the month.
  int? get _expectedCount =>
      widget.bakery?.expectedChaneFor(widget.dough.bagCount);

  double get _normalWeight =>
      _count * (widget.bakery?.normalChaneWeightKg ?? 0);

  double get _naninoWeight {
    final perChane = widget.bakery?.naninoChaneWeightKg ?? 0;

    return (int.tryParse(_naninoCount.text) ?? 0) * perChane;
  }

  double get _total => _normalWeight + _naninoWeight;

  void _addTray() => setState(() => _trays.add(_trayStep));

  void _removeTray(int index) => setState(() => _trays.removeAt(index));

  void _setTray(int index, int value) {
    setState(() => _trays[index] = value.clamp(1, 10000));
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    if (_count < 1) {
      showMessage(context, 'حداقل یک تشتک با تعداد معتبر ثبت کنید.', isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final result = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: _count,
        naninoChaneCount: int.tryParse(_naninoCount.text) ?? 0,
        sprayFlourKg: double.parse(_spray.text),
        trays: List<int>.from(_trays),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        result.queued
            ? 'اینترنت وصل نیست؛ ثبت چانه ذخیره شد و با اتصال بعدی ارسال می‌شود.'
            : 'ثبت چانه انجام شد. وزن کل: '
                '${result.weightKg!.toStringAsFixed(2)} کیلوگرم',
      );
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
    final expected = _expectedCount;

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

                if (expected != null) ...[
                  const SizedBox(height: 16),
                  _ExpectedBanner(expected: expected, actual: _count),
                ],

                const SizedBox(height: 18),
                Row(
                  children: [
                    Text(
                      'تشتک‌ها',
                      style: Theme.of(context)
                          .textTheme
                          .titleSmall
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    const Spacer(),
                    if (_trayStep > 1)
                      Text(
                        'هر تشتک $_trayStep عدد',
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(color: scheme.onSurfaceVariant),
                      ),
                  ],
                ),
                const SizedBox(height: 10),

                for (var i = 0; i < _trays.length; i++)
                  _TrayRow(
                    index: i,
                    count: _trays[i],
                    // The batch must keep at least one tray to mean anything.
                    canRemove: _trays.length > 1,
                    onChanged: (value) => _setTray(i, value),
                    onRemove: () => _removeTray(i),
                  ),

                const SizedBox(height: 4),
                OutlinedButton.icon(
                  onPressed: _addTray,
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('افزودن تشتک'),
                ),

                const SizedBox(height: 14),
                _TrayTotal(trayCount: _trays.length, chaneCount: _count),

                const SizedBox(height: 20),
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
                        '${_total.toStringAsFixed(2)} کیلوگرم',
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

/// Roughly what this dough should yield, so the chane gir sees a miscount
/// while the trays are still in front of them.
class _ExpectedBanner extends StatelessWidget {
  const _ExpectedBanner({required this.expected, required this.actual});

  final int expected;
  final int actual;

  @override
  Widget build(BuildContext context) {
    // Counting is never exact, so only a real gap is worth flagging.
    final short = expected - actual;
    final isShort = short > expected * 0.05;

    final color = isShort ? const Color(0xFFD1495B) : const Color(0xFFE8952D);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(
            isShort ? Icons.warning_amber_rounded : Icons.info_outline_rounded,
            size: 18,
            color: color,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              isShort
                  ? 'انتظار حدود $expected چانه — فعلاً $short عدد کمتر ثبت شده.'
                  : 'انتظار از این خمیر: حدود $expected چانه',
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

/// One tray with a stepper either side of its count.
class _TrayRow extends StatelessWidget {
  const _TrayRow({
    required this.index,
    required this.count,
    required this.canRemove,
    required this.onChanged,
    required this.onRemove,
  });

  final int index;
  final int count;
  final bool canRemove;
  final ValueChanged<int> onChanged;
  final VoidCallback onRemove;

  static const _ordinals = [
    'اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم', 'هفتم', 'هشتم',
    'نهم', 'دهم', 'یازدهم', 'دوازدهم',
  ];

  String get _label => index < _ordinals.length
      ? 'تشتک ${_ordinals[index]}'
      : 'تشتک ${index + 1}';

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              _label,
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(fontWeight: FontWeight.w600),
            ),
          ),
          IconButton(
            onPressed: count > 1 ? () => onChanged(count - 1) : null,
            icon: const Icon(Icons.remove_circle_outline_rounded),
            visualDensity: VisualDensity.compact,
            tooltip: 'یکی کمتر',
          ),
          SizedBox(
            width: 46,
            child: Text(
              '$count',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: scheme.primary,
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
            onPressed: canRemove ? onRemove : null,
            icon: const Icon(Icons.delete_outline_rounded),
            visualDensity: VisualDensity.compact,
            tooltip: 'حذف تشتک',
          ),
        ],
      ),
    );
  }
}

/// The running total, which is what actually gets recorded.
class _TrayTotal extends StatelessWidget {
  const _TrayTotal({required this.trayCount, required this.chaneCount});

  final int trayCount;
  final int chaneCount;

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
          Icon(Icons.layers_rounded, color: scheme.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '$trayCount تشتک',
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
          ),
          Text(
            '$chaneCount چانه',
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
            '${weight.toStringAsFixed(2)} کیلوگرم',
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
