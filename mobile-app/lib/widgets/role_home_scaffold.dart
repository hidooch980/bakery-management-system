import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../models/bakery.dart';
import '../models/my_bakery.dart';
import '../providers/auth_provider.dart';
import '../screens/shared/settings_screen.dart';
import '../services/api_client.dart';
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
    this.destination,
  });

  /// What the menu calls it — a word, not a sentence.
  final String label;

  /// What the app bar calls it, which can be longer.
  final String title;

  final IconData icon;

  final IconData selectedIcon;

  final WidgetBuilder builder;

  /// The name the server uses for this tab when it sends a screen
  /// somewhere — `warehouse`, `finance`, and so on.
  ///
  /// Deliberately not the Persian label: that is what the shop reads and
  /// is free to change, and a destination that broke because somebody
  /// renamed a tab would break silently.
  final String? destination;
}

/// Lets a page inside the scaffold move to another one of its tabs.
///
/// «امروز» needs it: an issue there says what is wrong, and the answer is
/// on a different tab. Without this the row could only name the place and
/// leave the owner to find it — which is what it did.
class HomeTabs extends InheritedWidget {
  const HomeTabs({super.key, required this.goTo, required super.child});

  /// Moves to the tab with this destination name. Does nothing when no
  /// tab claims the name, so an unknown one is inert rather than an error.
  final void Function(String destination) goTo;

  static HomeTabs? of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<HomeTabs>();

  @override
  bool updateShouldNotify(HomeTabs oldWidget) => false;
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

  /// مغازه‌هایی که این شخص می‌تواند ببیند.
  ///
  /// خالی می‌ماند تا وقتی جواب برسد، و اگر نرسد خالی می‌ماند — کسی که یک
  /// مغازه دارد هیچ فرقی نمی‌بیند، و آن تقریباً همهٔ کسانی است که این
  /// صفحه را باز می‌کنند.
  List<MyBakery> _shops = const [];

  Future<void> _loadShops() async {
    try {
      final shops = await widget.api.myBakeries();

      if (mounted) setState(() => _shops = shops);
    } on ApiException {
      // یک صفحهٔ خانه به خاطر نبودنِ یک انتخابِ اضافه خراب نمی‌شود.
    }
  }

  /// مغازه عوض شد: انتخاب ثبت می‌شود و فهرست دوباره خوانده می‌شود تا
  /// «انتخاب‌شده» را از سرور بگیرد، نه از حدسِ گوشی — سرور ممکن است
  /// شناسه‌ای را که حقش نیست نادیده گرفته باشد.
  Future<void> _switchTo(MyBakery shop) async {
    await widget.api.client.actAsBakery(shop.id);

    if (mounted) await _loadShops();
  }

  /// نامی که در نوار بالا می‌نشیند.
  ///
  /// بعد از جابه‌جایی، `widget.bakery` هنوز مغازهٔ قبلی است — یک بار
  /// خوانده شده و صفحه‌ای که آن را می‌خواند تازه دارد از نو ساخته
  /// می‌شود. جوابِ سرور دربارهٔ اینکه کدام مغازه انتخاب است تازه‌تر است،
  /// پس همان می‌نشیند و نام، یک لحظه هم دروغ نمی‌گوید.
  String get _currentShopName {
    for (final shop in _shops) {
      if (shop.isCurrent) return shop.name;
    }

    return widget.bakery?.name ?? 'نانوایی';
  }

  /// فهرست را همان لحظه‌ای می‌گیرد که لازم است.
  ///
  /// تعدادِ مغازه‌ها از `/me` آمده، پس تا کسی روی نام نزند این درخواست
  /// اصلاً فرستاده نمی‌شود — و کسی که یک مغازه دارد هیچ‌وقت نمی‌زند.
  Future<void> _offerShops() async {
    if (_shops.isEmpty) await _loadShops();

    if (!mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      builder: (sheetContext) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 12),
            Text(
              'کدام مغازه',
              style: Theme.of(sheetContext).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: 8),
            for (final shop in _shops)
              ListTile(
                title: Text(shop.name),
                trailing: shop.isCurrent
                    ? const Icon(Icons.check_rounded)
                    : null,
                onTap: () {
                  Navigator.pop(sheetContext);

                  if (!shop.isCurrent) _switchTo(shop);
                },
              ),
            const SizedBox(height: 12),
          ],
        ),
      ),
    );
  }

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

    return HomeTabs(
      goTo: _goTo,
      child: _scaffold(
        context,
        theme,
        user,
        tabs,
        current,
        user?.hasSeveralBakeries == true,
      ),
    );
  }

  /// Moves to the named tab, if this role has one.
  ///
  /// Quiet about a name it does not have: the destinations are chosen on
  /// the server and a seller's home screen has no «مالی» to go to. Doing
  /// nothing is right — the row that offered it should not have, and an
  /// error over a bakery's home screen is worse than a button that stays
  /// put.
  void _goTo(String destination) {
    final index = widget.tabs.indexWhere((t) => t.destination == destination);

    if (index >= 0 && index != _tab) setState(() => _tab = index);
  }

  Widget _scaffold(
    BuildContext context,
    ThemeData theme,
    dynamic user,
    List<HomeTab> tabs,
    HomeTab current,
    bool several,
  ) {
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
                  // یک مغازه که باشد، اسم فقط اسم است. چند تا که باشد،
                  // همان اسم دکمهٔ جابه‌جایی می‌شود — جایی که آدم برای
                  // فهمیدنِ «کجا هستم» همان‌جا را نگاه می‌کند.
                  child: several
                      ? InkWell(
                          onTap: _offerShops,
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Flexible(
                                child: Text(
                                  _currentShopName,
                                  style: theme.textTheme.titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w800),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              const SizedBox(width: 4),
                              const Icon(Icons.unfold_more_rounded, size: 18),
                            ],
                          ),
                        )
                      : Text(
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
              // کلید، هم تب است و هم نسلِ مغازه. صفحه‌ها ارقامشان را در
              // `initState` می‌گیرند، پس بدون عوض شدن کلید، بعد از
              // جابه‌جایی همان ارقامِ مغازهٔ قبلی زیر نام مغازهٔ تازه
              // می‌ماند — بدتر از صفحهٔ خالی.
              child: ValueListenableBuilder<int>(
                valueListenable: widget.api.client.shopGeneration,
                builder: (context, generation, _) => AnimatedSwitcher(
                  duration: const Duration(milliseconds: 250),
                  child: KeyedSubtree(
                    key: ValueKey('$generation-$_tab'),
                    child: Builder(builder: current.builder),
                  ),
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
