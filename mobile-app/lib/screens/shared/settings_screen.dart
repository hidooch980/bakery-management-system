import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../providers/theme_provider.dart';
import '../../widgets/biometric_tile.dart';

import 'change_password_screen.dart';
import 'update_screen.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.logout_rounded, size: 32),
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
                          size: 32, color: scheme.primary),
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
