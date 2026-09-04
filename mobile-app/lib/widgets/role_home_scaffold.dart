import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/bakery.dart';
import '../providers/auth_provider.dart';
import '../screens/shared/settings_screen.dart';
import '../services/bakery_api.dart';
import 'common.dart';
import 'saved_copy_banner.dart';
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

  /// What the menu calls it — a word, not a sentence.
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
/// pages. Every other role got one long scroll instead — the seller's ran
/// to a thousand lines, and finding the day's sales meant scrolling past
/// attendance, stock and the account.
///
/// The pages are chosen from a bar along the bottom. It was a drawer for a
/// while — a bar spends height on something looked at a few times an hour,
/// and the height belongs to the work. The shop tried both and said the
/// bar was tidier, which settles it: the drawer's saving is real and so is
/// having to remember the pages are behind a button, and only one of those
/// two is felt by the person holding the phone.
///
/// The height is kept as low as a bar can go and only the selected page is
/// labelled, so the cost is about sixty pixels rather than eighty.
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
  /// and connection card, just no menu to choose from — and so no button
  /// offering to open one.
  final List<HomeTab> tabs;

  /// Named in the title bar. Null until the settings have loaded.
  final Bakery? bakery;

  /// Anything the role wants beside the theme and settings buttons.
  final List<Widget> actions;

  /// The role's one primary action — recording a batch, say. Kept on the
  /// scaffold rather than inside a page so a page that scrolls cannot carry
  /// it off the screen.
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
            // Beside it, and separate: what is waiting to be sent and what
            // is being read from a saved copy are two different problems,
            // and only one of them clears itself.
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              child: SavedCopyBanner(client: widget.api.client),
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
      // Absent when there is nothing to choose between: one page with a
      // bar under it saying so is a row of nothing.
      bottomNavigationBar: tabs.length < 2 ? null : _bar(tabs),
    );
  }

  Widget _bar(List<HomeTab> tabs) {
    return NavigationBar(
      selectedIndex: _tab.clamp(0, tabs.length - 1),
      onDestinationSelected: (index) => setState(() => _tab = index),
      // The label under the selected one only. All four labelled at once
      // is four words competing with the page above them; none at all and
      // the icons have to be learned.
      labelBehavior: NavigationDestinationLabelBehavior.onlyShowSelected,
      height: 64,
      destinations: [
        for (final tab in tabs)
          NavigationDestination(
            icon: Icon(tab.icon),
            selectedIcon: Icon(tab.selectedIcon),
            label: tab.label,
          ),
      ],
    );
  }
}
