import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/entries.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
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
          Icon(icon, size: 18, color: scheme.primary),
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
  const _ProductionSection({required this.api, required this.onChanged});

  final BakeryApi api;
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
      builder: (_) => _DoughSheet(api: widget.api),
    );

    if (saved == true) _reload();
  }

  Future<void> _shape(DoughEntry dough) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ChaneSheet(api: widget.api, dough: dough),
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
                        size: 20,
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
                        backgroundColor: AppColors.emberHot
                            .withValues(alpha: 0.16),
                        child: const Icon(
                          Icons.inventory_2_rounded,
                          color: AppColors.emberHot,
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
  const _DoughSheet({required this.api});

  final BakeryApi api;

  @override
  State<_DoughSheet> createState() => _DoughSheetState();
}

class _DoughSheetState extends State<_DoughSheet> {
  final _formKey = GlobalKey<FormState>();
  final _bags = TextEditingController();
  final _note = TextEditingController();
  bool _saving = false;

  @override
  void dispose() {
    _bags.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final queued = await widget.api.recordDough(
        bagCount: int.parse(_bags.text.trim()),
        note: _note.text,
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        queued ? 'بدون اینترنت ذخیره شد و بعداً ارسال می‌شود.' : 'خمیر ثبت شد.',
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
    return _SheetShell(
      title: 'ثبت خمیر',
      icon: Icons.inventory_2_rounded,
      color: AppColors.emberHot,
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextFormField(
              controller: _bags,
              keyboardType: TextInputType.number,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'تعداد کیسه',
                prefixIcon: Icon(Icons.inventory_2_outlined),
              ),
              validator: (value) {
                final bags = int.tryParse((value ?? '').trim());

                return bags == null || bags <= 0
                    ? 'تعداد کیسه را وارد کنید.'
                    : null;
              },
            ),
            const SizedBox(height: 14),
            TextFormField(
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
                minimumSize: const Size.fromHeight(52),
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

/// Shaping a batch. Only counts are entered — the weights come back from
/// the server's formula, so what is recorded can never contradict it.
class _ChaneSheet extends StatefulWidget {
  const _ChaneSheet({required this.api, required this.dough});

  final BakeryApi api;
  final DoughEntry dough;

  @override
  State<_ChaneSheet> createState() => _ChaneSheetState();
}

class _ChaneSheetState extends State<_ChaneSheet> {
  final _formKey = GlobalKey<FormState>();
  final _count = TextEditingController();
  final _nanino = TextEditingController(text: '0');
  final _spray = TextEditingController(text: '0');
  bool _saving = false;

  @override
  void dispose() {
    _count.dispose();
    _nanino.dispose();
    _spray.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final result = await widget.api.recordChane(
        doughEntryId: widget.dough.id,
        chaneCount: int.parse(_count.text.trim()),
        naninoChaneCount: int.tryParse(_nanino.text.trim()) ?? 0,
        sprayFlourKg: double.tryParse(_spray.text.trim()) ?? 0,
      );

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
    return _SheetShell(
      title: 'ثبت چانه — ${widget.dough.bagCount} کیسه',
      icon: Icons.grain_rounded,
      color: const Color(0xFF3B82C4),
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextFormField(
              controller: _count,
              keyboardType: TextInputType.number,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'تعداد چانه عادی',
                prefixIcon: Icon(Icons.grain_rounded),
              ),
              validator: (value) {
                final count = int.tryParse((value ?? '').trim());

                return count == null || count <= 0
                    ? 'تعداد چانه را وارد کنید.'
                    : null;
              },
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _nanino,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                labelText: 'تعداد چانه نانینو',
                prefixIcon: Icon(Icons.bakery_dining_outlined),
              ),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _spray,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'آرد پاششی (کیلوگرم)',
                prefixIcon: Icon(Icons.scatter_plot_rounded),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'وزن‌ها از فرمول نانوایی محاسبه می‌شوند.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
            ),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _saving ? null : _save,
              style: FilledButton.styleFrom(
                minimumSize: const Size.fromHeight(52),
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

// ------------------------------------------------------------------ flour

/// Flour arriving, and flour swapped with a neighbouring bakery. Both reuse
/// the sheet the admin already uses, so the two screens cannot drift apart.
class _FlourSection extends StatelessWidget {
  const _FlourSection({
    required this.api,
    required this.onChanged,
    this.bakery,
  });

  final BakeryApi api;
  final Bakery? bakery;
  final VoidCallback onChanged;

  Future<void> _open(BuildContext context, AdminRecordKind kind) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdminRecordSheet(api: api, kind: kind, bakery: bakery),
    );

    if (saved == true) onChanged();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Heading(title: 'آرد', icon: Icons.grain_rounded),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () =>
                    _open(context, AdminRecordKind.flourIntake),
                icon: const Icon(Icons.local_shipping_rounded, size: 18),
                label: const Text('ورود آرد'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () =>
                    _open(context, AdminRecordKind.consignment),
                icon: const Icon(Icons.swap_horiz_rounded, size: 18),
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
          Icon(Icons.person_rounded, size: 20, color: scheme.onSurfaceVariant),
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
                  color: const Color(0xFF2E9E6B),
                ),
          ),
        ],
      ),
    );
  }
}
