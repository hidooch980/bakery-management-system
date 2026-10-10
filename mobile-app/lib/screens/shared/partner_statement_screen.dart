import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';

/// پروندهٔ یک همکار: سرخط مانده و گردش ریز به کیسه، جدیدترین بالا.
///
/// همان صفحه‌ای که صاحب مغازه در طرح دید: بالای صفحه «طلب ما: ۱۰ کیسه»
/// (سبز)، «بدهی ما: …» (قرمز) یا «تسویه»؛ زیرش ردیف‌ها با ماندهٔ بعد از
/// هر کدام. فقط کیسه — نه کیلو و نه پول.
class PartnerStatementScreen extends StatefulWidget {
  const PartnerStatementScreen({
    super.key,
    required this.api,
    required this.partnerId,
    required this.partnerName,
  });

  final BakeryApi api;
  final int partnerId;
  final String partnerName;

  @override
  State<PartnerStatementScreen> createState() => _PartnerStatementScreenState();
}

class _PartnerStatementScreenState extends State<PartnerStatementScreen> {
  late Future<Map<String, dynamic>> _future;
  String? _from;
  String? _to;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.partnerStatement(widget.partnerId, from: _from, to: _to);

  Future<void> _refresh() async {
    final future = _load();
    setState(() => _future = future);
    await future;
  }

