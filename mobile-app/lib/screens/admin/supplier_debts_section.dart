import 'package:flutter/material.dart';

import '../../models/purchase.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

typedef _Debts = ({List<SupplierBalance> suppliers, String totalOwedFormatted});

/// What the shop owes each mill.
///
/// The other side of the schools' debt, and until now the only one with no
/// answer in the system at all: flour has been bought on credit since the
/// shop opened, and the record of what was owed lived in the mill's book.
///
/// Paying is one figure against one supplier — that is how the shop
/// actually settles, a round number on account rather than invoice by
/// invoice — so that is what the button asks for.
class SupplierDebtsSection extends StatefulWidget {
  const SupplierDebtsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SupplierDebtsSection> createState() => _SupplierDebtsSectionState();
}

class _SupplierDebtsSectionState extends State<SupplierDebtsSection> {
  late Future<_Debts> _debts;

  @override
  void initState() {
    super.initState();
    _debts = widget.api.supplierBalances();
  }

  void _reload() => setState(() => _debts = widget.api.supplierBalances());

  Future<void> _pay(SupplierBalance supplier) async {
    final controller = TextEditingController();

    final amount = await showDialog<double>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('پرداخت به ${supplier.name}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('مانده بدهی: ${supplier.balanceFormatted}'),
            const SizedBox(height: 14),
            TextField(
              controller: controller,
              keyboardType: TextInputType.number,
              autofocus: true,
              decoration: const InputDecoration(labelText: 'مبلغ پرداختی'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () {
              final value = double.tryParse(controller.text.trim());

              if (value == null || value <= 0) return;

              Navigator.pop(dialogContext, value);
            },
            child: const Text('ثبت پرداخت'),
          ),
        ],
      ),
    );

    controller.dispose();

    if (amount == null) return;

    try {
      await widget.api.paySupplier(supplierId: supplier.id, amount: amount);
      if (!mounted) return;
      showMessage(context, 'پرداخت ثبت شد.');
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

        if (data.suppliers.isEmpty) {
          return const AdminSection(
            title: 'بدهی به تأمین‌کنندگان',
            icon: Icons.local_shipping_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'با همه تسویه است'),
            ],
          );
        }

        return AdminSection(
          title: 'بدهی به تأمین‌کنندگان',
          icon: Icons.local_shipping_rounded,
          children: [
            for (final supplier in data.suppliers)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _SupplierTile(
                  supplier: supplier,
                  onPay: () => _pay(supplier),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
              child: AdminRow(
                label: 'جمع بدهی',
                value: data.totalOwedFormatted,
                emphasise: true,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _SupplierTile extends StatelessWidget {
  const _SupplierTile({required this.supplier, required this.onPay});

  final SupplierBalance supplier;
  final VoidCallback onPay;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                supplier.name,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              Text(
                supplier.unpaidInvoices == 0
                    ? '${supplier.invoices} فاکتور'
                    : '${supplier.unpaidInvoices} فاکتور پرداخت‌نشده',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              supplier.balanceFormatted,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w800,
                // A mill holding the shop's money is shown as that rather
                // than as a debt with a minus in front of it.
                color: supplier.weOwe ? AppColors.moneyOut : AppColors.moneyIn,
              ),
            ),
            if (!supplier.weOwe)
              Text('پیش‌پرداخت', style: theme.textTheme.bodySmall),
          ],
        ),
        if (supplier.weOwe)
          IconButton(
            onPressed: onPay,
            tooltip: 'ثبت پرداخت',
            icon: const Icon(Icons.payments_rounded),
          ),
      ],
    );
  }
}
