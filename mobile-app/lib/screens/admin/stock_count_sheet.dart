import 'package:flutter/material.dart';

import '../../models/stock_count.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';

/// «چند کیسه در انبار هست؟» — از قفسه پرسیده می‌شود، از دفتر جواب
/// می‌گیرد.
///
/// دفتر خطای خودش را پیدا نمی‌کند: کیسه‌ای که آمده و فاکتورش ثبت نشده،
/// آردی که ریخته، فرمولی که کمی بیشتر یا کمتر از واقعیت حساب می‌کند — هر
/// کدام دو طرف دفتر را با هم جور می‌گذارد و با قفسه نه.
///
/// ۱۴۰۵/۰۶/۲۳ موجودی آرد دو بار با دست اصلاح شد، هر بار با یک دستور روی
/// سرور، و دفتر آرد نشان داد پیش از آن هم پنج بار همین شده بوده. کاری که
/// پنج بار با دست انجام شده، صفحه می‌خواهد.
///
/// قرینهٔ [showCashCountSheet].
Future<bool?> showStockCountSheet(
  BuildContext context,
  BakeryApi api, {
  String item = 'flour',
}) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (context) => StockCountSheet(api: api, item: item),
  );
}

class StockCountSheet extends StatefulWidget {
  const StockCountSheet({super.key, required this.api, this.item = 'flour'});

  final BakeryApi api;
  final String item;

  @override
  State<StockCountSheet> createState() => _StockCountSheetState();
}

class _StockCountSheetState extends State<StockCountSheet> {
  final _formKey = GlobalKey<FormState>();
  final _counted = TextEditingController();
  final _note = TextEditingController();

  late Future<StockCountBook> _book;
  bool _adjust = false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _book = widget.api.stockCounts(item: widget.item);
  }

  @override
  void dispose() {
    _counted.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save(StockCountBook book) async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final count = await widget.api.recordStockCount(
        item: widget.item,
        counted: double.parse(_counted.text.trim()),
        adjust: _adjust,
        note: _note.text.trim(),
      );

      if (!mounted) return;

      Navigator.pop(context, true);
      showMessage(
        context,
        count.isExact
            ? 'انبار با دفتر می‌خواند.'
            : '${count.differenceLabel} ${count.differenceAmount} ثبت شد.',
        isError: !count.isExact,
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
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: SingleChildScrollView(
        child: FutureBuilder<StockCountBook>(
          future: _book,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Padding(
                padding: EdgeInsets.all(40),
                child: Center(child: CircularProgressIndicator()),
              );
            }

            if (snapshot.hasError) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 24),
                child: Text(
                  snapshot.error is ApiException
                      ? (snapshot.error as ApiException).message
                      : 'خواندن انبار ممکن نشد.',
                  style: theme.textTheme.bodyMedium,
                  textAlign: TextAlign.center,
                ),
              );
            }

            final book = snapshot.data!;

            return Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      Icon(Icons.inventory_2_rounded,
                          color: theme.colorScheme.primary),
                      const SizedBox(width: 10),
                      Text(
                        'شمارش انبار',
                        style: theme.textTheme.titleLarge
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                    ],
                  ),
                  const SizedBox(height: 18),

                  // عددی که در برابرش می‌شمارید، پیش از آنکه چیزی تایپ
                  // شود.
                  Card(
                    margin: EdgeInsets.zero,
                    child: Padding(
                      padding:
                          const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('دفتر می‌گوید', style: theme.textTheme.bodySmall),
                          const SizedBox(height: 4),
                          Text(
                            '${book.itemName} — ${book.expectedLabel}',
                            style: theme.textTheme.titleLarge
                                ?.copyWith(fontWeight: FontWeight.w800),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            book.neverCounted
                                ? 'تا حالا شمرده نشده'
                                : 'آخرین شمارش: ${book.lastCountedAt}'
                                    ' (${book.daysSinceCount} روز پیش)',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),

                  TextFormField(
                    controller: _counted,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    autofocus: true,
                    decoration: InputDecoration(
                      labelText: 'شمرده شد (${book.unitLabel})',
                      prefixIcon: const Icon(Icons.inventory_rounded),
                    ),
                    validator: (value) {
                      final parsed = double.tryParse(value?.trim() ?? '');

                      if (parsed == null) return 'تعداد را وارد کنید.';
                      if (parsed < 0) return 'تعداد نمی‌تواند منفی باشد.';

                      return null;
                    },
                  ),
                  const SizedBox(height: 12),

                  TextFormField(
                    controller: _note,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'توضیح (اختیاری)',
                      helperText: 'اگر دلیل اختلاف را می‌دانید بنویسید —'
                          ' یک ماه بعد کسی یادش نیست.',
                    ),
                  ),
                  const SizedBox(height: 8),

                  SwitchListTile(
                    value: _adjust,
                    onChanged: (value) => setState(() => _adjust = value),
                    contentPadding: EdgeInsets.zero,
                    title: const Text('دفتر با قفسه یکی شود'),
                    subtitle: Text(
                      _adjust
                          ? 'اختلاف به‌عنوان «شمارش انبار» نوشته می‌شود'
                          : 'فقط ثبت می‌شود؛ موجودی دست نمی‌خورد',
                    ),
                    secondary: const Icon(Icons.balance_rounded),
                  ),
                  const SizedBox(height: 12),

                  FilledButton.icon(
                    onPressed: _saving ? null : () => _save(book),
                    icon: _saving
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.check_rounded),
                    label: const Text('ثبت شمارش'),
                  ),

                  if (book.counts.isNotEmpty) ...[
                    const SizedBox(height: 24),
                    Text(
                      'شمارش‌های قبلی',
                      style: theme.textTheme.titleSmall
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 8),
                    for (final count in book.counts.take(10))
                      _CountRow(count: count),
                  ],
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

class _CountRow extends StatelessWidget {
  const _CountRow({required this.count});

  final StockCount count;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(count.countedAt, style: theme.textTheme.bodyMedium),
                Text(
                  'شمرده: ${count.countedLabel}'
                  '${count.adjusted ? ' • دفتر اصلاح شد' : ''}',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          Text(
            count.isExact
                ? count.differenceLabel
                : '${count.differenceLabel} ${count.differenceAmount}',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w700,
              color: count.isExact ? scheme.primary : scheme.error,
            ),
          ),
        ],
      ),
    );
  }
}
