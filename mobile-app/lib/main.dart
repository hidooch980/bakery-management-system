import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'models/user.dart';
import 'providers/auth_provider.dart';
import 'providers/theme_provider.dart';
import 'screens/auth/login_screen.dart';
import 'screens/chane/chane_home_screen.dart';
import 'screens/dough/dough_home_screen.dart';
import 'screens/seller/seller_home_screen.dart';
import 'screens/admin/admin_home_screen.dart';
import 'screens/shater/shater_home_screen.dart';
import 'services/api_client.dart';
import 'services/bakery_api.dart';
import 'services/server_directory.dart';
import 'theme/app_theme.dart';
import 'widgets/update_prompt.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final client = ApiClient();

  // Where the backend lives is published in the repository rather than
  // baked into the build, so moving the server does not strand every
  // phone that already has the app. The lookup never throws and falls
  // back to the last address that worked.
  client.useBaseUrl(await ServerDirectory().resolve());

  runApp(BakeryApp(api: BakeryApi(client)));
}

class BakeryApp extends StatelessWidget {
  const BakeryApp({super.key, required this.api});

  final BakeryApi api;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthProvider(api)..bootstrap()),
        ChangeNotifierProvider(create: (_) => ThemeProvider()..load()),
      ],
      child: Consumer<ThemeProvider>(
        builder: (context, theme, _) => MaterialApp(
          title: 'مدیریت نانوایی',
          debugShowCheckedModeBanner: false,
          themeMode: theme.mode,
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          locale: const Locale('fa'),
          supportedLocales: const [Locale('fa'), Locale('en')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          // The whole UI is Persian, so force RTL regardless of device locale.
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: child ?? const SizedBox.shrink(),
          ),
          home: AppGate(api: api),
        ),
      ),
    );
  }
}

/// Sends the signed-in user to the screen that matches their role, and
/// everyone else to the login screen.
class AppGate extends StatelessWidget {
  const AppGate({super.key, required this.api});

  final BakeryApi api;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 350),
      child: switch (auth.status) {
        AuthStatus.unknown => const _SplashScreen(),
        AuthStatus.unauthenticated => const LoginScreen(),
        // Signed in and on their own screen is the one moment the user is
        // certain to reach, so it is where a waiting update is mentioned.
        AuthStatus.authenticated => UpdatePrompt(
            child: switch (auth.user?.role) {
              UserRole.admin => AdminHomeScreen(api: api),
              UserRole.doughMaker => DoughHomeScreen(api: api),
              UserRole.chaneGir => ChaneHomeScreen(api: api),
              UserRole.shater => ShaterHomeScreen(api: api),
              UserRole.seller => SellerHomeScreen(api: api),
              _ => const _UnknownRoleScreen(),
            },
          ),
      },
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 92,
              height: 92,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.wheat, AppColors.crust],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(26),
              ),
              child: const Icon(Icons.bakery_dining_rounded,
                  size: 48, color: Colors.white),
            ),
            const SizedBox(height: 28),
            Text(
              'سیستم مدیریت نانوایی',
              style: Theme.of(context)
                  .textTheme
                  .titleLarge
                  ?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: 28,
              height: 28,
              child: CircularProgressIndicator(strokeWidth: 3, color: scheme.primary),
            ),
          ],
        ),
      ),
    );
  }
}

/// Shown when the account has no recognised role — the admin must fix it.
class _UnknownRoleScreen extends StatelessWidget {
  const _UnknownRoleScreen();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.help_outline_rounded,
                  size: 64, color: Theme.of(context).colorScheme.error),
              const SizedBox(height: 20),
              Text(
                'نقشی برای حساب شما تعریف نشده است',
                textAlign: TextAlign.center,
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 10),
              const Text(
                'لطفاً با مدیر سیستم تماس بگیرید.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 28),
              OutlinedButton.icon(
                onPressed: () => context.read<AuthProvider>().logout(),
                icon: const Icon(Icons.logout_rounded),
                label: const Text('خروج از حساب'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
