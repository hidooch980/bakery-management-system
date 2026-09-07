import 'package:flutter/material.dart';

import '../../models/purchase.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// The invoices this person wrote down, and which of them are still owed.
///
/// Whoever takes the delivery at the door is the one who knows what came
/// off the lorry, and until now they had no way of seeing what they had
/// entered — the list lived on the owner's panel. So «آن بار آرد را ثبت
/// کردم یا نه» was answered by entering it again.
class MyPurchasesScreen extends StatefulWidget {
  const MyPurchasesScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<MyPurchasesScreen> createState() => _MyPurchasesScreenState();
}

class _MyPurchasesScreenState extends State<MyPurchasesScreen> {
  late Future<List<Purchase>> _purchases;

  @override
  void initState() {
    super.initState();
    _purchases = widget.api.myPurchases();
  }

  void _reload() => setState(() => _purchases = widget.api.myPurchases());

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('خریدهای من')),
      body: FutureBuilder<List<Purchase>>(
        future: _purchases,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
          }

          final purchases = snapshot.data ?? const <Purchase>[];

          if (purchases.isEmpty) {
            return RefreshIndicator(
              onRefresh: () async => _reload(),
              child: ListView(
                children: const [
                  SizedBox(height: 80),
                  EmptyState(
                    icon: Icons.receipt_rounded,
                    title: 'خریدی ثبت نکرده‌اید',
                    subtitle: 'هر فاکتوری که وارد کنید اینجا می‌ماند.',
                  ),
                ],
              ),
            );
          }

          final unpaid = purchases.where((p) => !p.isSettled).length;

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: purchases.length + 1,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                if (index == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: _Summary(total: purchases.length, unpaid: unpaid),
                  );
                }

                return _PurchaseTile(purchase: purchases[index - 1]);
              },
            ),
          );
        },
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.total, required this.unpaid});

  final int total;
  final int unpaid;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final owing = unpaid > 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(
              Icons.receipt_rounded,
              color: owing ? AppColors.attention : AppColors.moneyIn,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                // Unpaid first when there are any: an invoice still owed
                // is the thing worth acting on, and the count of all of
                // them is only context.
                owing
                    ? '$unpaid فاکتور تسویه‌نشده از $total'
                    : '$total فاکتور، همه تسویه شده',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: scheme.onSurface,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PurchaseTile extends StatelessWidget {
  const _PurchaseTile({required this.purchase});

  final Purchase purchase;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    // An invoice from a supplier already on file arrives with only an id,
    // so the mill's name can be empty. The number identifies it then.
    final title = purchase.supplierName.trim().isNotEmpty
        ? purchase.supplierName
        : (purchase.invoiceNo?.trim().isNotEmpty ?? false)
            ? 'فاکتور ${purchase.invoiceNo}'
            : 'فاکتور #${purchase.id}';

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: scheme.onSurface,
                    ),
                  ),
                ),
                Text(
                  purchase.amountFormatted,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurface,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: Text(
                    purchase.purchasedOnDisplay,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
                Text(
                  purchase.isSettled
                      ? 'تسویه شده'
                      : 'مانده ${purchase.outstandingFormatted}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: purchase.isSettled
                        ? AppColors.moneyIn
                        : AppColors.attention,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
            if (purchase.lines.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                purchase.lines.map((l) => l.label).join('، '),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
