import 'package:flutter/material.dart';

import '../../models/balance_sheet.dart';
import '../../services/bakery_api.dart';
import 'admin_home_screen.dart';
import '../../theme/app_theme.dart';

/// What the shop owns against what it owes.
///
/// The rest of the finance page answers what came in and what went out.
/// This answers the other question — what the shop is actually worth — and
/// it was the one thing only the web panel could say.
class BalanceSheetSection extends StatefulWidget {
  const BalanceSheetSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<BalanceSheetSection> createState() => _BalanceSheetSectionState();
}

class _BalanceSheetSectionState extends State<BalanceSheetSection> {
  late Future<BalanceSheet> _sheet;

  static const _ownColour = AppColors.moneyIn;
  static const _oweColour = AppColors.moneyOut;

  @override
  void initState() {
    super.initState();
    _sheet = widget.api.balanceSheet();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<BalanceSheet>(
      future: _sheet,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'تراز مالی',
            icon: Icons.balance_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError) {
          return const SizedBox.shrink();
        }

        final sheet = snapshot.data!;

        if (sheet.isEmpty) {
          return const AdminSection(
            title: 'تراز مالی',
            icon: Icons.balance_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'هنوز چیزی برای ترازکردن نیست'),
            ],
          );
        }

        return AdminSection(
          title: 'تراز مالی',
          icon: Icons.balance_rounded,
          children: [
            for (final line in sheet.assets)
              AdminRow(
                label: line.note == null ? line.label : '${line.label} (${line.note})',
                value: line.amountFormatted,
              ),
            AdminRow(
              label: 'جمع دارایی',
              value: sheet.assetTotalFormatted,
              color: _ownColour,
              emphasise: true,
            ),

            if (sheet.liabilities.isNotEmpty) ...[
              const Divider(height: 1),
              for (final line in sheet.liabilities)
                AdminRow(
                  label: line.note == null ? line.label : '${line.label} (${line.note})',
                  value: line.amountFormatted,
                ),
              AdminRow(
                label: 'جمع بدهی',
                value: sheet.liabilityTotalFormatted,
                color: _oweColour,
                emphasise: true,
              ),
            ],

            const Divider(height: 1),
            AdminRow(
              // Owing more than is held is said in words, not left to be
              // read off a minus sign.
              label: sheet.isSolvent ? 'سرمایه خالص' : 'کسری سرمایه',
              value: sheet.equityFormatted,
              icon: sheet.isSolvent
                  ? Icons.trending_up_rounded
                  : Icons.warning_amber_rounded,
              color: sheet.isSolvent ? _ownColour : _oweColour,
              emphasise: true,
            ),

            if (sheet.asOf != null)
              AdminRow(label: 'به تاریخ', value: sheet.asOf!),

            // Named rather than summed, because "دارایی ثابت: ۰" is a
            // question — is there no oven, or has nobody written it down?
            if (sheet.fixedAssets.isNotEmpty) ...[
              const Divider(height: 1),
              for (final asset in sheet.fixedAssets)
                AdminRow(
                  label: asset.categoryLabel == null
                      ? asset.title
                      : '${asset.title}  •  ${asset.categoryLabel}',
                  value: asset.valueFormatted,
                ),
            ],

            if (sheet.loans.isNotEmpty) ...[
              const Divider(height: 1),
              for (final loan in sheet.loans)
                AdminRow(
                  label: _loanLabel(loan),
                  value: loan.remainingFormatted,
                  // Overdue is the one loan state worth acting on today.
                  color: loan.isOverdue ? _oweColour : null,
                ),
            ],
          ],
        );
      },
    );
  }

  String _loanLabel(LoanLine loan) {
    final parts = <String>[
      loan.title,
      if (loan.lender != null && loan.lender!.trim().isNotEmpty) loan.lender!,
      if (loan.isOverdue && loan.nextDueOn != null)
        'سررسید گذشته: ${loan.nextDueOn}'
      else if (loan.nextDueOn != null)
        'قسط بعدی: ${loan.nextDueOn}',
    ];

    return parts.join('  •  ');
  }
}
