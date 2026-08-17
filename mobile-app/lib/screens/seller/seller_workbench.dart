import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/entries.dart';
import '../../models/flour_sale.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../services/last_used.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../admin/admin_record_sheet.dart';

/// The rest of the shop, on the seller's screen.
///
/// In a shop this size the seller is often the only one on the floor, so a
/// batch should not sit waiting for whoever nominally kneads or shapes it,
/// and flour arriving should not wait for the admin to open the panel.
/// These sections put that work where the person actually standing there
/// can reach it, without turning the seller's page into a second panel.
class SellerWorkbench extends StatefulWidget {
  const SellerWorkbench({
    super.key,
    required this.api,
    required this.onChanged,
    this.bakery,
  });

  final BakeryApi api;
  final Bakery? bakery;

  /// Called after anything is recorded, so the page above reloads.
  final VoidCallback onChanged;

  @override
  State<SellerWorkbench> createState() => _SellerWorkbenchState();
}

class _SellerWorkbenchState extends State<SellerWorkbench> {
  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _ProductionSection(
          api: widget.api,
          bakery: widget.bakery,
          onChanged: widget.onChanged,
        ),
        const SizedBox(height: 22),
        _FlourSection(
          api: widget.api,
          bakery: widget.bakery,
          onChanged: widget.onChanged,
        ),
        const SizedBox(height: 22),
        StaffAttendanceSection(api: widget.api),
      ],
    );
  }
}

// ---------------------------------------------------------------- heading

/// The section heading used across the workbench, matching the rest of the
/// seller's page rather than the admin's card style.
class _Heading extends StatelessWidget {
  const _Heading({required this.title, required this.icon, this.trailing});

  final String title;
  final IconData icon;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
      child: Row(
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
          const Spacer(),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}

/// The bottom-sheet shell the app uses everywhere: grabber, title, scrolling
/// body that lifts clear of the keyboard.
class _SheetShell extends StatelessWidget {
  const _SheetShell({
    required this.title,
    required this.icon,
    required this.color,
    required this.child,
  });

  final String title;
  final IconData icon;
  final Color color;
  final Widget child;

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
              Row(
                children: [
                  Icon(icon, color: color),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      title,
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.w800),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 20),
              child,
            ],
          ),
        ),
      ),
    );
  }
}

// ------------------------------------------------------------- production

/// Kneading and shaping: record a batch, then shape whatever is waiting.
class _ProductionSection extends StatefulWidget {
  const _ProductionSection({
    required this.api,
    required this.onChanged,
    this.bakery,
  });

  final BakeryApi api;

  /// The shop's formula, so a batch can be checked against what it should
  /// yield while it is still being typed.
  final Bakery? bakery;

  final VoidCallback onChanged;

  @override
  State<_ProductionSection> createState() => _ProductionSectionState();
}

class _ProductionSectionState extends State<_ProductionSection> {
  late Future<List<DoughEntry>> _pending;

  @override
  void initState() {
    super.initState();
    _pending = _load();
  }

  Future<List<DoughEntry>> _load() async {
    try {
      return await widget.api.pendingDough();
    } on ApiException {
      // Without the permission the queue simply does not show.
      return const [];
    }
  }

  void _reload() {
    setState(() => _pending = _load());
    widget.onChanged();
  }

