import 'package:flutter/material.dart';

import '../../models/bank_account.dart';
import '../../services/bakery_api.dart';
import 'admin_home_screen.dart';
import 'bank_statement_screen.dart';
import '../../theme/app_theme.dart';

/// What is in the shop's bank accounts.
///
/// Card takings are banked as they are settled, so this is where the money
/// the shop has actually collected ends up — but until now it could only be
/// seen from the web panel, and the admin standing on the shop floor with a
/// phone had no way to check it.
///
/// Read-only on purpose: moving money between accounts is a deliberate act
/// with a paper trail, and the panel is the place for it.
class BankBalancesSection extends StatefulWidget {
  const BankBalancesSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<BankBalancesSection> createState() => _BankBalancesSectionState();
}

class _BankBalancesSectionState extends State<BankBalancesSection> {
  late Future<BankBalances> _balances;

  @override
  void initState() {
    super.initState();
    _balances = widget.api.bankBalances();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<BankBalances>(
      future: _balances,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'موجودی بانک',
            icon: Icons.account_balance_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        // A shop that has not registered an account is not in trouble, and
        // a failed read is not either — the section simply stays away
        // rather than putting an error where a figure should be.
        if (snapshot.hasError) {
          return const SizedBox.shrink();
        }

        final balances = snapshot.data!;

        if (balances.isEmpty) {
          return const AdminSection(
            title: 'موجودی بانک',
            icon: Icons.account_balance_rounded,
            children: [
              AdminRow(
                label: 'وضعیت',
                value: 'حساب بانکی ثبت نشده است',
              ),
            ],
          );
        }

        final active = balances.accounts.where((a) => a.isActive).toList();
        final closed = balances.accounts.where((a) => !a.isActive).toList();

        return AdminSection(
          title: 'موجودی بانک',
          icon: Icons.account_balance_rounded,
          children: [
            for (final account in active)
              AdminRow(
                label: _nameOf(account),
                value: account.balanceFormatted,
                // Overdrawn is the one state worth a colour: it means the
                // ledger says more has left the account than went in.
                color: account.isOverdrawn ? AppColors.moneyOut : null,
                // The balance answers how much; its statement answers where
                // it went, which is the question asked when the figure is
                // not what was expected.
                onTap: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => BankStatementScreen(
                      api: widget.api,
                      account: account,
                    ),
                  ),
                ),
              ),

            // Only worth a line when there is more than one to add up.
            if (active.length > 1)
              AdminRow(
                label: 'جمع کل',
                value: balances.totalFormatted,
                emphasise: true,
              ),

            // Named but set apart: a closed account's money is not money
            // the shop can spend, and it is left out of the total.
            for (final account in closed)
              AdminRow(
                label: '${_nameOf(account)} (بسته)',
                value: account.balanceFormatted,
              ),
          ],
        );
      },
    );
  }

  /// The shop's own name for the account, with the bank when it adds
  /// something — two accounts called "اصلی" are told apart by their bank.
  String _nameOf(BankAccount account) {
    final name = account.label?.trim().isNotEmpty == true
        ? account.label!.trim()
        : account.title;

    return account.isDefault ? '$name  •  پیش‌فرض' : name;
  }
}
