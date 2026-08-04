import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/bakery.dart';
import '../providers/auth_provider.dart';
import '../screens/shared/settings_screen.dart';
import '../services/bakery_api.dart';
import 'common.dart';
import 'sync_status_card.dart';

/// One page of a role's home screen.
class HomeTab {
  const HomeTab({
    required this.label,
    required this.title,
    required this.icon,
    required this.selectedIcon,
    required this.builder,
  });

  /// What the bottom bar calls it — a word, not a sentence.
  final String label;

  /// What the app bar calls it, which can be longer.
  final String title;

  final IconData icon;

  final IconData selectedIcon;

  final WidgetBuilder builder;
}

/// The shape every role's home screen shares.
///
/// Only the admin had it: a title bar naming the shop and the person, the
/// connection state where it cannot be missed, and the work divided into
/// pages along the bottom. Every other role got one long scroll instead —
/// the seller's ran to a thousand lines, and finding the day's sales meant
/// scrolling past attendance, stock and the account.
///
/// Written once here so a shop's roles do not each drift into their own
/// idea of where things live.
class RoleHomeScaffold extends StatefulWidget {
  const RoleHomeScaffold({
    super.key,
    required this.api,
    required this.tabs,
    this.bakery,
    this.actions = const [],
    this.floatingActionButton,
  });

  final BakeryApi api;

  /// At least one. A role with a single page still gets the same title bar
  /// and connection card, just no bar along the bottom to choose from.
  final List<HomeTab> tabs;

  /// Named in the title bar. Null until the settings have loaded.
  final Bakery? bakery;

  /// Anything the role wants beside the theme and settings buttons.
  final List<Widget> actions;

  /// The role's one primary action — recording a batch, say. Kept on the
  /// scaffold rather than inside a page so it sits above the bottom bar
  /// instead of behind it.
  final Widget? floatingActionButton;

  @override
  State<RoleHomeScaffold> createState() => _RoleHomeScaffoldState();
}

class _RoleHomeScaffoldState extends State<RoleHomeScaffold> {
  int _tab = 0;

  @override
  void didUpdateWidget(RoleHomeScaffold oldWidget) {
    super.didUpdateWidget(oldWidget);

    // A role that gains or loses a page — a permission arriving, a section
    // hiding itself — must not be left pointing past the end of the list.
    if (_tab >= widget.tabs.length) {
      _tab = widget.tabs.length - 1;
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final theme = Theme.of(context);
    final tabs = widget.tabs;
    final current = tabs[_tab.clamp(0, tabs.length - 1)];

    return Scaffold(
      floatingActionButton: widget.floatingActionButton,
      appBar: AppBar(
        title: Text(current.title),
        actions: [
          ...widget.actions,
          const ThemeToggleButton(),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            tooltip: 'تنظیمات',
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
                    widget.bakery?.name ?? 'نانوایی',
                    style: theme.textTheme.titleMedium
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                Text(
                  user?.name ?? '',
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
        ),
      ),
      body: SafeArea(
        child: Column(
          children: [
            // Above the pages rather than inside one, so losing the server
            // is visible whichever page the user happens to be on.
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: SyncStatusCard(api: widget.api),
            ),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 250),
                child: KeyedSubtree(
                  key: ValueKey(_tab),
                  child: Builder(builder: current.builder),
                ),
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: tabs.length < 2
          ? null
          : NavigationBar(
              selectedIndex: _tab.clamp(0, tabs.length - 1),
              onDestinationSelected: (index) => setState(() => _tab = index),
              destinations: [
                for (final tab in tabs)
                  NavigationDestination(
                    icon: Icon(tab.icon),
                    selectedIcon: Icon(tab.selectedIcon),
                    label: tab.label,
                  ),
              ],
            ),
    );
  }
}
