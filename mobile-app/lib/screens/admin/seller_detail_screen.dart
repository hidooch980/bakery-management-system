import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';

/// One seller, sale by sale.
///
/// Grouped by day rather than shown flat, because that is how the shop
/// remembers a week — «سه‌شنبه چقدر فروختی» — and because ninety lines in
/// a single column is not something anybody reads standing in a bakery.
///
/// Every line carries the time, the payment type, the loaves and the
/// customer where there is one. The owner reads this next to the person it
/// is about, and a row that says only a number gives him nothing to ask.
class SellerDetailScreen extends StatefulWidget {
  const SellerDetailScreen({
    super.key,
    required this.api,
    required this.sellerId,
    required this.sellerName,
  });

  final BakeryApi api;
  final int sellerId;
  final String sellerName;

  @override
  State<SellerDetailScreen> createState() => _SellerDetailScreenState();
}

class _SellerDetailScreenState extends State<SellerDetailScreen> {
  late Future<Map<String, dynamic>> _detail;

  @override
  void initState() {
    super.initState();
    _detail = widget.api.sellerDetail(widget.sellerId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('کارکرد ${widget.sellerName}')),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _detail,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError || snapshot.data == null) {
            return const Center(child: Text('در دسترس نیست'));
          }

          final data = snapshot.data!;
          final summary =
              (data['summary'] as Map?)?.cast<String, dynamic>() ?? {};
          final days =
              (data['days'] as List? ?? const []).cast<Map<String, dynamic>>();

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _Summary(summary: summary, data: data),
              const SizedBox(height: 16),

              if (days.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 32),
                  child: Center(child: Text('در این دوره فروشی ثبت نشده')),
                ),

              for (final day in days) _Day(day: day),
            ],
          );
        },
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.summary, required this.data});

  final Map<String, dynamic> summary;
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final types = (summary['by_payment_type'] as List? ?? const [])
        .cast<Map<String, dynamic>>();
    final shortfallCount = summary['shortfall_count'] as int? ?? 0;

    return AdminSection(
      title: 'خلاصه',
      icon: Icons.summarize_rounded,
      trailing: Text(
        '${data['from_jalali']} تا ${data['to_jalali']}',
        style: Theme.of(context).textTheme.bodySmall,
      ),
      children: [
        AdminRow(
          label: 'نان فروخته‌شده',
          value: '${summary['bread_count'] ?? 0}',
          emphasise: true,
        ),
        AdminRow(
          label: 'مبلغ فروش',
          value: '${summary['amount_formatted'] ?? '—'}',
          emphasise: true,
        ),
        AdminRow(
          label: 'روزهای کاری',
          value: '${summary['days_active'] ?? 0}',
        ),
        AdminRow(
          label: 'تعداد فروش',
          value: '${summary['sale_count'] ?? 0}',
        ),

        if (types.isNotEmpty) const Divider(height: 20),
        for (final type in types)
          AdminRow(
            label: '${type['label']}',
            value: '${type['bread_count']} نان — ${type['amount_formatted']}',
          ),

        // Below the takings and visually apart from them. Bread that left
        // with no money behind it is not a smaller sale, it is a different
        // kind of fact.
        if (shortfallCount > 0) ...[
          const Divider(height: 20),
          AdminRow(
            label: 'کسری',
            value: '$shortfallCount نان — ${summary['shortfall_formatted']}',
            color: AppColors.attention,
          ),
        ],
      ],
    );
  }
}

class _Day extends StatelessWidget {
  const _Day({required this.day});

  final Map<String, dynamic> day;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final lines =
        (day['lines'] as List? ?? const []).cast<Map<String, dynamic>>();

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${day['date_long'] ?? day['date_jalali']}',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              Text(
                '${day['bread_count']} نان  ·  ${day['amount_formatted']}',
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: scheme.onSurfaceVariant),
              ),
            ],
          ),
          const SizedBox(height: 6),
          for (final line in lines) _Line(line: line),
        ],
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.line});

  final Map<String, dynamic> line;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final shortfall = line['shortfall_formatted'] as String?;
    final customer = line['customer'] as String?;
    final note = line['note'] as String?;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 44,
            child: Text(
              '${line['at']}',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${line['payment_label']}  ·  ${line['bread_count']} نان',
                  style: Theme.of(context).textTheme.bodySmall,
                ),

                // The customer, then the note. Either can be the reason a
                // line is worth asking about, and neither is on the row
                // that only carries a figure.
                if (customer != null && customer.isNotEmpty)
                  Text(
                    customer,
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                if (note != null && note.isNotEmpty)
                  Text(
                    note,
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                if (shortfall != null)
                  Text(
                    'کسری ${line['shortfall_count']} نان — $shortfall',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: AppColors.attention),
                  ),
              ],
            ),
          ),
          Text(
            '${line['amount_formatted']}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}