  Future<void> _recordDough() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _DoughSheet(api: widget.api, bakery: widget.bakery),
    );

    if (saved == true) _reload();
  }

  Future<void> _shape(DoughEntry dough) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ChaneSheet(
        api: widget.api,
        dough: dough,
        bakery: widget.bakery,
      ),
    );

    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Heading(title: 'کارگاه', icon: Icons.bakery_dining_rounded),
        FilledButton.tonalIcon(
          onPressed: _recordDough,
          icon: const Icon(Icons.add_rounded),
          label: const Text('ثبت خمیر'),
          style: FilledButton.styleFrom(
            minimumSize: const Size.fromHeight(52),
          ),
        ),
        const SizedBox(height: 12),
        FutureBuilder<List<DoughEntry>>(
          future: _pending,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 20),
                child: Center(child: CircularProgressIndicator()),
              );
            }

            final pending = snapshot.data ?? const <DoughEntry>[];

            if (pending.isEmpty) {
              return Card(
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 18, vertical: 16),
                  child: Row(
                    children: [
                      Icon(
                        Icons.check_circle_outline_rounded,
                        size: IconSize.button,
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'خمیری در انتظار چانه‌گیری نیست.',
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            }

            return Column(
              children: [
                for (final dough in pending)
                  Card(
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 8),
                      leading: CircleAvatar(
                        backgroundColor: AppColors.stock
                            .withValues(alpha: 0.16),
                        child: const Icon(
                          Icons.water_drop_rounded,
                          color: AppColors.stock,
                        ),
                      ),
                      title: Text(
                        '${dough.bagCount} کیسه خمیر',
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                      subtitle: Text(JalaliFormat.time(dough.createdAt)),
                      trailing: FilledButton(
                        onPressed: () => _shape(dough),
                        child: const Text('ثبت چانه'),
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}

/// Recording a batch of dough: the bag count, and nothing else.
class _DoughSheet extends StatefulWidget {
  const _DoughSheet({required this.api, this.bakery});

  final BakeryApi api;
  final Bakery? bakery;

  @override
  State<_DoughSheet> createState() => _DoughSheetState();
}

class _DoughSheetState extends State<_DoughSheet> {
  final _note = TextEditingController();

  /// Held as a number, not text. The count is stepped rather than typed:
  /// this shop has put in ten bags on nearly every batch of the last
  /// month, and the few that differed differed by one or two.
  int _bags = 10;

  bool _ready = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final bags = await LastUsed.doughBags();

    if (!mounted) return;
    setState(() {
      _bags = bags;
      _ready = true;
    });
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _save({bool force = false}) async {
    setState(() => _saving = true);

    try {
      final queued = await widget.api.recordDough(
        bagCount: _bags,
        note: _note.text,
        force: force,
      );

      await LastUsed.rememberDoughBags(_bags);

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        queued ? 'بدون اینترنت ذخیره شد و بعداً ارسال می‌شود.' : 'خمیر ثبت شد.',
      );
    } on ApiException catch (e) {
      if (!mounted) return;

      setState(() => _saving = false);

      // The server refused it as a repeat of a batch just recorded. On 24
      // Mordad the same thirteen bags went in three times because the
      // first two did not look like they had landed, and each one took
      // flour out of the store. Ask, rather than refuse outright: a second
      // batch of the same size is unusual, not impossible.
      if (e.statusCode == 409) {
        await _confirmRepeat(e.message);

        return;
      }

      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _confirmRepeat(String message) async {
    final again = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('این خمیر را همین الان ثبت کردید'),
        content: Text('$message\n\nاگر دستهٔ تازه‌ای است، تأیید کنید.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('نه، اشتباه شد'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('بله، دستهٔ تازه است'),
          ),
        ],
      ),
    );

    if (again == true) await _save(force: true);
  }

  @override
  Widget build(BuildContext context) {
    final expected = widget.bakery?.expectedChaneFor(_bags);

    return _SheetShell(
      title: 'ثبت خمیر',
      icon: Icons.water_drop_rounded,
      color: AppColors.stock,
      child: !_ready
          ? const Padding(
              padding: EdgeInsets.symmetric(vertical: 40),
              child: Center(child: CircularProgressIndicator()),
            )
          : Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _Stepper(
                  label: 'تعداد کیسه',
                  value: _bags,
                  onChanged: (value) => setState(() => _bags = value),
                ),

                // What that many bags should come to. A mistyped count is
                // otherwise invisible until the chane are counted hours
                // later and the yield looks wrong for reasons nobody can
                // reconstruct.
                if (expected != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    'حدود $expected چانه از این خمیر درمی‌آید.',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                  ),
                ],

                const SizedBox(height: 16),
                TextField(
                  controller: _note,
                  decoration: const InputDecoration(
                    labelText: 'توضیح (اختیاری)',
                    prefixIcon: Icon(Icons.notes_rounded),
                  ),
                ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: _saving ? null : _save,
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(56),
                  ),
                  child: _saving
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text('ثبت $_bags کیسه'),
                ),
              ],
            ),
    );
  }
}

