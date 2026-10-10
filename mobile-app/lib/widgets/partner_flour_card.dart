import 'package:flutter/material.dart';

import '../screens/shared/consignment_flour_screen.dart';
import '../screens/shared/partner_statement_screen.dart' show bags;
import '../services/bakery_api.dart';
import '../theme/app_theme.dart';
import '../utils/json.dart';

/// خلاصهٔ آرد امانی همکاران روی صفحهٔ خانه، همان سه عدد داشبورد پنل:
/// طلب ما، بدهی ما، و خالص — به کیسه. با زدن، فهرست همکاران باز می‌شود.
///
/// جدا بار می‌شود و اگر نیامد چیزی نشان نمی‌دهد؛ یک فراخوانی فرعی نباید
/// بقیهٔ صفحهٔ خانه را با خودش ببرد.
class PartnerFlourCard extends StatefulWidget {
  const PartnerFlourCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<PartnerFlourCard> createState() => _PartnerFlourCardState();
}

class _PartnerFlourCardState extends State<PartnerFlourCard> {
  late Future<Map<String, dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.api.consignmentBalance();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snapshot) {
        final data = snapshot.data;
        if (snapshot.hasError ||
            data == null ||
            data['owed_to_us_bags'] == null) {
          return const SizedBox.shrink();
        }

        return PartnerFlourTotals(
          data: data,
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => ConsignmentFlourScreen(api: widget.api),
            ),
          ),
        );
      },
    );
  }
}

/// نمای خالصِ کارت؛ جدا تا بی‌شبکه هم آزمون شود.
class PartnerFlourTotals extends StatelessWidget {
  const PartnerFlourTotals({super.key, required this.data, this.onTap});

  final Map<String, dynamic> data;
  final VoidCallback? onTap;

  static double _num(dynamic v) =>
      v is num ? v.toDouble() : double.tryParse('$v') ?? 0;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final owed = _num(data['owed_to_us_bags']);
    final owe = _num(data['we_owe_bags']);
    final net = _num(data['partners_net_bags']);
    final headline = '${keyedGroup(data['headline'])['label'] ?? ''}';
    final netColor = net > 0.001
        ? AppColors.moneyIn
        : net < -0.001
            ? AppColors.moneyOut
            : scheme.onSurfaceVariant;

    Widget figure(String label, String value, Color color) => Expanded(
          child: Column(
            children: [
              Text(
                value,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: color,
                    ),
              ),
              const SizedBox(height: 2),
              Text(
                label,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
              ),
            ],
          ),
        );

    return Card(
      key: const ValueKey('partner-flour-card'),
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Icon(Icons.storefront_rounded,
                      size: IconSize.row, color: scheme.onSurfaceVariant),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'آرد امانی همکاران',
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                  ),
                  Icon(Icons.chevron_left_rounded,
                      size: IconSize.row, color: scheme.onSurfaceVariant),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  figure('طلب ما', '${bags(owed)} کیسه', AppColors.moneyIn),
                  figure('بدهی ما', '${bags(owe)} کیسه', AppColors.moneyOut),
                  figure(
                      'خالص',
                      headline
                          .replaceFirst('طلب ما: ', '')
                          .replaceFirst('بدهی ما: ', ''),
                      netColor),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                headline,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: netColor,
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
