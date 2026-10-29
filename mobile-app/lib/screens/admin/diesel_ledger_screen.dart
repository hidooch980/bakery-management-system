import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/json.dart';
import 'admin_home_screen.dart';

/// گردش ماهانهٔ سوخت، با انتقال ماندهٔ سهمیه و باک در دو حساب جدا.
class DieselLedgerScreen extends StatefulWidget {
  const DieselLedgerScreen({super.key, required this.api});
  final BakeryApi api;

  @override
  State<DieselLedgerScreen> createState() => _DieselLedgerScreenState();
}

class _DieselLedgerScreenState extends State<DieselLedgerScreen> {
  String? _month;
  late Future<Map<String, dynamic>> _future = widget.api.dieselLedger();

  void _load([String? month]) {
    final future = widget.api.dieselLedger(month: month ?? _month);
    setState(() {
      _month = month ?? _month;
      _future = future;
    });
  }

  String litres(dynamic value) {
    final number = value is num ? value : num.tryParse('$value');
    if (number == null) return 'نامشخص';
    return '${number.toStringAsFixed(number % 1 == 0 ? 0 : 2)} لیتر';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('گردش و انتقال ماندهٔ گازوئیل')),
        body: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError || snapshot.data == null) {
              final error = snapshot.error;
              return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
                Text(error is ApiException ? error.message : 'گردش سوخت دریافت نشد.'),
                TextButton(onPressed: () => _load(), child: const Text('تلاش دوباره')),
              ]));
            }
            final data = snapshot.data!;
            return RefreshIndicator(
              onRefresh: () async {
                _load();
                await _future;
              },
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Row(children: [
                  IconButton(tooltip: 'ماه قبل', onPressed: () => _load(data['previous_month'] as String), icon: const Icon(Icons.chevron_right)),
                  Expanded(child: Text('${data['month_label']}', textAlign: TextAlign.center)),
                  IconButton(tooltip: 'ماه بعد', onPressed: data['next_month'] == null ? null : () => _load(data['next_month'] as String), icon: const Icon(Icons.chevron_left)),
                ]),
                Text('${data['from_label']} تا ${data['to_label']}'),
                AdminSection(title: 'سهمیهٔ قابل دریافت', icon: Icons.local_gas_station_outlined, children: [
                  AdminRow(label: 'مانده از ماه قبل', value: litres(data['quota_opening_litres'])),
                  Text('${data['quota_opening_source']}'),
                  AdminRow(label: 'سهمیه ماه', value: data['quota_recorded'] == true ? litres(data['quota_litres']) : 'ثبت نشده است'),
                  AdminRow(label: 'تحویل این ماه', value: litres(data['delivered_litres'])),
                  AdminRow(label: 'ماندهٔ قابل دریافت؛ انتقال به ماه بعد', value: litres(data['quota_closing_litres']), emphasise: true),
                ]),
                const SizedBox(height: 16),
                AdminSection(title: 'گردش تخمینی باک', icon: Icons.water_drop_outlined, children: [
                  AdminRow(label: 'مانده باک از قبل', value: litres(data['tank_opening_litres'])),
                  AdminRow(label: 'ورودی این ماه', value: litres(data['delivered_litres'])),
                  AdminRow(label: 'مصرف تخمینی پخت', value: litres(data['consumed_litres'])),
                  AdminRow(label: 'ماندهٔ تخمینی باک؛ انتقال به ماه بعد', value: litres(data['tank_closing_litres']), emphasise: true),
                ]),
                const SizedBox(height: 12),
                Text('${data['note']}'),
                if (data['uses_default_rate'] == true) const Text('برای بخشی از خمیرها نرخ تاریخی ثبت نشده؛ نرخ پیش‌فرض فعلی در برآورد استفاده شده است.'),
                if ((data['quota_records_count'] as num? ?? 0) > 1) const Text('بیش از یک سهمیه برای این ماه ثبت شده؛ جمع رکوردها نمایش داده می‌شود.'),
                const SizedBox(height: 16),
                Text('ریز گردش این ماه', style: Theme.of(context).textTheme.titleMedium),
                if (rowList(data['lines']).isEmpty) const Text('در این ماه گردشی ثبت نشده است.'),
                for (final line in rowList(data['lines']))
                  ListTile(
                    leading: Icon(line['type'] == 'delivery' ? Icons.south_west : Icons.north_east),
                    title: Text('${line['label']} #${line['id']} — ${litres(line['litres'])}'),
                    subtitle: Text('${line['date_label']}\n${line['detail']}\n${line['user'] ?? ''}'),
                  ),
                if (data['lines_truncated'] == true) const Text('۲۰۰ ردیف آخر نمایش داده شده؛ جمع‌ها شامل تمام گردش ماه هستند.'),
              ]),
            );
          },
        ),
      );
}
