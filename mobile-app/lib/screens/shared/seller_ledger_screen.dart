import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';

/// «دفتر حساب»: every debt the seller took on and every handover, newest
/// first, with the balance after each — and a warning when that balance and
/// the account figure disagree. The same screen serves the seller (their
/// own) and the manager (any seller), so [load] says whose.
class SellerLedgerScreen extends StatefulWidget {
  const SellerLedgerScreen({super.key, required this.load, required this.title});

  final Future<Map<String, dynamic>> Function() load;
  final String title;

  @override
  State<SellerLedgerScreen> createState() => _SellerLedgerScreenState();
}

class _SellerLedgerScreenState extends State<SellerLedgerScreen> {
  late Future<Map<String, dynamic>> _future = widget.load();

  void _reload() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError || snapshot.data == null) {
            final error = snapshot.error;
            return Center(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Text(error is ApiException ? error.message : 'دفتر حساب خوانده نشد.'),
                TextButton(onPressed: _reload, child: const Text('تلاش دوباره')),
              ]),
            );
          }
          final data = snapshot.data!;
          final entries = rowList(data['entries']);
          final warning = data['warning'] as String?;
          final alert = data['debt_alert'] is Map ? keyedGroup(data['debt_alert']) : null;

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (warning != null)
                  _Banner(
                    key: const Key('ledger-warning'),
                    icon: Icons.report_problem_rounded,
                    color: AppColors.moneyOut,
                    text: warning,
                  )
                else
                  const _Banner(
                    icon: Icons.verified_rounded,
                    color: AppColors.moneyIn,
                    text: 'ماندهٔ حساب با دفتر یکی است.',
                  ),
                if (alert != null) ...[
                  const SizedBox(height: 8),
                  _Banner(
                    icon: Icons.warning_amber_rounded,
                    color: AppColors.attention,
                    text: '${alert['message'] ?? ''}',
                  ),
                ],
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _Figure(label: 'جمع بدهی‌ها', value: '${data['debts_total_formatted'] ?? ''}'),
                    _Figure(label: 'جمع تسویه‌ها', value: '${data['paid_total_formatted'] ?? ''}'),
                    _Figure(label: 'پیش‌پرداخت', value: '${data['prepaid_formatted'] ?? ''}'),
                    _Figure(
                      label: 'ماندهٔ بدهی',
                      value: '${data['balance_formatted'] ?? ''}',
                      strong: true,
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  'نسیهٔ مدارس و مشتری‌ها جدا از بدهی فروشنده است: '
                  '${data['customer_credit_formatted'] ?? ''}',
                  style: text.bodySmall,
                ),
                const SizedBox(height: 14),
                Text('گردش حساب', style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                if (entries.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Center(child: Text('گردشی ثبت نشده است.')),
                  ),
                for (final e in entries) _Row(entry: e),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({super.key, required this.icon, required this.color, required this.text});

  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(Corner.control),
          border: Border.all(color: color.withValues(alpha: 0.35)),
        ),
        child: Row(children: [
          Icon(icon, color: color, size: IconSize.button),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: color, fontWeight: FontWeight.w700)),
          ),
        ]),
      );
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, this.strong = false});

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      width: 160,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(Corner.control),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 4),
        Text(value,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: strong ? AppColors.moneyOut : null,
                )),
      ]),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.entry});

  final Map<String, dynamic> entry;

  @override
  Widget build(BuildContext context) {
    final isPayment = entry['kind'] == 'payment';
    final text = Theme.of(context).textTheme;
    final amount = isPayment ? entry['credit_formatted'] : entry['debit_formatted'];

    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        dense: true,
        leading: Icon(
          isPayment ? Icons.south_west_rounded : Icons.north_east_rounded,
          color: isPayment ? AppColors.moneyIn : AppColors.moneyOut,
        ),
        title: Text('${entry['label'] ?? ''}', style: text.bodyMedium),
        subtitle: Text('${entry['on_label'] ?? ''}', style: text.bodySmall),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              '${isPayment ? '−' : '+'} ${amount ?? ''}',
              style: text.bodySmall?.copyWith(
                fontWeight: FontWeight.w700,
                color: isPayment ? AppColors.moneyIn : AppColors.moneyOut,
              ),
            ),
            Text('مانده: ${entry['balance_formatted'] ?? ''}', style: text.bodySmall),
          ],
        ),
      ),
    );
  }
}
