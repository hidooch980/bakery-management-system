import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import 'admin_home_screen.dart';

/// How the takings were paid for, and who took them.
///
/// «فروش ۱۲٬۰۰۰٬۰۰۰ ریال» is one figure covering two different facts: cash
/// the shop is holding and credit it is owed. The owner could read the
/// total and not how much of it he actually has — which is the question
/// behind «چقدر فروختیم» most of the time. The endpoint has split it since
/// the reports were written; the phone never asked.
class SalesBreakdownSection extends StatefulWidget {
  const SalesBreakdownSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
    this.currency = Currency.toman,
  });

  final BakeryApi api;
  final String from;
  final String to;
  final Currency currency;

  @override
  State<SalesBreakdownSection> createState() => _SalesBreakdownSectionState();
}

class _SalesBreakdownSectionState extends State<SalesBreakdownSection> {
  late Future<Map<String, dynamic>> _report;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  @override
  void didUpdateWidget(SalesBreakdownSection oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.from != widget.from || oldWidget.to != widget.to) {
      setState(() => _report = _load());
    }
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.salesReport(from: widget.from, to: widget.to);

  String _money(dynamic value) => MoneyFormat.format(
        value is num ? value : num.tryParse('$value') ?? 0,
        currency: widget.currency,
      );

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'تفکیک فروش',
            icon: Icons.receipt_long_outlined,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError) return const SizedBox.shrink();

        final data = snapshot.data!;
        final byType = (data['by_payment_type'] as Map?) ?? const {};
        final bySeller = ((data['by_seller'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList();

        if (byType.isEmpty) {
          return const AdminSection(
            title: 'تفکیک فروش',
            icon: Icons.receipt_long_outlined,
            children: [
              AdminRow(label: 'وضعیت', value: 'در این بازه فروشی ثبت نشده'),
            ],
          );
        }

        // Biggest first: the line that matters is the one with the most
        // money behind it, not whichever the server happened to group first.
        final types = byType.entries
            .map((e) => (e.value as Map).cast<String, dynamic>())
            .toList()
          ..sort((a, b) => _double(b['amount']).compareTo(_double(a['amount'])));

        final sellers = bySeller.toList()
          ..sort((a, b) => _double(b['amount']).compareTo(_double(a['amount'])));

        return AdminSection(
          title: 'تفکیک فروش',
          icon: Icons.receipt_long_outlined,
          children: [
            AdminRow(
              label: 'مجموع',
              value: '${data['total_amount_formatted'] ?? _money(data['total_amount'])}',
              emphasise: true,
              color: AppColors.moneyIn,
            ),
            AdminRow(
              label: 'نان فروخته‌شده',
              value: '${_int(data['bread_count'])} عدد در ${_int(data['count'])} فقره',
            ),

            const Divider(height: 1),

            for (final type in types)
              AdminRow(
                label: '${type['label'] ?? '—'}',
                value: '${type['amount_formatted'] ?? _money(type['amount'])}'
                    ' · ${_int(type['bread_count'])} نان',
              ),

            // Only where there is more than one, because a shop with a
            // single seller already has this figure as the total above.
            if (sellers.length > 1) ...[
              const Divider(height: 1),
              for (final seller in sellers)
                AdminRow(
                  label: '${seller['seller'] ?? 'بدون نام'}',
                  value:
                      '${seller['amount_formatted'] ?? _money(seller['amount'])}',
                ),
            ],
          ],
        );
      },
    );
  }
}

int _int(dynamic value) =>
    value is num ? value.toInt() : int.tryParse('$value') ?? 0;

double _double(dynamic value) =>
    value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
