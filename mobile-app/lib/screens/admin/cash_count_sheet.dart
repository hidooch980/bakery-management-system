import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../models/cash_count.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// «چقدر پول در کشو هست؟» — asked of the drawer, answered by the books.
///
/// Everything closed this month was about money *reaching* the till.
/// Nothing said whether the figure is true: change is given from the same
/// drawer, notes are handed over in a hurry, and a sale typed at the wrong
/// price leaves a gap both sides of the ledger agree about.
///
/// A gap found the same evening is a question somebody can still answer —
/// who was on the counter, what was sold around then. The same gap found
/// at month end is a number nobody can do anything with.
Future<bool?> showCashCountSheet(BuildContext context, BakeryApi api) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (context) => CashCountSheet(api: api),
  );
}

class CashCountSheet extends StatefulWidget {
  const CashCountSheet({super.key, required this.api});

  final BakeryApi api;

  @override
  State<CashCountSheet> createState() => _CashCountSheetState();
}

class _CashCountSheetState extends State<CashCountSheet> {
  final _formKey = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _note = TextEditingController();

  late Future<CashCountBook> _book;
  bool _adjust = false;
  bool _saving = false;

  /// نام همین یک شمارش، زده‌شده وقتی صفحه باز می‌شود و ثابت در هر تلاش
  /// دوباره.
  ///
  /// صفحه برای ثبت **یک** شمارش باز می‌شود، پس یک نام هم بس است. اگر
  /// جواب سرور گم شود — timeout، که روی موبایل ایران معمول است — دکمه
  /// دوباره فعال می‌شود و مالک دوباره می‌زند. بدون این نام، سرور آن تلاش
  /// دوم را یک شمارش تازه می‌بیند و اگر کلید «اصلاح» روشن باشد، دو بار
  /// اصلاح می‌نویسد.
  final String _attempt = const Uuid().v4();

  @override
  void initState() {
    super.initState();
    _book = widget.api.cashCounts();
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _saving = true);

    try {
      final value = MoneyFormat.parseInput(_amount.text.trim()) ?? 0;

      final count = await widget.api.recordCashCount(
        countedAmount: value,
        attemptKey: _attempt,
        adjust: _adjust,
        note: _note.text.trim(),
      );

      if (!mounted) return;

      Navigator.pop(context, true);
      showMessage(
        context,
        count.isExact
            ? 'صندوق با دفتر می‌خواند.'
            : '${count.differenceLabel} ${count.differenceFormatted} ثبت شد.',
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
        child: FutureBuilder<CashCountBook>(
          future: _book,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 40),
                child: Center(child: CircularProgressIndicator()),
              );
            }

            // A shop with no till flagged is told what is missing rather
            // than shown an empty form it cannot submit.
            if (snapshot.hasError) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 24),
                child: Text(
                  snapshot.error is ApiException
                      ? (snapshot.error as ApiException).message
                      : 'خواندن صندوق ممکن نشد.',
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
                      Icon(Icons.calculate_rounded, color: theme.colorScheme.primary),
                      const SizedBox(width: 10),
                      Text(
                        'شمارش صندوق',
                        style: theme.textTheme.titleLarge
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                    ],
                  ),
                  const SizedBox(height: 18),

                  // What to count against, before anything is typed.
                  Card(
                    margin: EdgeInsets.zero,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('دفتر می‌گوید', style: theme.textTheme.bodySmall),
                          const SizedBox(height: 4),
                          Text(
                            book.expectedFormatted,
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

                  // Said before the figure is typed, not after it is judged:
                  // the first count against books that never received the
                  // shop's past cash will read «اضافه» by a great deal, and
                  // that is the books catching up, not money gone astray.
                  if (book.ledgerBehind != null) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding:
                          const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                      decoration: BoxDecoration(
                        color: theme.colorScheme.tertiaryContainer,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Icon(
                            Icons.info_outline_rounded,
                            size: 20,
                            color: theme.colorScheme.onTertiaryContainer,
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'دفتر حدود ${book.ledgerBehind!.amountFormatted}'
                                  ' عقب است',
                                  style: theme.textTheme.bodyMedium?.copyWith(
                                    fontWeight: FontWeight.w800,
                                    color: theme.colorScheme.onTertiaryContainer,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  book.ledgerBehind!.message,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: theme.colorScheme.onTertiaryContainer,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],

                  const SizedBox(height: 16),

                  TextFormField(
                    controller: _amount,
                    keyboardType: TextInputType.number,
                    inputFormatters: const [GroupedAmountInputFormatter()],
                    autofocus: true,
                    decoration: const InputDecoration(
                      labelText: 'پول واقعی کشو',
                      prefixIcon: Icon(Icons.payments_rounded),
                    ),
                    validator: (value) {
                      final parsed = MoneyFormat.parseInput(value?.trim());

                      if (parsed == null) return 'مبلغ را وارد کنید.';
                      if (parsed < 0) return 'مبلغ نمی‌تواند منفی باشد.';

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
                    title: const Text('دفتر با کشو یکی شود'),
                    subtitle: Text(
                      _adjust
                          ? 'اختلاف به‌عنوان یک ردیف اصلاحی نوشته می‌شود'
                          : 'فقط ثبت می‌شود؛ هیچ پولی جابه‌جا نمی‌شود',
                    ),
                    secondary: const Icon(Icons.balance_rounded),
                  ),
                  const SizedBox(height: 12),

                  FilledButton.icon(
                    onPressed: _saving ? null : _save,
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

  final CashCount count;

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
                  'شمرده: ${count.countedFormatted}'
                  '${count.adjusted ? ' • دفتر اصلاح شد' : ''}',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
          Text(
            count.isExact
                ? count.differenceLabel
                : '${count.differenceLabel} ${count.differenceFormatted}',
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
