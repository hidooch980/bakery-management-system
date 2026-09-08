import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import 'admin_home_screen.dart';

/// «سود و زیان» — the whole statement, in the order that explains it.
///
/// The panel has had this since it was written. The phone had the halves:
/// income on one screen, expenses on another, profit in a widget, and
/// never the sum.
///
/// The order is the point. On 2026-08-16 the dashboard and the report
/// disagreed about profit by 164,640,000 Rial because flour was counted
/// both as cost of goods and as an expense — two screens each showing
/// half a sum. One statement is how that gets caught, so income, costs and
/// profit are on one card and are meant to be read down.
///
/// Every figure comes formatted from the server. The shop's currency lives
/// there, and a screen that formatted money itself would be the second
/// place deciding what a number means.
class ProfitAndLossSection extends StatefulWidget {
  const ProfitAndLossSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
  });

  final BakeryApi api;
  final String from;
  final String to;

  @override
  State<ProfitAndLossSection> createState() => _ProfitAndLossSectionState();
}

class _ProfitAndLossSectionState extends State<ProfitAndLossSection> {
  late Future<Map<String, dynamic>> _statement;

  @override
  void initState() {
    super.initState();
    _statement = _load();
  }

  @override
  void didUpdateWidget(ProfitAndLossSection old) {
    super.didUpdateWidget(old);

    if (old.from != widget.from || old.to != widget.to) {
      setState(() => _statement = _load());
    }
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.profitAndLoss(from: widget.from, to: widget.to);

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _statement,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'سود و زیان',
            icon: Icons.account_balance_wallet_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // The rest of the tab is what this screen is for; a statement that
        // cannot be read stays away rather than sitting there as an error.
        if (snapshot.hasError || snapshot.data == null) {
          return const SizedBox.shrink();
        }

        final data = snapshot.data!;
        final income = keyedGroup(data['income']);
        final costs = rowList(data['costs']);
        final profit = (data['profit'] as num?)?.toDouble() ?? 0;

        return AdminSection(
          title: 'سود و زیان',
          icon: Icons.account_balance_wallet_rounded,
          children: [
            // Income first, because it is the only line the shop can grow.
            const _Heading('درآمد'),
            AdminRow(
              label: 'فروش نان',
              value: '${income['bread_formatted'] ?? '—'}',
            ),
            AdminRow(
              label: 'فروش آرد',
              value: '${income['flour_formatted'] ?? '—'}',
            ),
            AdminRow(
              label: 'درآمد متفرقه',
              value: '${income['other_formatted'] ?? '—'}',
            ),
            AdminRow(
              label: 'جمع درآمد',
              value: '${data['income_total_formatted'] ?? '—'}',
              emphasise: true,
              color: AppColors.moneyIn,
            ),

            const _Heading('پرداختی‌ها'),
            for (final row in costs)
              AdminRow(
                label: '${row['label'] ?? '—'}',
                value: '${row['amount_formatted'] ?? '—'}',
              ),
            AdminRow(
              label: 'جمع پرداختی',
              value: '${data['expense_total_formatted'] ?? '—'}',
              emphasise: true,
              color: AppColors.moneyOut,
            ),

            const Divider(height: 20),
            AdminRow(
              label: profit < 0 ? 'زیان دوره' : 'سود دوره',
              value: '${data['profit_formatted'] ?? '—'}',
              emphasise: true,
              // A loss is not a smaller profit. It is the one line on this
              // card that has to be impossible to skim past.
              color: profit < 0 ? AppColors.moneyOut : AppColors.moneyIn,
            ),

            Padding(
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
              child: Text(
                'سود بالا یعنی درآمد منهای هر پولی که از حساب خارج شده.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),

            // Beside the profit, never instead of it: cost of goods counts
            // flour as it is baked rather than as it is bought, so in any
            // period where the two do not line up it disagrees with the
            // headline — on purpose, and it says so.
            const _Heading('به روش بهای تمام‌شده'),
            AdminRow(
              label: 'بهای آردِ مصرف‌شده',
              value: '${data['cogs_formatted'] ?? '—'}',
            ),
            AdminRow(
              label: 'سود ناخالص',
              value: '${data['gross_profit_formatted'] ?? '—'}',
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 14),
              child: Text(
                'این دو، آرد را روزی که پخته شده حساب می‌کنند نه روزی که'
                ' خریده شده. پس با سود بالا یکی نیستند و نباید باشند.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 4),
      child: Text(
        text,
        style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
              color: Theme.of(context).colorScheme.onSurface,
            ),
      ),
    );
  }
}
