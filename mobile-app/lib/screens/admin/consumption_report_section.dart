import 'package:flutter/material.dart';
import '../../utils/json.dart';

import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'admin_home_screen.dart';

/// Where the flour went: into bread, or out of the door as flour.
///
/// The quota is issued against baking, so flour sold on is the figure that
/// decides whether a month's usage makes sense — and the two were nowhere
/// side by side on the phone. `/reports/consumption-series` has answered
/// this since the reports page was written and nothing on the handset
/// asked it.
///
/// Salt and yeast are here for the same reason they are on the panel: they
/// are bought against the flour, and a month where the flour rose and the
/// salt did not is a month where something was not recorded.
class ConsumptionReportSection extends StatefulWidget {
  const ConsumptionReportSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
    required this.granularity,
  });

  final BakeryApi api;
  final String from;
  final String to;
  final String granularity;

  @override
  State<ConsumptionReportSection> createState() =>
      _ConsumptionReportSectionState();
}

class _ConsumptionReportSectionState extends State<ConsumptionReportSection> {
  late Future<Map<String, dynamic>> _report;

  @override
  void initState() {
    super.initState();
    _report = _load();
  }

  @override
  void didUpdateWidget(ConsumptionReportSection oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.from != widget.from ||
        oldWidget.to != widget.to ||
        oldWidget.granularity != widget.granularity) {
      setState(() => _report = _load());
    }
  }

  Future<Map<String, dynamic>> _load() => widget.api.consumptionSeries(
        from: widget.from,
        to: widget.to,
        granularity: widget.granularity,
      );

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _report,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'مصرف',
            icon: Icons.inventory_2_outlined,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError) return const SizedBox.shrink();

        final totals =
            keyedGroup(snapshot.data!['totals']);

        final used = _double(totals['flour_used_kg']);
        final sold = _double(totals['flour_sold_kg']);

        if (used == 0 && sold == 0) {
          return const AdminSection(
            title: 'مصرف',
            icon: Icons.inventory_2_outlined,
            children: [
              AdminRow(label: 'وضعیت', value: 'در این بازه آردی مصرف نشد'),
            ],
          );
        }

        return AdminSection(
          title: 'مصرف',
          icon: Icons.inventory_2_outlined,
          children: [
            AdminRow(
              label: 'آرد خمیر شده',
              value: '${_num(totals['bags_kneaded'])} کیسه',
              emphasise: true,
            ),
            AdminRow(label: 'آرد مصرف‌شده', value: '${_num(used)} کیلوگرم'),

            // The one that changes what the rest mean. Flour sold on left
            // the store without becoming bread, so it must not be read as
            // baking — which is exactly what a single «مصرف» figure invites.
            if (sold > 0)
              AdminRow(
                label: 'آرد فروخته‌شده',
                value: '${_num(sold)} کیلوگرم',
                color: AppColors.attention,
              ),

            if (_double(totals['salt_kg']) > 0)
              AdminRow(label: 'نمک', value: '${_num(totals['salt_kg'])} کیلوگرم'),
          ],
        );
      },
    );
  }
}

double _double(dynamic value) =>
    value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

/// Flour is weighed to the gram and a trailing «٫۰۰» on a round sack count
/// is noise, so a whole number prints whole and a fraction keeps two.
String _num(dynamic value) {
  final number = _double(value);

  return number == number.roundToDouble()
      ? number.toStringAsFixed(0)
      : number.toStringAsFixed(2);
}
