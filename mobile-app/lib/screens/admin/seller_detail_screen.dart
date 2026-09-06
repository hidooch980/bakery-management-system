import 'package:flutter/material.dart';
import '../../utils/json.dart';

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
              keyedGroup(data['summary']);
          final days =
              rowList(data['days']);

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

              // Newest first from the server, so the first entry is the
              // most recent day — the one a manager opening this screen is
              // almost always asking about.
              for (final (i, day) in days.indexed)
                _Day(day: day, startOpen: i == 0),
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
    final types = rowList(summary['by_payment_type'])
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

/// One day, closed until it is asked about.
///
/// The first version drew every line of every day at once. Ninety-odd
/// sales over a quota period is a wall of text on a phone, and the day
/// totals — which are what the owner actually reads first — were lost
/// inside it. A day is a summary now, and opens when he wants the detail.
class _Day extends StatefulWidget {
  const _Day({required this.day, required this.startOpen});

  final Map<String, dynamic> day;

  /// Today is open on arrival; the rest are not. The question a manager
  /// has when he opens this screen is almost always about today.
  final bool startOpen;

  @override
  State<_Day> createState() => _DayState();
}

class _DayState extends State<_Day> {
  late bool _open = widget.startOpen;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final lines =
        rowList(widget.day['lines']);

    final shortfall = lines.fold<int>(
      0,
      (sum, l) => sum + ((l['shortfall_count'] as int?) ?? 0),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => setState(() => _open = !_open),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Row(
                children: [
                  Icon(
                    _open ? Icons.expand_more_rounded : Icons.chevron_left_rounded,
                    size: IconSize.row,
                    color: scheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${widget.day['date_long'] ?? widget.day['date_jalali']}',
                          style: Theme.of(context)
                              .textTheme
                              .bodyMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                        Text(
                          '${lines.length} فروش'
                          '${shortfall > 0 ? '  ·  کسری $shortfall نان' : ''}',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: shortfall > 0
                                    ? AppColors.attention
                                    : scheme.onSurfaceVariant,
                              ),
                        ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        '${widget.day['bread_count']} نان',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      ),
                      Text(
                        '${widget.day['amount_formatted']}',
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          if (_open)
            Padding(
              padding: const EdgeInsets.only(right: 26, bottom: 4),
              child: Column(children: [for (final l in lines) _Line(line: l)]),
            ),
          const Divider(height: 1),
        ],
      ),
    );
  }
}

/// One sale, on one line.
///
/// Four stacked lines per sale is what the first version drew, and with a
/// hundred sales in a period nothing could be found in it. Time, kind and
/// loaves sit in fixed columns so the eye can run down them; the customer
/// and the note only appear when they say something, and the shortfall
/// keeps its own colour because it is the line worth stopping on.
class _Line extends StatelessWidget {
  const _Line({required this.line});

  final Map<String, dynamic> line;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final shortfall = line['shortfall_formatted'] as String?;
    final customer = line['customer'] as String?;
    final note = line['note'] as String?;
    final small = Theme.of(context).textTheme.bodySmall;
    final quiet = small?.copyWith(color: scheme.onSurfaceVariant);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SizedBox(width: 40, child: Text('${line['at']}', style: quiet)),
              SizedBox(
                width: 74,
                child: Text('${line['payment_label']}', style: small),
              ),
              SizedBox(
                width: 62,
                child: Text('${line['bread_count']} نان', style: quiet),
              ),
              Expanded(
                child: Text(
                  '${line['amount_formatted']}',
                  textAlign: TextAlign.end,
                  style: small,
                ),
              ),
            ],
          ),

          // Only when there is something to say. On an ordinary cash sale
          // these are all empty, and a blank line under every row is what
          // made the first version unreadable.
          if ((customer != null && customer.isNotEmpty) ||
              (note != null && note.isNotEmpty) ||
              shortfall != null)
            Padding(
              padding: const EdgeInsets.only(right: 40, top: 1),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (customer != null && customer.isNotEmpty)
                    Text(customer, style: quiet),
                  if (note != null && note.isNotEmpty)
                    Text(note, style: quiet),
                  if (shortfall != null)
                    Text(
                      'کسری ${line['shortfall_count']} نان — $shortfall',
                      style: small?.copyWith(color: AppColors.attention),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
