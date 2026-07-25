import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/bakery.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../shared/settings_screen.dart';
import 'admin_finance_tab.dart';
import 'admin_overview_tab.dart';
import 'admin_staff_tab.dart';
import 'admin_warehouse_tab.dart';

/// The admin's own app: today's numbers, money, stock and staff — the same
/// information as the web panel, laid out for a phone.
class AdminHomeScreen extends StatefulWidget {
  const AdminHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminHomeScreen> createState() => _AdminHomeScreenState();
}

class _AdminHomeScreenState extends State<AdminHomeScreen> {
  int _tab = 0;
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

  static const _titles = ['خلاصه امروز', 'مالی', 'انبار', 'کارکنان'];

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

    final tabs = [
      AdminOverviewTab(api: widget.api, bakery: _bakery),
      AdminFinanceTab(api: widget.api, bakery: _bakery),
      AdminWarehouseTab(api: widget.api),
      AdminStaffTab(api: widget.api),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Text(_titles[_tab]),
        actions: [
          const ThemeToggleButton(),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const SettingsScreen()),
            ),
          ),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(52),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    _bakery?.name ?? 'نانوایی',
                    style: Theme.of(context)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                Text(
                  user?.name ?? '',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
          ),
        ),
      ),
      body: SafeArea(
        child: AnimatedSwitcher(
          duration: const Duration(milliseconds: 250),
          child: KeyedSubtree(key: ValueKey(_tab), child: tabs[_tab]),
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (index) => setState(() => _tab = index),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.dashboard_outlined),
            selectedIcon: Icon(Icons.dashboard_rounded),
            label: 'خلاصه',
          ),
          NavigationDestination(
            icon: Icon(Icons.account_balance_wallet_outlined),
            selectedIcon: Icon(Icons.account_balance_wallet_rounded),
            label: 'مالی',
          ),
          NavigationDestination(
            icon: Icon(Icons.warehouse_outlined),
            selectedIcon: Icon(Icons.warehouse_rounded),
            label: 'انبار',
          ),
          NavigationDestination(
            icon: Icon(Icons.people_outline_rounded),
            selectedIcon: Icon(Icons.people_rounded),
            label: 'کارکنان',
          ),
        ],
      ),
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
              Icon(icon, size: 18, color: scheme.primary),
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
  });

  final String label;
  final String value;
  final IconData? icon;
  final Color? color;
  final bool emphasise;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final accent = color ?? scheme.onSurface;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      child: Row(
        children: [
          if (icon != null) ...[
            Icon(icon, size: 20, color: color ?? scheme.onSurfaceVariant),
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
        ],
      ),
    );
  }
}

/// Formats a stored Toman amount using the shop's configured unit.
String adminMoney(Bakery? bakery, num? toman) {
  return MoneyFormat.format(toman, currency: bakery?.currency ?? Currency.toman);
}