/// Shaping a batch. Only counts are entered — the weights come back from
/// the server's formula, so what is recorded can never contradict it.
class _ChaneSheet extends StatefulWidget {
  const _ChaneSheet({required this.api, required this.dough, this.bakery});

  final BakeryApi api;
  final DoughEntry dough;
  final Bakery? bakery;

  @override
  State<_ChaneSheet> createState() => _ChaneSheetState();
}

class _ChaneSheetState extends State<_ChaneSheet> {
  final _formKey = GlobalKey<FormState>();
  final _count = TextEditingController();
  final _nanino = TextEditingController(text: '0');
  final _spray = TextEditingController(text: '5');

  /// Nanino and spray flour are folded away. This shop has shaped no
  /// nanino at all and put on five kilos of spray flour every single time,
  /// so two of the three boxes were asking a question already answered —
  /// and a form of three identical-looking number fields is where the
  /// wrong one gets filled in.
  bool _showMore = false;

  bool _ready = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final spray = await LastUsed.sprayFlourKg();
    final nanino = await LastUsed.naninoCount();

    if (!mounted) return;
    setState(() {
      _spray.text = spray.toStringAsFixed(spray % 1 == 0 ? 0 : 1);
      _nanino.text = nanino.toString();
      _ready = true;
    });
  }

  @override
  void dispose() {
    _count.dispose();
    _nanino.dispose();
    _spray.dispose();
    super.dispose();
  }

  int? get _expected => widget.bakery?.expectedChaneFor(widget.dough.bagCount);

  /// Far enough off the formula to be worth a second look before saving.
  /// A fifth either way is wide — a real day varies by a tenth — so this
  /// catches a digit dropped or added, not an ordinary good or bad batch.
  bool get _looksWrong {
    final expected = _expected;
    final typed = int.tryParse(_count.text.trim());

    if (expected == null || typed == null || typed <= 0) return false;

    return typed < expected * 0.8 || typed > expected * 1.2;
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final nanino = int.tryParse(_nanino.text.trim()) ?? 0;
      final spray = double.tryParse(_spray.text.trim()) ?? 0;

      final result = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: int.parse(_count.text.trim()),
        naninoChaneCount: nanino,
        sprayFlourKg: spray,
      );

      await LastUsed.rememberSprayFlourKg(spray);
      await LastUsed.rememberNaninoCount(nanino);

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        result.queued
            ? 'بدون اینترنت ذخیره شد و بعداً ارسال می‌شود.'
            : 'چانه ثبت شد — ${result.weightKg?.toStringAsFixed(2) ?? '—'} کیلوگرم',
      );
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _saving = false);
        showMessage(context, e.message, isError: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final expected = _expected;

    return _SheetShell(
      title: 'ثبت چانه — ${widget.dough.bagCount} کیسه',
      icon: Icons.blur_circular_rounded,
      color: AppColors.moneyNeutral,
      child: !_ready
          ? const Padding(
              padding: EdgeInsets.symmetric(vertical: 40),
              child: Center(child: CircularProgressIndicator()),
            )
          : Form(
              key: _formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextFormField(
                    controller: _count,
                    keyboardType: TextInputType.number,
                    autofocus: true,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.headlineSmall
                        ?.copyWith(fontWeight: FontWeight.w800),
                    decoration: InputDecoration(
                      labelText: 'تعداد چانه',
                      // The formula's own answer, offered rather than
                      // imposed: the oven, not the arithmetic, decides how
                      // many actually came out.
                      hintText: expected?.toString(),
                      helperText: expected == null
                          ? null
                          : 'از ${widget.dough.bagCount} کیسه معمولاً حدود $expected چانه',
                    ),
                    onChanged: (_) => setState(() {}),
                    validator: (value) {
                      final count = int.tryParse((value ?? '').trim());

                      return count == null || count <= 0
                          ? 'تعداد چانه را وارد کنید.'
                          : null;
                    },
                  ),

                  if (_looksWrong) ...[
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        const Icon(Icons.error_outline_rounded,
                            size: IconSize.row, color: AppColors.attention),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'این عدد از حالت عادی این نانوایی خیلی دور است.'
                            ' اگر درست است ثبتش کنید.',
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: AppColors.attention,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],

                  const SizedBox(height: 8),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: TextButton.icon(
                      onPressed: () => setState(() => _showMore = !_showMore),
                      icon: Icon(
                        _showMore
                            ? Icons.expand_less_rounded
                            : Icons.expand_more_rounded,
                        size: IconSize.button,
                      ),
                      label: Text(_showMore ? 'بستن' : 'نانینو و آرد پاششی'),
                    ),
                  ),

                  if (_showMore) ...[
                    const SizedBox(height: 4),
                    TextFormField(
                      controller: _nanino,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'تعداد چانه نانینو',
                        prefixIcon: Icon(Icons.bakery_dining_rounded),
                      ),
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _spray,
                      keyboardType:
                          const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(
                        labelText: 'آرد پاششی (کیلوگرم)',
                        prefixIcon: Icon(Icons.scatter_plot_rounded),
                      ),
                    ),
                  ],

                  const SizedBox(height: 8),
                  Text(
                    'وزن‌ها از فرمول نانوایی حساب می‌شوند.',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 18),
                  FilledButton(
                    onPressed: _saving ? null : _save,
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(56),
                    ),
                    child: _saving
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('ثبت'),
                  ),
                ],
              ),
            ),
    );
  }
}

