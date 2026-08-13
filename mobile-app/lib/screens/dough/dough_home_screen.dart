import 'package:flutter/material.dart';

import '../../utils/formatters.dart';
import 'package:provider/provider.dart';

import '../../models/entries.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../models/bakery.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/role_home_scaffold.dart';
import '../../widgets/common.dart';
import '../../theme/app_theme.dart';

/// Home screen for the dough maker: record bag counts and review own history.
class DoughHomeScreen extends StatefulWidget {
  const DoughHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<DoughHomeScreen> createState() => _DoughHomeScreenState();
}

class _DoughHomeScreenState extends State<DoughHomeScreen> {
  late Future<List<DoughEntry>> _history;

  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _loadBakery();
    _history = widget.api.myDoughHistory();
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The page reads fine without the shop's name in the bar.
    }
  }

  void _reload() {
    setState(() => _history = widget.api.myDoughHistory());
  }

  Future<void> _openRecordSheet() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _RecordDoughSheet(api: widget.api),
    );

    if (saved == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

    return RoleHomeScaffold(
      api: widget.api,
      bakery: _bakery,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openRecordSheet,
        icon: const Icon(Icons.add_rounded),
        label: const Text('ثبت خمیر'),
      ),
      tabs: [
        HomeTab(
          label: 'خمیرگیری',
          title: 'خمیرگیری',
          icon: Icons.blender_outlined,
          selectedIcon: Icons.blender_rounded,
          builder: (_) => RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 96),
              children: [
                Text(
                  'سلام ${user?.name ?? ''}',
                  style: Theme.of(context)
                      .textTheme
                      .headlineSmall
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 20),
                AttendanceCard(api: widget.api),
                const SizedBox(height: 24),
                Text(
                  'ثبت‌های من',
                  style: Theme.of(context)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 12),
                FutureBuilder<List<DoughEntry>>(
                  future: _history,
                  builder: (context, snapshot) {
                    if (snapshot.connectionState == ConnectionState.waiting) {
                      return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 40),
                        child: Center(child: CircularProgressIndicator()),
                      );
                    }

                    if (snapshot.hasError) {
                      return ErrorBox(
                        message: '${snapshot.error}',
                        onRetry: _reload,
                      );
                    }

                    final entries = snapshot.data ?? const <DoughEntry>[];

                    if (entries.isEmpty) {
                      return const EmptyState(
                        icon: Icons.inventory_2_outlined,
                        title: 'هنوز خمیری ثبت نکرده‌اید',
                        subtitle:
                            'با دکمه «ثبت خمیر» اولین مورد را اضافه کنید.',
                      );
                    }

                    return Column(
                      children: [
                        for (final entry in entries) ...[
                          _DoughTile(entry: entry),
                          const SizedBox(height: 10),
                        ],
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _DoughTile extends StatelessWidget {
  const _DoughTile({required this.entry});

  final DoughEntry entry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: scheme.primary.withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(Icons.inventory_2_rounded,
                  color: scheme.primary, size: 24),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${entry.bagCount} کیسه',
                    style: Theme.of(context)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    entry.createdAt != null
                        ? JalaliFormat.dateTime(entry.createdAt!)
                        : '—',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                  if (entry.note != null && entry.note!.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      entry.note!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ],
              ),
            ),
            Chip(
              label: Text(entry.isPending ? 'در انتظار چانه' : 'چانه شده'),
              visualDensity: VisualDensity.compact,
              backgroundColor: (entry.isPending
                      ? AppColors.emberHot
                      : const Color(0xFF2E9E6B))
                  .withValues(alpha: 0.15),
            ),
          ],
        ),
      ),
    );
  }
}

class _RecordDoughSheet extends StatefulWidget {
  const _RecordDoughSheet({required this.api});

  final BakeryApi api;

  @override
  State<_RecordDoughSheet> createState() => _RecordDoughSheetState();
}

class _RecordDoughSheetState extends State<_RecordDoughSheet> {
  final _formKey = GlobalKey<FormState>();
  final _bagController = TextEditingController();
  final _noteController = TextEditingController();
  bool _saving = false;

  @override
  void dispose() {
    _bagController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final queued = await widget.api.recordDough(
        bagCount: int.parse(_bagController.text),
        note: _noteController.text.trim(),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        queued
            ? 'اینترنت وصل نیست؛ ثبت خمیر ذخیره شد و با اتصال بعدی ارسال می‌شود.'
            : 'ثبت خمیر انجام شد.',
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
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
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
                      color: Theme.of(context).colorScheme.outlineVariant,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 22),
                Text(
                  'ثبت خمیر جدید',
                  style: Theme.of(context)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _bagController,
                  keyboardType: TextInputType.number,
                  autofocus: true,
                  style: const TextStyle(
                      fontSize: 22, fontWeight: FontWeight.w700),
                  decoration: const InputDecoration(
                    labelText: 'تعداد کیسه خمیرگیری‌شده',
                    prefixIcon: Icon(Icons.inventory_2_outlined),
                    suffixText: 'کیسه',
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
                  controller: _noteController,
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
                  label: Text(_saving ? 'در حال ثبت…' : 'ثبت خمیر'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
