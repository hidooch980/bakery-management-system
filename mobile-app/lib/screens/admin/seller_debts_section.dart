import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// What each seller still owes, and the requests waiting on an answer.
///
/// The panel shows the same thing, but settling happens when the money
/// actually changes hands — on the shop floor, with a phone — so the admin
/// can confirm or refuse a handover right there.
class SellerDebtsSection extends StatefulWidget {
  const SellerDebtsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerDebtsSection> createState() => _SellerDebtsSectionState();
}

class _SellerDebtsSectionState extends State<SellerDebtsSection> {
  late Future<List<Map<String, dynamic>>> _sellers;

  @override
  void initState() {
    super.initState();
    _sellers = widget.api.sellerAccounts();
  }

  void _reload() => setState(() => _sellers = widget.api.sellerAccounts());

  Future<void> _run(Future<void> Function() action, String done) async {
    try {
      await action();
      if (!mounted) return;
      showMessage(context, done);
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _confirm(Map<String, dynamic> seller) async {
    final request = seller['request'] as Map<String, dynamic>;

    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('تأیید تسویه ${seller['name']}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('مبلغ کل: ${request['amount_formatted']}'),
            const SizedBox(height: 6),
            Text('تحویل نقدی: ${request['paid_cash_formatted']}'),
            Text('کارتخوان: ${request['paid_card_formatted']}'),
            if (request['note'] != null) ...[
              const SizedBox(height: 8),
              Text('توضیح فروشنده: ${request['note']}'),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('تأیید تسویه'),
          ),
        ],
      ),
    );

    if (ok != true) return;

    // The card share posts itself to the default bank account server-side,
    // so the admin only has to say yes.
    await _run(
      () => widget.api.confirmSettlement(request['id'] as int),
      'تسویه تأیید شد.',
    );
  }

  Future<void> _reject(Map<String, dynamic> seller) async {
    final request = seller['request'] as Map<String, dynamic>;
    final reason = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('رد درخواست تسویه'),
        content: TextField(
          controller: reason,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'دلیل رد',
            hintText: 'برای فروشنده نمایش داده می‌شود',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('رد کن'),
          ),
        ],
      ),
    );

    if (ok != true || reason.text.trim().isEmpty) return;

    await _run(
      () => widget.api.rejectSettlement(request['id'] as int, reason.text.trim()),
      'درخواست رد شد.',
    );
  }

  Future<void> _settleDirect(Map<String, dynamic> seller) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('تسویه ${seller['name']}'),
        content: Text(
          'مبلغ ${seller['settleable_formatted']} را از این فروشنده تحویل گرفته‌اید؟'
          '\nنسیه‌ها با پرداخت مشتری تسویه می‌شوند و در این مبلغ نیستند.',
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

    await _run(
      () => widget.api.settleSeller(seller['id'] as int),
      'حساب تسویه شد.',
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _sellers,
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

        final sellers = snapshot.data!;

        // Nothing owed anywhere is good news, not an empty state to
        // apologise for — the section simply says so and stays small.
        if (sellers.isEmpty) {
          return const AdminSection(
            title: 'حساب فروشندگان',
            icon: Icons.account_balance_wallet_rounded,
            children: [
              AdminRow(
                label: 'وضعیت',
                value: 'حساب همه فروشندگان تسویه است',
              ),
            ],
          );
        }

        return AdminSection(
          title: 'حساب فروشندگان',
          icon: Icons.account_balance_wallet_rounded,
          children: [
            for (final seller in sellers)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _SellerTile(
                seller: seller,
                onConfirm: () => _confirm(seller),
                onReject: () => _reject(seller),
                  onSettle: () => _settleDirect(seller),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _SellerTile extends StatelessWidget {
  const _SellerTile({
    required this.seller,
    required this.onConfirm,
    required this.onReject,
    required this.onSettle,
  });

  final Map<String, dynamic> seller;
  final VoidCallback onConfirm;
  final VoidCallback onReject;
  final VoidCallback onSettle;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final request = seller['request'] as Map<String, dynamic>?;
    final settleable = (seller['settleable'] as num?)?.toDouble() ?? 0;
    final credit = (seller['credit'] as num?)?.toDouble() ?? 0;

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${seller['name']}',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w800),
                ),
              ),
              Text(
                '${seller['settleable_formatted']}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: settleable > 0
                          ? AppColors.attention
                          : scheme.onSurfaceVariant,
                    ),
              ),
            ],
          ),
          if (credit > 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                // Credit is the customer's debt, not the seller's, so it
                // is shown apart from what they can hand over.
                'نسیه وصول‌نشده: ${seller['credit_formatted']}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
              ),
            ),

          const SizedBox(height: 8),
          if (request != null) ...[
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppColors.moneyNeutral.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'درخواست تسویه ${request['amount_formatted']}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: AppColors.moneyNeutral,
                        ),
                  ),
                  Text(
                    'نقد ${request['paid_cash_formatted']}'
                    '  •  کارتخوان ${request['paid_card_formatted']}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: onConfirm,
                    icon: const Icon(Icons.check_rounded, size: 18),
                    label: const Text('تأیید'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: onReject,
                    icon: const Icon(Icons.close_rounded, size: 18),
                    label: const Text('رد'),
                  ),
                ),
              ],
            ),
          ] else if (settleable > 0)
            OutlinedButton.icon(
              onPressed: onSettle,
              icon: const Icon(Icons.handshake_rounded, size: 18),
              label: const Text('ثبت تسویه'),
            ),
          const Divider(height: 22),
        ],
      ),
    );
  }
}
