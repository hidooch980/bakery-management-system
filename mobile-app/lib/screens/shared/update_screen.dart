import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../services/install_permission.dart';
import '../../services/update_service.dart';
import '../../widgets/common.dart';
import '../../theme/app_theme.dart';

/// Manual "check for updates" screen, reachable from Settings.
class UpdateScreen extends StatefulWidget {
  const UpdateScreen({super.key});

  @override
  State<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends State<UpdateScreen> {
  final _service = UpdateService();
  final _cancelToken = CancelToken();

  String _currentVersion = '…';
  bool _checking = true;
  bool _downloading = false;
  double _progress = 0;
  AppUpdate? _update;

  @override
  void initState() {
    super.initState();
    _check();
  }

  @override
  void dispose() {
    if (_downloading) _cancelToken.cancel();
    super.dispose();
  }

  Future<void> _check() async {
    setState(() => _checking = true);

    final version = await _service.currentVersion();
    final update = await _service.checkForUpdate();

    if (!mounted) return;

    setState(() {
      _currentVersion = version;
      _update = update;
      _checking = false;
    });
  }

  Future<void> _install() async {
    final update = _update;
    if (update == null) return;

    setState(() {
      _downloading = true;
      _progress = 0;
    });

    try {
      await _service.downloadAndInstall(
        update,
        cancelToken: _cancelToken,
        onProgress: (p) {
          if (mounted) setState(() => _progress = p);
        },
      );

      if (!mounted) return;
      showMessage(context, 'فایل نصب آماده شد. مراحل نصب را ادامه دهید.');
    } catch (e) {
      if (!mounted) return;
      // The usual cause is the missing install-unknown-apps consent, so offer
      // to open that settings page rather than just reporting a failure.
      await _showPermissionHelp();
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  Future<void> _showPermissionHelp() async {
    final open = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.shield_rounded, size: IconSize.large),
        title: const Text('اجازه نصب لازم است'),
        content: const Text(
          'اندروید برای نصب برنامه‌هایی که از فروشگاه نیامده‌اند، یک اجازه '
          'یک‌باره می‌خواهد.\n\n'
          'در صفحه‌ای که باز می‌شود، گزینه «اجازه نصب برنامه‌های ناشناس» را '
          'روشن کنید و سپس برگردید و دوباره روی نصب بزنید.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('بعداً'),
          ),
          FilledButton.icon(
            onPressed: () => Navigator.pop(context, true),
            icon: const Icon(Icons.settings_rounded),
            label: const Text('باز کردن تنظیمات'),
          ),
        ],
      ),
    );

    if (open != true || !mounted) return;

    final opened = await InstallPermission.openSettings();

    if (!mounted) return;

    if (!opened) {
      showMessage(
        context,
        'تنظیمات باز نشد. از مسیر تنظیمات ← برنامه‌ها ← دسترسی ویژه ← '
        'نصب برنامه‌های ناشناس، این برنامه را مجاز کنید.',
        isError: true,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(
        title: const Text('به‌روزرسانی برنامه'),
        actions: const [ThemeToggleButton()],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Card(
              child: ListTile(
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                leading: CircleAvatar(
                  backgroundColor: scheme.primary.withValues(alpha: 0.14),
                  child: Icon(Icons.info_outline_rounded, color: scheme.primary),
                ),
                title: const Text('نسخه فعلی'),
                subtitle: Text(_currentVersion),
              ),
            ),
            const SizedBox(height: 20),
            if (_checking)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 40),
                child: Column(
                  children: [
                    CircularProgressIndicator(),
                    SizedBox(height: 16),
                    Text('در حال بررسی نسخه جدید…'),
                  ],
                ),
              )
            else if (_update == null)
              Column(
                children: [
                  const EmptyState(
                    icon: Icons.check_circle_outline_rounded,
                    title: 'برنامه به‌روز است',
                    subtitle: 'نسخه جدیدی برای نصب موجود نیست.',
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: _check,
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('بررسی دوباره'),
                  ),
                ],
              )
            else
              _UpdateAvailableCard(
                update: _update!,
                downloading: _downloading,
                progress: _progress,
                onInstall: _install,
                onOpenPermissionSettings: InstallPermission.openSettings,
              ),
          ],
        ),
      ),
    );
  }
}

