import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../providers/theme_provider.dart';
import '../../services/bakery_api.dart';
import '../../widgets/biometric_tile.dart';
import '../admin/backup_screen.dart';
import 'change_password_screen.dart';
import 'my_advances_screen.dart';
import 'my_devices_screen.dart';
import 'update_screen.dart';
import '../../theme/app_theme.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.logout_rounded, size: IconSize.large),
        title: const Text('خروج از حساب'),
        content: const Text('آیا می‌خواهید از حساب خود خارج شوید؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(context).colorScheme.error,
              minimumSize: const Size(88, 44),
            ),
            child: const Text('خروج'),
          ),
        ],
      ),
    );

    if (confirmed == true && context.mounted) {
      await context.read<AuthProvider>().logout();
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final theme = context.watch<ThemeProvider>();
    final user = auth.user;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: const Text('تنظیمات')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 30,
                      backgroundColor: scheme.primary.withValues(alpha: 0.15),
                      child: Icon(Icons.person_rounded,
                          size: IconSize.large, color: scheme.primary),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            user?.name ?? '—',
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 6),
                          Chip(
                            label: Text(user?.role.label ?? '—'),
                            visualDensity: VisualDensity.compact,
                            backgroundColor:
                                scheme.primary.withValues(alpha: 0.12),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),
            Text(
              'ظاهر برنامه',
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 12),
            Card(
              child: RadioGroup<ThemeMode>(
                groupValue: theme.mode,
                onChanged: (value) {
                  if (value != null) theme.setMode(value);
                },
                child: Column(
                  children: [
                    for (final mode in ThemeMode.values)
                      RadioListTile<ThemeMode>(
                        value: mode,
                        title: Text(switch (mode) {
                          ThemeMode.light => 'حالت روشن',
                          ThemeMode.dark => 'حالت تاریک',
                          ThemeMode.system => 'مطابق تنظیمات سیستم',
                        }),
                        secondary: Icon(switch (mode) {
                          ThemeMode.light => Icons.light_mode_rounded,
                          ThemeMode.dark => Icons.dark_mode_rounded,
                          ThemeMode.system => Icons.brightness_auto_rounded,
                        }),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),
            Text(
              'حساب کاربری',
              style: Theme.of(context)
                  .textTheme
                  .titleSmall
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 12),
            Card(
              child: Column(
                children: [
                  const BiometricTile(),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.lock_reset_rounded),
                    title: const Text('تغییر رمز عبور'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => const ChangePasswordScreen(),
                      ),
                    ),
                  ),
                  const Divider(height: 1),
                  // Every role, and above the rest of the list on purpose:
                  // whoever has just lost a phone is not necessarily
                  // somebody with a role that can do anything else here.
                  ListTile(
                    leading: const Icon(Icons.devices_rounded),
                    title: const Text('دستگاه‌های من'),
                    subtitle: const Text('خروج از گوشی گم‌شده'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => MyDevicesScreen(
                          api: context.read<BakeryApi>(),
                        ),
                      ),
                    ),
                  ),
                  const Divider(height: 1),
                  // Reachable by every role: the person whose pay an
                  // advance comes out of is whoever took it, not only the
                  // seller whose home screen happens to have room.
                  ListTile(
                    leading: const Icon(Icons.wallet_rounded),
                    title: const Text('علی‌الحساب من'),
                    subtitle: const Text('دریافتی‌ها و درخواست جدید'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => MyAdvancesScreen(
                          api: context.read<BakeryApi>(),
                        ),
                      ),
                    ),
                  ),
                  // Only the owner. It is his data and his decision, and
                  // the same permission already gates the shop's other
                  // settings.
                  if (user?.can('manage-bakery') ?? false) ...[
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.backup_rounded),
                      title: const Text('پشتیبان‌گیری'),
                      subtitle: const Text('آخرین پشتیبان و گرفتن نسخه تازه'),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => BackupScreen(
                            api: context.read<BakeryApi>(),
                          ),
                        ),
                      ),
                    ),
                  ],
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.system_update_rounded),
                    title: const Text('به‌روزرسانی برنامه'),
                    subtitle: const Text('بررسی و نصب نسخه جدید'),
                    trailing: const Icon(Icons.chevron_left_rounded),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const UpdateScreen()),
                    ),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: Icon(Icons.logout_rounded, color: scheme.error),
                    title: Text('خروج از حساب',
                        style: TextStyle(color: scheme.error)),
                    onTap: () => _confirmLogout(context),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
