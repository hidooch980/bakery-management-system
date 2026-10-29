import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/json.dart';
import 'admin_home_screen.dart';

/// ماندهٔ مالی فروشنده در کنار سهمیه و تولید کل نانوایی.
class SellerDailyReportScreen extends StatefulWidget {
  const SellerDailyReportScreen({
    super.key,
    required this.api,
    required this.sellerId,
    required this.sellerName,
  });

  final BakeryApi api;
  final int sellerId;
  final String sellerName;

  @override
  State<SellerDailyReportScreen> createState() => _SellerDailyReportScreenState();
}

class _SellerDailyReportScreenState extends State<SellerDailyReportScreen> {
  String? _from;
  String? _to;
  late Future<Map<String, dynamic>> _future = _load();

  Future<Map<String, dynamic>> _load() => widget.api.sellerDailyReport(
        widget.sellerId,
        from: _from,
        until: _to,
      );

  void _reload() => setState(() {
        _future = _load();
      });

  void _period(int days, Map<String, dynamic> report) {
    final end = DateTime.tryParse('${report['to']}') ?? DateTime.now();
    _from = days == 0
        ? null
        : end.subtract(Duration(days: days - 1)).toIso8601String().split('T')[0];
    _to = days == 0 ? null : end.toIso8601String().split('T')[0];
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('ریز بدهی ${widget.sellerName}')),
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
                Text(error is ApiException ? error.message : 'گزارش خوانده نشد.'),
                TextButton(onPressed: _reload, child: const Text('تلاش دوباره')),
              ]),
            );
          }
          final report = snapshot.data!;
          final summary = keyedGroup(report['summary']);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              AdminSection(
                title: 'ماندهٔ حساب — همهٔ تاریخ‌ها',
                icon: Icons.account_balance_wallet_outlined,
                children: [
                  for (final entry in {
                    'cash_formatted': 'پول نقد نزد فروشنده',
                    'shortfall_formatted': 'کسری نان',
                    'difference_formatted': 'اختلاف صندوق',
                    'total_formatted': 'جمع قابل تسویه',
                    'on_account_formatted': 'علی‌الحساب باقیمانده از پرداخت‌های قبلی',
                    'still_owed_formatted': 'ماندهٔ بدهی فروشنده',
                    'credit_formatted': 'نسیهٔ مشتری‌ها — جدا از بدهی فروشنده',
                  }.entries)
                    AdminRow(
                      label: entry.value,
                      value: '${summary[entry.key] ?? '—'}',
                      emphasise: entry.key == 'still_owed_formatted',
                    ),
                ],
              ),
              const SizedBox(height: 14),
              Text('${report['card_source'] ?? ''}'),
              Text('${report['scope_note'] ?? ''}'),
              Text('${report['quota_note'] ?? ''}'),
              Row(children: [
                Expanded(child: Text('${report['from_label']} تا ${report['to_label']}')),
                PopupMenuButton<int>(
                  tooltip: 'بازه گزارش',
                  onSelected: (days) => _period(days, report),
                  itemBuilder: (_) => const [
                    PopupMenuItem(value: 0, child: Text('دورهٔ سهمیه جاری')),
                    PopupMenuItem(value: 30, child: Text('۳۰ روز اخیر')),
                    PopupMenuItem(value: 90, child: Text('۹۰ روز اخیر')),
                  ],
                ),
              ]),
              for (final day in rowList(report['days']))
                Card(
                  child: ExpansionTile(
                    title: Text('${day['date_label']}'),
                    subtitle: Text(day['quota_bags'] == null
                        ? '${day['quota_status']}'
                        : 'سهم آرد نانوایی: ${day['quota_bags']} کیسه'),
                    childrenPadding: const EdgeInsets.all(12),
                    children: [
                      AdminRow(label: 'معادل کارتخوان سهمیه', value: '${day['quota_card_target'] ?? 'نامشخص'} نان'),
                      AdminRow(label: 'چانهٔ تولیدشدهٔ نانوایی', value: '${day['bakery_chane_count']}'),
                      AdminRow(label: 'کارتخوان ثبت‌شدهٔ کل نانوایی', value: '${day['bakery_card_bread_count']} نان'),
                      AdminRow(label: 'کارتخوان این فروشنده', value: '${day['seller_card_bread_count']} نان — ${day['seller_card_amount_formatted']}'),
                      AdminRow(label: 'نان منزل کارکنان', value: '${day['home_bread_count']} نان'),
                      AdminRow(label: 'نسیه و مدارس', value: '${day['credit_bread_count']} نان'),
                      AdminRow(label: 'ضایعات', value: '${day['waste_bread_count']} نان'),
                      AdminRow(label: 'تحویل وجه در این تاریخ', value: '${day['handed_over_formatted']}'),
                      if (day['has_sales'] != true) const Text('برای این فروشنده در اپ فروشی ثبت نشده است.'),
                      for (final line in rowList(day['lines'])) _SaleLine(line: line),
                    ],
                  ),
                ),
              const SizedBox(height: 16),
              Text('ریز بدهی‌های باز — همهٔ تاریخ‌ها', style: Theme.of(context).textTheme.titleMedium),
              if (rowList(report['open_days']).isEmpty) const Text('بدهی بازی ثبت نشده است.'),
              for (final day in rowList(report['open_days']))
                ExpansionTile(
                  title: Text('${day['date_label']} — ${day['total_formatted']}'),
                  children: [
                    Text('نسیهٔ مشتری‌ها: ${day['credit_formatted']}'),
                    for (final line in rowList(day['lines'])) _SaleLine(line: line),
                  ],
                ),
              const SizedBox(height: 16),
              Text('پرداخت‌ها در بازهٔ انتخابی', style: Theme.of(context).textTheme.titleMedium),
              Text('${report['handover_note'] ?? ''}'),
              for (final payment in rowList(report['handovers']))
                ListTile(
                  title: Text('${payment['date_label']} — ${payment['amount_formatted']}'),
                  subtitle: payment['is_reversed'] == true ? const Text('اصلاح و برگشت داده شده؛ از بدهی کم نمی‌شود') : null,
                ),
              if (report['handovers_truncated'] == true) const Text('فقط ۱۰۰ پرداخت آخر نمایش داده شده؛ بازه را کوتاه‌تر کنید.'),
            ],
          );
        },
      ),
    );
  }
}

class _SaleLine extends StatelessWidget {
  const _SaleLine({required this.line});
  final Map<String, dynamic> line;

  @override
  Widget build(BuildContext context) => ListTile(
        isThreeLine: true,
        title: Text('#${line['id']} — ${line['payment_label']} — ${line['bread_count']} نان'),
        subtitle: Text('چانه #${line['chane_entry_id']} — ${line['time']} — ${line['amount_formatted']}\n'
            'نقد ${line['cash_formatted']} | کسری ${line['shortfall_formatted']} | اختلاف ${line['difference_formatted']}\n'
            'بدهی باز این ثبت: ${line['open_debt_formatted']}'),
      );
}
