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
import '../../widgets/role_home_scaffold.dart';
import '../../widgets/work_start_card.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';

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
          label: 'چانه‌گیری',
          title: 'چانه‌گیری',
          icon: Icons.pan_tool_outlined,
          selectedIcon: Icons.pan_tool_rounded,
          builder: (_) => _withData(_shaping),
        ),
      ],
    );
  }

  /// Both pages read the same fetch and pull to refresh the same way, so
  /// switching pages does not re-ask the server.
  Widget _withData(List<Widget> Function(_ChaneData data) children) {
    return RefreshIndicator(
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

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: children(snapshot.data!),
          );
        },
      ),
    );
  }

  // ------------------------------------------------------------ خلاصه

  List<Widget> _overview(_ChaneData data) {
    final user = context.watch<AuthProvider>().user;

    return [
      Text(
        'سلام ${user?.name ?? ''}',
        style: Theme.of(context)
            .textTheme
            .titleLarge
            ?.copyWith(fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 14),
      AttendanceCard(api: widget.api),
      const SizedBox(height: 14),
      WorkStartCard(
        api: widget.api,
        // Baking start is the seller's tick, not the chane gir's — each
        // role sees only the one it records.
        visibleTypes: const {WorkStartType.chane},
      ),
      if (data.board != null) ...[
        const SizedBox(height: 16),
        ChaneComparison(board: data.board!),
      ],
    ];
  }

  // ------------------------------------------------------- چانه‌گیری

  List<Widget> _shaping(_ChaneData data) {
    return [
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
              if (entry.createdAt != null) JalaliFormat.dateTime(entry.createdAt),
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
    ];
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
  final _spray = TextEditingController(text: '0');

  /// One field per tray, in the order they were filled. Chane is counted
  /// into trays on the bench, so this is the real record; the batch total
  /// is only their sum.
  final List<TextEditingController> _trays = [];

  bool _saving = false;

  @override
  void initState() {
    super.initState();

    // Start on the first tray, already filled to the shop's tray size.
    _addTray();
  }

  @override
  void dispose() {
    for (final controller in _trays) {
      controller.dispose();
    }
    _spray.dispose();
    super.dispose();
  }

  int get _trayStep => widget.bakery?.trayStep ?? 1;

  int get _count => _trays.fold(
        0,
        (sum, tray) => sum + (int.tryParse(tray.text.trim()) ?? 0),
      );

  /// Roughly what this dough should yield, so a miscount shows up here
  /// rather than in a report at the end of the month.
  int? get _expectedCount =>
      widget.bakery?.expectedChaneFor(widget.dough.bagCount);

  double get _normalWeight =>
      _count * (widget.bakery?.normalChaneWeightKg ?? 0);

  void _addTray() {
    final controller = TextEditingController(text: '$_trayStep');

    // Every keystroke moves the running total and the expected-yield
    // notice, so both have to redraw as the count is typed.
    controller.addListener(() => setState(() {}));

    setState(() => _trays.add(controller));
  }

  void _removeTray(int index) {
    final controller = _trays.removeAt(index);
    controller.dispose();
    setState(() {});
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) {
      // The form is long enough that a field error can sit off-screen,
      // which reads as the button doing nothing at all.
      showMessage(context, 'یکی از فیلدها را کامل کنید.', isError: true);
      return;
    }

    if (_count < 1) {
      showMessage(context, 'حداقل یک تشتک با تعداد معتبر ثبت کنید.',
          isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final trays = _trays
          .map((c) => int.tryParse(c.text.trim()) ?? 0)
          .where((count) => count > 0)
          .toList();

      final result = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: _count,
        sprayFlourKg: double.tryParse(_spray.text.trim()) ?? 0,
        trays: trays,
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
                    key: ObjectKey(_trays[i]),
                    index: i,
                    controller: _trays[i],
                    // The batch must keep at least one tray to mean anything.
                    canRemove: _trays.length > 1,
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
                // Weight comes from the admin's dough formula and cannot be
                // edited here, so it is shown rather than entered.
                _DerivedWeights(
                  normalWeight: _normalWeight,
                  normalPerChane: widget.bakery?.normalChaneWeightKg,
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _spray,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'وزن آرد پاششی مصرف‌شده',
                    prefixIcon: Icon(Icons.grass_rounded),
                    suffixText: 'کیلوگرم',
                    // Starts at zero so a batch that used none can be filed
                    // without the field blocking the whole form.
                    helperText: 'اگر آرد پاششی مصرف نشده، صفر بماند',
                  ),
                  validator: (value) {
                    final parsed = double.tryParse(value?.trim() ?? '');
                    if (parsed == null) return 'یک عدد معتبر وارد کنید';
                    if (parsed < 0) return 'مقدار نمی‌تواند منفی باشد';
                    return null;
                  },
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
/// One tray, with its count typed rather than stepped. Trays hold dozens of
/// chane and the last one is trimmed to whatever was left, so tapping a
/// plus button thirty times was never the right gesture.
class _TrayRow extends StatelessWidget {
  const _TrayRow({
    super.key,
    required this.index,
    required this.controller,
    required this.canRemove,
    required this.onRemove,
  });

  final int index;
  final TextEditingController controller;
  final bool canRemove;
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
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: TextFormField(
              controller: controller,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              decoration: InputDecoration(
                labelText: _label,
                isDense: true,
                suffixText: 'عدد',
              ),
              validator: (value) {
                final parsed = int.tryParse(value?.trim() ?? '');
                if (parsed == null) return 'عدد وارد کنید';
                if (parsed < 1) return 'بیشتر از صفر';
                return null;
              },
            ),
          ),
          IconButton(
            onPressed: canRemove ? onRemove : null,
            icon: const Icon(Icons.delete_outline_rounded),
            tooltip: 'حذف تشتک',
          ),
        ],
      ),
    );
  }
}

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
/// The batch weight, worked out from the shop's formula rather than typed,
/// so the floor cannot enter a figure that contradicts it.
class _DerivedWeights extends StatelessWidget {
  const _DerivedWeights({
    required this.normalWeight,
    required this.normalPerChane,
  });

  final double normalWeight;
  final double? normalPerChane;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final hasFormula = (normalPerChane ?? 0) > 0;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(Icons.scale_rounded, size: 20, color: scheme.onSurfaceVariant),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              hasFormula
                  ? 'وزن این چانه‌ها'
                  : 'وزن چانه در تنظیمات نانوایی ثبت نشده است',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
          if (hasFormula)
            Text(
              '${normalWeight.toStringAsFixed(2)} کیلوگرم',
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: scheme.primary,
                  ),
            ),
        ],
      ),
    );
  }
}