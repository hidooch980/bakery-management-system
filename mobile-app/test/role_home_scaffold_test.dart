import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';

import 'package:bakery_app/models/bakery.dart';
import 'package:bakery_app/providers/auth_provider.dart';
import 'package:bakery_app/providers/theme_provider.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/services/connection_status.dart';
import 'package:bakery_app/widgets/role_home_scaffold.dart';

/// Reports the server as reachable, so the connection card stays out of the
/// way and the pages themselves are what is under test.
class _OnlineStatus extends ConnectionStatus {
  _OnlineStatus(super.client);

  @override
  bool get online => true;

  @override
  Future<void> refresh() async {}
}

HomeTab _tab(String label, String title, String body) => HomeTab(
      label: label,
      title: title,
      icon: Icons.circle_outlined,
      selectedIcon: Icons.circle,
      builder: (_) => Center(child: Text(body)),
    );

Future<void> _pump(WidgetTester tester, List<HomeTab> tabs, {Bakery? bakery}) async {
  final api = BakeryApi(ApiClient(baseUrl: 'http://server.test/api/v1'));

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthProvider>(create: (_) => AuthProvider(api)),
        ChangeNotifierProvider<ConnectionStatus>(
          create: (_) => _OnlineStatus(ApiClient(baseUrl: 'http://server.test/api/v1')),
        ),
        // The bar carries a theme toggle, which reads the theme provider.
        ChangeNotifierProvider<ThemeProvider>(create: (_) => ThemeProvider()),
      ],
      child: MaterialApp(
        home: RoleHomeScaffold(api: api, tabs: tabs, bakery: bakery),
      ),
    ),
  );

  await tester.pump();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('opens on the first page', (tester) async {
    await _pump(tester, [
      _tab('خلاصه', 'خلاصه امروز', 'صفحه یک'),
      _tab('فروش', 'فروش', 'صفحه دو'),
    ]);

    expect(find.text('صفحه یک'), findsOneWidget);
    expect(find.text('صفحه دو'), findsNothing);
    // The bar names the page, which can be longer than its label.
    expect(find.text('خلاصه امروز'), findsOneWidget);
  });

  testWidgets('the drawer moves between pages', (tester) async {
    await _pump(tester, [
      _tab('خلاصه', 'خلاصه امروز', 'صفحه یک'),
      _tab('فروش', 'فروش', 'صفحه دو'),
      _tab('حساب من', 'حساب من', 'صفحه سه'),
    ]);

    tester.state<ScaffoldState>(find.byType(Scaffold).first).openDrawer();
    await tester.pumpAndSettle();

    await tester.tap(find.text('حساب من').last);
    await tester.pumpAndSettle();

    expect(find.text('صفحه سه'), findsOneWidget);
    expect(find.text('صفحه یک'), findsNothing);
  });

  testWidgets('choosing a page closes the drawer behind it', (tester) async {
    await _pump(tester, [
      _tab('خلاصه', 'خلاصه امروز', 'صفحه یک'),
      _tab('فروش', 'فروش', 'صفحه دو'),
    ]);

    tester.state<ScaffoldState>(find.byType(Scaffold).first).openDrawer();
    await tester.pumpAndSettle();

    await tester.tap(find.text('فروش').last);
    await tester.pumpAndSettle();

    // Left open, it would cover the page the tap was for.
    expect(find.byType(NavigationDrawer), findsNothing);
    expect(find.text('صفحه دو'), findsOneWidget);
  });

  testWidgets('a single page gets the same bar and no chooser', (tester) async {
    await _pump(tester, [_tab('شاطر', 'شاطر', 'تنها صفحه')]);

    expect(find.text('تنها صفحه'), findsOneWidget);

    // Nothing to choose between, so there is no drawer — and therefore no
    // button in the title bar offering to open one.
    expect(
      tester.widget<Scaffold>(find.byType(Scaffold).first).drawer,
      isNull,
    );
  });

  testWidgets('more than one page is offered a drawer', (tester) async {
    await _pump(tester, [
      _tab('خلاصه', 'خلاصه', 'صفحه یک'),
      _tab('فروش', 'فروش', 'صفحه دو'),
    ]);

    expect(
      tester.widget<Scaffold>(find.byType(Scaffold).first).drawer,
      isNotNull,
    );
  });

  testWidgets('names the shop once it is known', (tester) async {
    await _pump(
      tester,
      [_tab('خلاصه', 'خلاصه', 'صفحه')],
      bakery: const Bakery(name: 'خبازی نمونه'),
    );

    expect(find.text('خبازی نمونه'), findsOneWidget);
  });

  testWidgets('says nothing wrong before the shop has loaded', (tester) async {
    await _pump(tester, [_tab('خلاصه', 'خلاصه', 'صفحه')]);

    // A placeholder rather than an empty bar or a crash.
    expect(find.text('نانوایی'), findsOneWidget);
  });
}