class _UpdateAvailableCard extends StatelessWidget {
  const _UpdateAvailableCard({
    required this.update,
    required this.downloading,
    required this.progress,
    required this.onInstall,
    required this.onOpenPermissionSettings,
  });

  final AppUpdate update;
  final bool downloading;
  final double progress;
  final VoidCallback onInstall;
  final VoidCallback onOpenPermissionSettings;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Container(
                  width: 52,
                  height: 52,
                  decoration: BoxDecoration(
                    color: AppColors.moneyIn.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: const Icon(Icons.system_update_rounded,
                      color: AppColors.moneyIn, size: IconSize.heading),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'نسخه ${update.version} موجود است',
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        update.sizeLabel,
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(color: scheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (update.notes != null && update.notes!.trim().isNotEmpty) ...[
              const SizedBox(height: 18),
              const Divider(),
              const SizedBox(height: 12),
              Text(
                'تغییرات این نسخه',
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              Text(
                update.notes!.trim(),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
            const SizedBox(height: 22),
            if (downloading) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: LinearProgressIndicator(
                  value: progress,
                  minHeight: 10,
                ),
              ),
              const SizedBox(height: 10),
              Center(
                child: Text(
                  'در حال دانلود… ${(progress * 100).toStringAsFixed(0)}٪',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            ] else
              FilledButton.icon(
                onPressed: onInstall,
                icon: const Icon(Icons.download_rounded),
                label: const Text('دانلود و نصب'),
              ),
            const SizedBox(height: 12),
            TextButton.icon(
              onPressed: onOpenPermissionSettings,
              icon: const Icon(Icons.shield_rounded, size: IconSize.row),
              label: const Text('تنظیم اجازه نصب (یک‌بار لازم است)'),
              style: TextButton.styleFrom(
                foregroundColor: scheme.onSurfaceVariant,
                textStyle: Theme.of(context).textTheme.bodySmall,
              ),
            ),

            // Copies installed before the signing key was fixed cannot be
            // updated over — Android refuses to replace an app with one
            // signed differently. Uninstalling first is the only way, and
            // the failure message Android shows does not say so.
            const SizedBox(height: 4),
            const _InstallFailedHint(),
          ],
        ),
      ),
    );
  }
}

/// Why an install can fail with "برنامه نصب نشد" even when everything else
/// is right — and the one thing that fixes it.
///
/// Copies of v1.0.0 and v1.1.0 were built before the release signing key
/// existed, so Android treats the new build as a different app and refuses
/// to replace them. Its own error message says nothing about signatures,
/// which leaves people retrying the same download.
class _InstallFailedHint extends StatefulWidget {
  const _InstallFailedHint();

  @override
  State<_InstallFailedHint> createState() => _InstallFailedHintState();
}

class _InstallFailedHintState extends State<_InstallFailedHint> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextButton.icon(
          onPressed: () => setState(() => _open = !_open),
          icon: Icon(
            _open
                ? Icons.keyboard_arrow_up_rounded
                : Icons.help_outline_rounded,
            size: IconSize.row,
          ),
          label: const Text('نصب نشد؟'),
          style: TextButton.styleFrom(
            foregroundColor: scheme.onSurfaceVariant,
            textStyle: Theme.of(context).textTheme.bodySmall,
          ),
        ),
        if (_open)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'اگر پیام «برنامه نصب نشد» می‌بینید:',
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                Text(
                  'نسخه‌های خیلی قدیمی این برنامه با کلید دیگری ساخته شده '
                  'بودند و اندروید اجازه نمی‌دهد روی آن‌ها نصب شود.\n\n'
                  '۱. همین برنامه را از گوشی حذف کنید\n'
                  '۲. دوباره فایل را نصب کنید\n\n'
                  'اطلاعات شما روی سرور است و از بین نمی‌رود؛ فقط باید '
                  'دوباره وارد شوید.',
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: scheme.onSurfaceVariant, height: 1.6),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