  Future<void> _pickDate({required bool from}) async {
    final controller = TextEditingController(text: from ? _from : _to);
    final value = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(from ? 'از تاریخ' : 'تا تاریخ'),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: TextInputType.datetime,
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(hintText: '1405/05/01'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, ''),
            child: const Text('همهٔ تاریخ‌ها'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('اعمال'),
          ),
        ],
      ),
    );

    if (value == null || !mounted) return;

    setState(() {
      if (from) {
        _from = value.isEmpty ? null : value;
      } else {
        _to = value.isEmpty ? null : value;
      }
      _future = _load();
    });
  }

  Future<void> _share(Map<String, dynamic> data) async {
    await Clipboard.setData(ClipboardData(text: statementText(data)));
    if (!mounted) return;
    showMessage(context, 'متن پرونده کپی شد؛ در پیام‌رسان بچسبانید.');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.partnerName)),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              final error = snapshot.error;

              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  ErrorBox(
                    message: error is ApiException
                        ? error.message
                        : 'پروندهٔ همکار خوانده نشد.',
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            final data = snapshot.data!;
            final rows = rowList(data['rows']).reversed.toList();
            final totals = keyedGroup(data['totals']);

            return ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              children: [
                PartnerHeadline(
                  balance: _num(data['current_bags']),
                  label: '${keyedGroup(data['headline'])['label'] ?? ''}',
                ),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    ActionChip(
                      label: Text(_from == null ? 'از ابتدا' : 'از $_from'),
                      onPressed: () => _pickDate(from: true),
                    ),
                    ActionChip(
                      label: Text(_to == null ? 'تا امروز' : 'تا $_to'),
                      onPressed: () => _pickDate(from: false),
                    ),
                    ActionChip(
                      avatar: const Icon(Icons.ios_share_rounded, size: 18),
                      label: const Text('اشتراک‌گذاری'),
                      onPressed: () => _share(data),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                _SectionTitle(
                  (_from != null)
                      ? 'گردش ریز (جدیدترین بالا) — ماندهٔ اول دوره ${signedBags(_num(data['opening_bags']))}'
                      : 'گردش ریز (جدیدترین بالا)',
                ),
                const SizedBox(height: 8),
                if (rows.isEmpty)
                  const EmptyState(
                    icon: Icons.inbox_rounded,
                    title: 'در این بازه گردشی نیست.',
                  )
                else
                  for (final row in rows) ...[
                    LedgerRowCard(row: row),
                    const SizedBox(height: 8),
                  ],
                const SizedBox(height: 8),
                _SectionTitle(
                  'جمع: دادیم ${bags(_num(totals['lent_bags']))}'
                  ' • گرفتیم ${bags(_num(totals['borrowed_bags']))}'
                  ' • برگشت ${bags(_num(totals['returns_bags']))} کیسه',
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

double _num(dynamic value) {
  if (value is num) return value.toDouble();

  return double.tryParse('$value') ?? 0;
}

/// «۱۲»، «۴۶.۵» — بدون صفر اضافه و بدون علامت.
String bags(double value) {
  final v = value.abs();
  if (v == v.roundToDouble()) return v.toStringAsFixed(0);

  var text = v.toStringAsFixed(2);
  while (text.endsWith('0')) {
    text = text.substring(0, text.length - 1);
  }

  return text;
}

/// با علامت: «+۱۰»، «−۲»، «0».
String signedBags(double value) {
  if (value.abs() < 0.001) return '0';

  return '${value > 0 ? '+' : '−'}${bags(value)}';
}

/// متن ساده برای فرستادن در پیام‌رسان.
String statementText(Map<String, dynamic> data) {
  final partner = keyedGroup(data['partner']);
  final rows = rowList(data['rows']);
  final buffer = StringBuffer()
    ..writeln('پروندهٔ ${partner['name'] ?? ''}')
    ..writeln('${keyedGroup(data['headline'])['label'] ?? ''}')
    ..writeln();

  for (final row in rows) {
    buffer.writeln(
      '${row['date_display']}  ${row['label']} ${bags(_num(row['bags']))} کیسه'
      '  — مانده ${signedBags(_num(row['balance_bags']))}',
    );
  }

  return buffer.toString();
}

/// سرخط بزرگ مانده، سبز برای طلب، قرمز برای بدهی، خاکستری برای تسویه.
class PartnerHeadline extends StatelessWidget {
  const PartnerHeadline(
      {super.key, required this.balance, required this.label});

  final double balance;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final owed = balance > 0.001;
    final owes = balance < -0.001;
    final tone = owed
        ? AppColors.moneyIn
        : owes
            ? AppColors.moneyOut
            : scheme.onSurfaceVariant;

    return Container(
      key: const ValueKey('partner-headline'),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(Corner.card),
        border: Border.all(color: tone.withValues(alpha: 0.45)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'ماندهٔ فعلی',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tone,
                ),
          ),
          const SizedBox(height: 6),
          Text(
            owed
                ? 'آرد ما نزد این همکار است'
                : owes
                    ? 'آرد این همکار نزد ماست'
                    : 'حساب آرد با این همکار صاف است',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

/// یک ردیف گردش: نوع و کیسه، تاریخ، شرح و ماندهٔ بعد از همین ردیف.
class LedgerRowCard extends StatelessWidget {
  const LedgerRowCard({super.key, required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final kind = '${row['kind']}';
    final color = switch (kind) {
      'lent' => AppColors.moneyIn,
      'borrowed' => AppColors.moneyOut,
      _ => AppColors.moneyNeutral,
    };
    final note = '${row['note'] ?? ''}'.trim();
    final approximate = row['approximate'] == true;
    final small = Theme.of(context).textTheme.bodySmall?.copyWith(
          color: scheme.onSurfaceVariant,
        );

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${row['label']} ${bags(_num(row['bags']))} کیسه',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: color,
                        ),
                  ),
                ),
                Text('${row['date_display'] ?? ''}', style: small),
              ],
            ),
            const SizedBox(height: 4),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(
                    [
                      if (note.isNotEmpty) note,
                      if (approximate) '(تاریخ تقریبی)',
                    ].join('  '),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: small,
                  ),
                ),
                const SizedBox(width: 8),
                Text('مانده: ', style: small),
                // جهت چپ‌به‌راست تا علامت «+» و «−» کنار عدد بماند.
                Text(
                  signedBags(_num(row['balance_bags'])),
                  textDirection: TextDirection.ltr,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: scheme.onSurface,
                      ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: Theme.of(context).textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
    );
  }
}