/// A count that is nearly always the same, changed by tapping rather than
/// typing. Ten bags on almost every batch of the last month; the keypad
/// was six taps and a chance to mistype for a number that rarely moves.
class _Stepper extends StatelessWidget {
  const _Stepper({
    required this.label,
    required this.value,
    required this.onChanged,
  });

  final String label;
  final int value;
  final ValueChanged<int> onChanged;

  /// Fixed rather than passed in. One bag is the smallest batch worth
  /// recording and ninety-nine is far past anything this oven holds, so
  /// they were parameters no caller ever had a reason to set.
  static const _min = 1;

  static const _max = 99;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      children: [
        Text(label, style: theme.textTheme.bodyMedium),
        const SizedBox(height: 10),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            _Round(
              icon: Icons.remove_rounded,
              onTap: value > _min ? () => onChanged(value - 1) : null,
            ),
            SizedBox(
              width: 96,
              child: Text(
                value.toString(),
                textAlign: TextAlign.center,
                style: theme.textTheme.displaySmall?.copyWith(
                  fontWeight: FontWeight.w900,
                  color: AppColors.stock,
                ),
              ),
            ),
            _Round(
              icon: Icons.add_rounded,
              onTap: value < _max ? () => onChanged(value + 1) : null,
            ),
          ],
        ),
      ],
    );
  }
}

class _Round extends StatelessWidget {
  const _Round({required this.icon, this.onTap});

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    // Deliberately large: this is tapped with a floury thumb, standing up.
    return SizedBox(
      height: 56,
      width: 56,
      child: Material(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Icon(
            icon,
            size: IconSize.heading,
            color: onTap == null
                ? Theme.of(context).disabledColor
                : Theme.of(context).colorScheme.onSurface,
          ),
        ),
      ),
    );
  }
}

// ------------------------------------------------------------------ flour

/// Flour arriving, and flour swapped with a neighbouring bakery. Both reuse
/// the sheet the admin already uses, so the two screens cannot drift apart.
/// The warehouse, with what is in it on the heading.
///
/// The seller could already see the flour balance — inside the sheet for
/// selling flour, which meant deciding to sell some before finding out
/// whether there was any. It is the figure that answers «can we bake
/// tomorrow», so it belongs where it is read without asking.
///
/// Sacks lead and the weight follows: sacks are what arrive at the door,
/// what the quota is counted in, and what the shop says out loud.
class _FlourSection extends StatefulWidget {
  const _FlourSection({
    required this.api,
    required this.onChanged,
    this.bakery,
  });

  final BakeryApi api;
  final Bakery? bakery;
  final VoidCallback onChanged;

  @override
  State<_FlourSection> createState() => _FlourSectionState();
}

