import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

typedef _Debts = ({
  List<Map<String, dynamic>> customers,
  String totalFormatted,
  int overdueCount,
});

/// What the schools and offices still owe, one figure per buyer.
///
/// Chasing a debt is a conversation with one school about one number, not
/// with a stack of separate receipts, so the sales are summed per customer
/// and the one that has been waiting longest comes first.
class CustomerDebtsSection extends StatefulWidget {
  const CustomerDebtsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<CustomerDebtsSection> createState() => _CustomerDebtsSectionState();
}

class _CustomerDebtsSectionState extends State<CustomerDebtsSection> {
  late Future<_Debts> _debts;

  @override
  void initState() {
    super.initState();
    _debts = widget.api.customerDebts();
  }

  void _reload() => setState(() => _debts = widget.api.customerDebts());

  Future<void> _settle(Map<String, dynamic> customer) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('تسویه بدهی ${customer['name']}'),
        content: Text(
          'کل بدهی ${customer['amount_formatted']} '
          '(${customer['sale_count']} فاکتور) دریافت شده است؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('تسویه شد'),
          ),
        ],
      ),
    );

    if (ok != true) return;

    try {
      await widget.api.settleCustomerDebt(customer['customer_id'] as int);
      if (!mounted) return;
      showMessage(context, 'بدهی تسویه شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<_Debts>(
      future: _debts,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }

        final data = snapshot.data!;

        if (data.customers.isEmpty) {
          return const AdminSection(
            title: 'بدهی معوقه مدارس و ادارات',
            icon: Icons.school_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'بدهی معوقه‌ای ثبت نشده است'),
            ],
          );
        }

        return AdminSection(
          title: 'بدهی معوقه مدارس و ادارات',
          icon: Icons.school_rounded,
          trailing: data.overdueCount > 0
              ? Text(
                  '${data.overdueCount} معوق',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: const Color(0xFFD1495B),
                        fontWeight: FontWeight.w700,
                      ),
                )
              : null,
          children: [
            for (final customer in data.customers)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _DebtTile(
                  customer: customer,
                  onSettle: () => _settle(customer),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
              child: AdminRow(
                label: 'جمع کل بدهی',
                value: data.totalFormatted,
                emphasise: true,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _DebtTile extends StatelessWidget {
  const _DebtTile({required this.customer, required this.onSettle});

  final Map<String, dynamic> customer;
  final VoidCallback onSettle;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final overdue = customer['is_overdue'] == true;
    final days = (customer['oldest_days'] as num?)?.toInt() ?? 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${customer['name']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                  Text(
                    '${customer['type_label']}'
                    '  •  ${customer['sale_count']} فاکتور'
                    '  •  ${customer['bread_count']} نان',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                ],
              ),
            ),
            Text(
              '${customer['amount_formatted']}',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: const Color(0xFFD1495B),
                  ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Icon(
              overdue
                  ? Icons.warning_amber_rounded
                  : Icons.schedule_rounded,
              size: 15,
              color: overdue ? const Color(0xFFD1495B) : scheme.onSurfaceVariant,
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                // How long the oldest unpaid receipt has been waiting is
                // what decides who gets called first.
                'از ${customer['oldest_date_display']} — $days روز'
                '${overdue ? ' (معوق)' : ''}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: overdue
                          ? const Color(0xFFD1495B)
                          : scheme.onSurfaceVariant,
                    ),
              ),
            ),
            TextButton(
              onPressed: onSettle,
              child: const Text('تسویه'),
            ),
          ],
        ),
        const Divider(height: 18),
      ],
    );
  }
}
