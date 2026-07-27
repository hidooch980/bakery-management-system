import 'package:flutter/material.dart';

import '../models/seller_account.dart';
import '../services/api_client.dart';
import '../services/bakery_api.dart';

/// What the seller still answers for, shown to them rather than only to the
/// admin — so a shortfall or an uncollected debt is something they can act
/// on the same day instead of hearing about at the end of the month.
///
/// There is no settle button here on purpose: clearing your own debt is the
/// admin's call, not the debtor's.
class SellerAccountCard extends StatefulWidget {
  const SellerAccountCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerAccountCard> createState() => _SellerAccountCardState();
}

class _SellerAccountCardState extends State<SellerAccountCard> {
  SellerAccount? _account;
  bool _expanded = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final account = await widget.api.myAccount();
      if (mounted) setState(() => _account = account);
    } on ApiException {
      // The rest of the day's work does not depend on this card.
    }
  }

  @override
  Widget build(BuildContext context) {
    final account = _account;

    // Nothing owed is worth saying nothing about — the card stays away.
    if (account == null || account.isClear) return const SizedBox.shrink();

    final scheme = Theme.of(context).colorScheme;
    const warn = Color(0xFFE8952D);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: warn.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: warn.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_wallet_rounded,
                  size: 20, color: warn),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حساب موقت شما',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              Text(
                account.totalFormatted,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: warn,
                    ),
              ),
            ],
          ),

          const SizedBox(height: 10),
          if (account.cash > 0)
            _AccountLine(
              icon: Icons.payments_rounded,
              label: 'پول نقد نزد شما',
              value: account.cashFormatted,
            ),
          if (account.hasDifference)
            _AccountLine(
              icon: Icons.error_outline_rounded,
              label: 'اختلاف مالی',
              value: account.differenceFormatted,
              isProblem: true,
            ),
          if (account.hasShortfall)
            _AccountLine(
              icon: Icons.remove_shopping_cart_rounded,
              label: 'کسری ${account.shortfallCount} نان',
              value: account.shortfallFormatted,
              isProblem: true,
            ),
          if (account.hasCredit)
            _AccountLine(
              icon: Icons.schedule_rounded,
              label: 'نسیه وصول‌نشده',
              value: account.creditFormatted,
            ),

          if (account.creditSales.isNotEmpty) ...[
            const SizedBox(height: 6),
            InkWell(
              onTap: () => setState(() => _expanded = !_expanded),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      _expanded ? 'بستن فهرست نسیه' : 'فهرست نسیه‌ها',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.primary,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    Icon(
                      _expanded
                          ? Icons.keyboard_arrow_up_rounded
                          : Icons.keyboard_arrow_down_rounded,
                      size: 18,
                      color: scheme.primary,
                    ),
                  ],
                ),
              ),
            ),
            if (_expanded)
              for (final sale in account.creditSales)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${sale.customer ?? 'بدون مشتری'}'
                          '  •  ${sale.breadCount} نان',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      Text(
                        sale.amountFormatted,
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
          ],

          const SizedBox(height: 4),
          Text(
            'تسویه با مدیر انجام می‌شود.',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

class _AccountLine extends StatelessWidget {
  const _AccountLine({
    required this.icon,
    required this.label,
    required this.value,
    this.isProblem = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final bool isProblem;

  @override
  Widget build(BuildContext context) {
    final color = isProblem
        ? const Color(0xFFD1495B)
        : Theme.of(context).colorScheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color),
            ),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: color,
                ),
          ),
        ],
      ),
    );
  }
}
