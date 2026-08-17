import 'package:flutter/material.dart';

import '../services/api_client.dart';
import '../services/bakery_api.dart';
import '../utils/formatters.dart';
import 'common.dart';
import '../theme/app_theme.dart';

typedef _Collections = ({
  List<Map<String, dynamic>> customers,
  String totalFormatted,
});

/// What the schools, offices and dormitories owe this seller, and the money
/// they hand back.
///
/// The seller delivers to them and is the one they pay, so the account
/// belongs on the seller's own screen — otherwise they collect without
/// knowing the balance, or chase a debt that was already settled.
class SellerCollectionsCard extends StatefulWidget {
  const SellerCollectionsCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerCollectionsCard> createState() => _SellerCollectionsCardState();
}

class _SellerCollectionsCardState extends State<SellerCollectionsCard> {
  _Collections? _data;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.myCollections();
      if (mounted) setState(() => _data = data);
    } on ApiException {
      // The rest of the day's work does not depend on this card.
    }
  }

  Future<void> _collect(Map<String, dynamic> customer) async {
    final field = TextEditingController(
      text: (customer['owed'] as num?)?.toStringAsFixed(0) ?? '',
    );

    // How the money arrived decides where it lands: cash stays in the till,
    // a card payment is already in the bank.
    var method = 'cash';

    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setSheetState) => AlertDialog(
          title: Text('دریافت از ${customer['name']}'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('بدهی: ${customer['owed_formatted']}'),
              const SizedBox(height: 12),
              TextField(
                controller: field,
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: const [GroupedAmountInputFormatter()],
                decoration: const InputDecoration(labelText: 'مبلغ دریافتی'),
              ),
              const SizedBox(height: 16),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(
                    value: 'cash',
                    label: Text('نقد'),
                    icon: Icon(Icons.payments_rounded, size: IconSize.row),
                  ),
                  ButtonSegment(
                    value: 'card',
                    label: Text('کارتخوان'),
                    icon: Icon(Icons.credit_card_rounded, size: IconSize.row),
                  ),
                ],
                selected: {method},
                onSelectionChanged: (selection) =>
                    setSheetState(() => method = selection.first),
                showSelectedIcon: false,
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('انصراف'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('ثبت دریافت'),
            ),
          ],
        ),
      ),
    );

    final amount = MoneyFormat.parseInput(field.text.trim()) ?? 0;

    if (ok != true || amount <= 0) return;

    try {
      final queued = await widget.api.collectFromCustomer(
        customer['customer_id'] as int,
        amount,
        method: method,
      );
      if (!mounted) return;
      // Told plainly when it is only on the phone so far: the seller is
      // standing in front of the customer and needs to know whether the
      // shop has it yet.
      showMessage(
        context,
        queued
            ? 'دریافت ذخیره شد و با وصل شدن اینترنت ارسال می‌شود.'
            : 'دریافت ثبت شد.',
      );
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;

    // No accounts to collect on is not something to take up space over.
    if (data == null || data.customers.isEmpty) {
      return const SizedBox.shrink();
    }

    final scheme = Theme.of(context).colorScheme;
    const accent = AppColors.moneyNeutral;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: accent.withValues(alpha: 0.30)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_rounded, size: IconSize.button, color: accent),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حساب مدارس، ادارات و خوابگاه',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              Text(
                data.totalFormatted,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: accent,
                    ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          for (final customer in data.customers)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${customer['name']}  •  ${customer['type_label']}',
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ),
                      Text(
                        '${customer['owed_formatted']}',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w800,
                              color: AppColors.moneyOut,
                            ),
                      ),
                    ],
                  ),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          // What has already come back, so the account
                          // reads as moving rather than only as a debt.
                          'دریافت‌شده ${customer['collected_formatted']}'
                          '${customer['oldest_display'] != null ? '  •  از ${customer['oldest_display']}' : ''}',
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: scheme.onSurfaceVariant,
                              ),
                        ),
                      ),
                      TextButton(
                        onPressed: () => _collect(customer),
                        child: const Text('ثبت دریافت'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
