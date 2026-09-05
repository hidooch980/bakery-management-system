import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/role_home_scaffold.dart';
import 'admin_finance_tab.dart';
import 'admin_today_tab.dart';
import 'admin_overview_tab.dart';
import 'admin_staff_tab.dart';
import 'admin_warehouse_tab.dart';
import '../../theme/app_theme.dart';

/// The admin's own app: one answer, then today's numbers, money, stock and
/// staff — the same information as the web panel, laid out for a phone.
///
/// «امروز» leads, because four tabs of correct figures are what the owner
/// had on 1405/06/07 while 400 kg of flour was missing from the ledger.
/// Nothing was wrong with what he could see; what he needed was a screen
/// willing to say whether it added up.
class AdminHomeScreen extends StatefulWidget {
  const AdminHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminHomeScreen> createState() => _AdminHomeScreenState();
}

class _AdminHomeScreenState extends State<AdminHomeScreen> {
  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _loadBakery();
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The tabs degrade gracefully without shop settings.
    }
  }

  @override
  Widget build(BuildContext context) {
    return RoleHomeScaffold(
      api: widget.api,
      bakery: _bakery,
      tabs: [
        // First, and first for a reason: an answer before four tabs of
        // figures. «خلاصه» keeps everything it had for anyone who wants
        // to read the numbers themselves.
        HomeTab(
          label: 'امروز',
          title: 'امروز',
          icon: Icons.wb_sunny_outlined,
          selectedIcon: Icons.wb_sunny_rounded,
          builder: (_) => AdminTodayTab(api: widget.api),
        ),
        HomeTab(
          label: 'خلاصه',
          destination: 'overview',
          title: 'خلاصه امروز',
          icon: Icons.dashboard_outlined,
          selectedIcon: Icons.dashboard_rounded,
          builder: (_) => AdminOverviewTab(api: widget.api, bakery: _bakery),
        ),
        HomeTab(
          label: 'مالی',
          destination: 'finance',
          title: 'مالی',
          icon: Icons.account_balance_wallet_outlined,
          selectedIcon: Icons.account_balance_wallet_rounded,
          builder: (_) => AdminFinanceTab(api: widget.api, bakery: _bakery),
        ),
        HomeTab(
          label: 'انبار',
          destination: 'warehouse',
          title: 'انبار',
          icon: Icons.warehouse_outlined,
          selectedIcon: Icons.warehouse_rounded,
          builder: (_) => AdminWarehouseTab(api: widget.api),
        ),
        HomeTab(
          label: 'کارکنان',
          destination: 'staff',
          title: 'کارکنان',
          icon: Icons.people_outline_rounded,
          selectedIcon: Icons.people_rounded,
          builder: (_) => AdminStaffTab(api: widget.api),
        ),
      ],
    );
  }
}

/// Shared building block for the admin tabs: a titled group of rows.
class AdminSection extends StatelessWidget {
  const AdminSection({
    super.key,
    required this.title,
    required this.icon,
    required this.children,
    this.trailing,
  });

  final String title;
  final IconData icon;
  final List<Widget> children;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
          child: Row(
            children: [
              Icon(icon, size: IconSize.row, color: scheme.primary),
              const SizedBox(width: 8),
              Text(
                title,
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              const Spacer(),
              if (trailing != null) trailing!,
            ],
          ),
        ),
        Card(child: Column(children: children)),
      ],
    );
  }
}

/// A label/value row used throughout the admin tabs.
class AdminRow extends StatelessWidget {
  const AdminRow({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.color,
    this.emphasise = false,
    this.onTap,
  });

  final String label;
  final String value;
  final IconData? icon;
  final Color? color;
  final bool emphasise;

  /// Given when the row leads somewhere — a balance to its statement. The
  /// row grows a chevron so it is visibly worth pressing.
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final accent = color ?? scheme.onSurface;

    final row = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      child: Row(
        children: [
          if (icon != null) ...[
            Icon(icon, size: IconSize.button, color: color ?? scheme.onSurfaceVariant),
            const SizedBox(width: 12),
          ],
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
          ),
          Text(
            value,
            style: (emphasise
                    ? Theme.of(context).textTheme.titleMedium
                    : Theme.of(context).textTheme.bodyLarge)
                ?.copyWith(fontWeight: FontWeight.w700, color: accent),
          ),
          if (onTap != null) ...[
            const SizedBox(width: 6),
            Icon(Icons.chevron_left_rounded,
                size: IconSize.button, color: scheme.onSurfaceVariant),
          ],
        ],
      ),
    );

    if (onTap == null) {
      return row;
    }

    return InkWell(onTap: onTap, child: row);
  }
}

/// Formats a stored Toman amount using the shop's configured unit.
String adminMoney(Bakery? bakery, num? toman) {
  return MoneyFormat.format(toman, currency: bakery?.currency ?? Currency.toman);
}
