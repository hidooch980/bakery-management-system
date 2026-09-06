import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';
import 'seller_detail_screen.dart';

/// What each seller sold this quota period, busiest first.
///
/// The admin app showed a seller in exactly one place — [SellerDebtsSection],
/// the list of what is outstanding — and that list drops anybody at zero.
/// So the seller who sells all day and hands the money over the same
/// evening appeared nowhere at all, and the only sellers the owner ever
/// saw were the ones who were behind.
///
/// The server has had `by_seller` in reports/sales since the beginning and
/// nothing has ever drawn it. This is the same question asked properly,
/// on the screen the owner is already holding.
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
        final sellers = (data['sellers'] as List? ?? const [])
            .cast<Map<String, dynamic>>();

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
          // The period, said out loud. This defaults to the quota period
          // and not the calendar month, and a figure whose window the
          // reader has to guess at is one they will read wrong.
          trailing: Text(
            '${data['from_jalali']} تا ${data['to_jalali']}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          children: [
            for (final seller in sellers) _SellerRow(seller: seller, api: widget.api),
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
  const _SellerRow({required this.seller, required this.api});

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
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute<void>(
            builder: (_) => SellerDetailScreen(
              api: api,
              sellerId: seller['id'] as int,
              sellerName: '${seller['name']}',
            ),
          ),
        ),
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

                  // Shortfall gets its own line and its own colour rather
                  // than being folded into the takings. Bread that left
                  // with no money behind it is a different fact from a
                  // quiet week, and it is the one worth asking about.
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
                // Only when there is something to say. A zero on every row
                // is noise, and noise is what makes the one row that
                // matters easy to scroll past.
                if (outstanding > 0)
                  Text(
                    'دست او: ${seller['outstanding_formatted']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),

                // Bread let out on trust. A different fact from money he
                // is holding, and kept apart for that reason.
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