class _FlourSectionState extends State<_FlourSection> {
  FlourSaleOptions? _stock;

  @override
  void initState() {
    super.initState();
    _loadStock();
  }

  Future<void> _loadStock() async {
    try {
      final stock = await widget.api.flourSaleOptions();
      if (mounted) setState(() => _stock = stock);
    } on ApiException {
      // The two buttons below still work; the heading simply says nothing
      // rather than showing a figure it could not confirm.
    }
  }

  Future<void> _open(BuildContext context, AdminRecordKind kind) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdminRecordSheet(
        api: widget.api,
        kind: kind,
        bakery: widget.bakery,
      ),
    );

    if (saved == true) {
      widget.onChanged();
      await _loadStock();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Heading(
          title: 'انبار',
          icon: Icons.grain_rounded,
          trailing: _stock == null ? null : _FlourOnHand(stock: _stock!),
        ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _open(context, AdminRecordKind.intake),
                icon: const Icon(Icons.local_shipping_rounded, size: IconSize.row),
                label: const Text('ورودی انبار'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _open(context, AdminRecordKind.consignment),
                icon: const Icon(Icons.swap_horiz_rounded, size: IconSize.row),
                label: const Text('آرد همکار'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// «۱۹۳ کیسه · ۷٬۷۲۰ کگ» — what is in the store, at a glance.
///
/// Coloured only when it is low. A figure that is always tinted is a
/// figure nobody reads a warning into; this one stays plain until there
/// really is something to say, and the threshold is the shop's own, set on
/// the warehouse item rather than guessed at here.
class _FlourOnHand extends StatelessWidget {
  const _FlourOnHand({required this.stock});

  /// Ten sacks: about a week of baking at this shop's rate, which is time
  /// enough to order more before the oven stops.
  static const _lowBags = 10;

  final FlourSaleOptions stock;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final low = stock.availableBags < _lowBags;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (low)
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 4),
            child: Icon(
              Icons.error_outline_rounded,
              size: IconSize.inline,
              color: AppColors.attention,
            ),
          ),
        Text(
          '${stock.availableBags.toStringAsFixed(stock.availableBags % 1 == 0 ? 0 : 1)} کیسه',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
            color: low ? AppColors.attention : null,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
        Text(
          '  ·  ${stock.availableKg.toStringAsFixed(0)} کگ',
          style: theme.textTheme.bodySmall?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

// ------------------------------------------------------------- attendance

/// Who is in today, for the whole floor rather than just the person looking.
class StaffAttendanceSection extends StatefulWidget {
  const StaffAttendanceSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<StaffAttendanceSection> createState() => _StaffAttendanceSectionState();
}

class _StaffAttendanceSectionState extends State<StaffAttendanceSection> {
  late Future<List<Map<String, dynamic>>> _records;

  @override
  void initState() {
    super.initState();
    _records = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    try {
      return await widget.api.adminAttendanceToday();
    } on ApiException {
      return const [];
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _records,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const SizedBox.shrink();
        }

        final records = snapshot.data ?? const <Map<String, dynamic>>[];

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _Heading(
              title: 'حاضری امروز',
              icon: Icons.how_to_reg_rounded,
              trailing: Text(
                '${records.length} نفر',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
            if (records.isEmpty)
              Card(
                child: Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
                  child: Text(
                    'هنوز کسی تیک حضور نزده.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              )
            else
              Card(
                child: Column(
                  children: [
                    for (var i = 0; i < records.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      _AttendanceRow(record: records[i]),
                    ],
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}

class _AttendanceRow extends StatelessWidget {
  const _AttendanceRow({required this.record});

  final Map<String, dynamic> record;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final user = record['user'] as Map<String, dynamic>?;
    final checkedInAt = DateTime.tryParse('${record['checked_in_at']}');

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
      child: Row(
        children: [
          Icon(Icons.person_rounded, size: IconSize.button, color: scheme.onSurfaceVariant),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              '${user?['name'] ?? '—'}',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          Text(
            JalaliFormat.time(checkedInAt),
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.moneyIn,
                ),
          ),
        ],
      ),
    );
  }
}
