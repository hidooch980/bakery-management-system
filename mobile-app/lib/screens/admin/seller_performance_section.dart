import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';
import 'seller_detail_screen.dart';

class SellerPerformanceSection extends StatefulWidget {
  const SellerPerformanceSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerPerformanceSection> createState() =>
      _SellerPerformanceSectionState();
}

class _SellerPerformanceSectionState extends State<SellerPerformanceSection> {
  late Future<Map<String, dynamic>> _report;

  @override
  void initState() {
    super.initState();
    _report = widget.api.sellerPerformance();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'کارکرد فروشندگان',
            icon: Icons.storefront_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError || snapshot.data == null) {
          return const AdminSection(
            title: 'کارکرد فروشندگان',
            icon: Icons.storefront_rounded,
            children: [AdminRow(label: 'در دسترس نیست', value: '—')],
          );
        }

        final data = snapshot.data!;
        final sellers = rowList(data['sellers']).cast<Map<String, dynamic>>();

        if (sellers.isEmpty) {
          return const AdminSection(
            title: 'کارکرد فروشندگان',
            icon: Icons.storefront_rounded,
            children: [AdminRow(label: 'فروشنده‌ای ثبت نشده', value: '—')],
          );
        }

        final totals = keyedGroup(data['totals']);

        return AdminSection(
          title: 'کارکرد فروشندگان',
          icon: Icons.storefront_rounded,
          trailing: Text(
            '${data['from_jalali']} تا ${data['to_jalali']}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          children: [
            for (final seller in sellers)
              _SellerRow(
                  seller: seller,
                  api: widget.api,
                  onReturn: () =>
                      setState(() => _report = widget.api.sellerPerformance())),
            const Divider(height: 20),
            AdminRow(
              label: 'جمع نان فروخته‌شده',
              value: '${totals['bread_count'] ?? 0}',
              emphasise: true,
            ),
            AdminRow(
              label: 'جمع فروش',
              value: '${totals['amount_formatted'] ?? '—'}',
              emphasise: true,
            ),
          ],
        );
      },
    );
  }
}

class _SellerRow extends StatelessWidget {
  const _SellerRow(
      {required this.seller, required this.api, required this.onReturn});

  final VoidCallback onReturn;

  final Map<String, dynamic> seller;
  final BakeryApi api;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final breadCount = seller['bread_count'] as int? ?? 0;
    final shortfallCount = seller['shortfall_count'] as int? ?? 0;
    final outstanding = (seller['outstanding'] as num? ?? 0).toDouble();
    final creditOut = (seller['credit_out'] as num? ?? 0).toDouble();

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: InkWell(
        onTap: () async {
          await Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => SellerDetailScreen(
                api: api,
                sellerId: seller['id'] as int,
                sellerName: '${seller['name']}',
              ),
            ),
          );
          onReturn();
        },
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${seller['name']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  Text(
                    '$breadCount نان  ·  ${seller['days_active']} روز کاری',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                  if (shortfallCount > 0)
                    Text(
                      'کسری: $shortfallCount نان — ${seller['shortfall_formatted']}',
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: AppColors.attention),
                    ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  '${seller['amount_formatted']}',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                if (outstanding > 0)
                  Text(
                    'دست او: ${seller['outstanding_formatted']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                if (creditOut > 0)
                  Text(
                    'نسیه: ${seller['credit_out_formatted']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
              ],
            ),
            const SizedBox(width: 4),
            Icon(Icons.chevron_left_rounded,
                size: IconSize.row, color: scheme.onSurfaceVariant),
          ],
        ),
      ),
    );
  }
}
