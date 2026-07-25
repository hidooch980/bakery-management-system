import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import 'settings_screen.dart';

/// Admins do their day-to-day work in the Filament web panel; the app shows
/// them their identity and points them there.
class AdminHomeScreen extends StatelessWidget {
  const AdminHomeScreen({super.key, required this.api});

  final BakeryApi api;

  /// Derived from the configured API base URL so it stays correct on any host.
  static String get _panelUrl {
    final base = Uri.tryParse(ApiClient.defaultBaseUrl);
    if (base == null) return 'http://<آدرس-سرور>:8000/admin';

    return base.replace(path: '/admin', query: '').toString();
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(
        title: const Text('مدیریت'),
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
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'سلام ${user?.name ?? ''}',
              style: Theme.of(context)
                  .textTheme
                  .headlineSmall
                  ?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
            Text(
              'شما با نقش مدیر وارد شده‌اید.',
              style: Theme.of(context)
                  .textTheme
                  .bodyMedium
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 24),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.dashboard_rounded, color: scheme.primary),
                        const SizedBox(width: 10),
                        Text(
                          'پنل مدیریت تحت وب',
                          style: Theme.of(context)
                              .textTheme
                              .titleMedium
                              ?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text(
                      'داشبورد، گزارش‌ها، مدیریت کاربران، حضور و غیاب و تنظیمات '
                      'نانوایی در پنل تحت وب در دسترس است:',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 12),
                    SelectableText(
                      _panelUrl,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: scheme.primary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: scheme.primary.withValues(alpha: 0.14),
                  child: Icon(Icons.person_rounded, color: scheme.primary),
                ),
                title: Text(user?.name ?? '—'),
                subtitle: Text(user?.role.label ?? '—'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
