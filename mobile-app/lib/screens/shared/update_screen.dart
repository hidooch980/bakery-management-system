import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../services/install_permission.dart';
import '../../services/update_service.dart';
import '../../widgets/common.dart';
import '../../theme/app_theme.dart';

/// Manual "check for updates" screen, reachable from Settings.
class UpdateScreen extends StatefulWidget {
  const UpdateScreen({super.key, this.service});

  final UpdateService? service;

  @override
  State<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends State<UpdateScreen>
    with WidgetsBindingObserver {
  late final UpdateService _service;
  final _cancelToken = CancelToken();

  String _currentVersion = '…';
  bool _checking = true;
  bool _downloading = false;
  double _progress = 0;
  AppUpdate? _update;
  String? _checkError;
  bool _waitingForInstallPermission = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _service = widget.service ?? UpdateService();
    _check();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    if (_downloading) _cancelToken.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _waitingForInstallPermission) {
      _resumeInstallation();
    }
  }

  Future<void> _resumeInstallation() async {
    if (_downloading) return;
    _waitingForInstallPermission = false;
    try {
      final granted = await InstallPermission.isGranted();
      if (mounted && granted) await _install();
    } catch (_) {
      if (mounted)
        showMessage(context, 'بررسی اجازه نصب ممکن نشد؛ دوباره روی نصب بزنید.',
            isError: true);
    }
  }

  Future<void> _check() async {
    setState(() => _checking = true);

    try {
      final version = await _service.currentVersion();
      final update = await _service.checkForUpdate();
      if (!mounted) return;
      setState(() {
        _currentVersion = version;
        _update = update;
        _checkError = _service.lastCheckError;
        _checking = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _checkError = 'بررسی نسخه انجام نشد؛ دوباره تلاش کنید.';
        _checking = false;
      });
    }
  }

  Future<void> _install() async {
    final update = _update;
    if (update == null || _downloading) return;

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
    } on UpdateIntegrityException catch (e) {
      // Not a permission problem: the file was broken or not ours. Sending
      // the user to toggle a setting would not help.
      if (!mounted) return;
      showMessage(context, e.toString());
    } on UpdateInstallException catch (e) {
      if (!mounted) return;
      if (e.permissionDenied) {
        await _showPermissionHelp();
      } else {
        showMessage(context, e.message, isError: true);
      }
    } on DioException catch (e) {
      if (!mounted || CancelToken.isCancel(e)) return;
      showMessage(
        context,
        'دانلود کامل نشد؛ اتصال اینترنت را بررسی و دوباره تلاش کنید.',
        isError: true,
      );
    } catch (e) {
      if (!mounted) return;
      showMessage(context, 'آماده‌سازی نصب انجام نشد: $e', isError: true);
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
          'روشن کنید و سپس برگردید؛ همان فایل دانلودشده برای نصب باز می‌شود.',
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

    _waitingForInstallPermission = true;
    final opened = await InstallPermission.openSettings();

    if (!mounted) return;

    if (!opened) {
      _waitingForInstallPermission = false;
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
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 20,
                  vertical: 12,
                ),
                leading: CircleAvatar(
                  backgroundColor: scheme.primary.withValues(alpha: 0.14),
                  child: Icon(
                    Icons.info_outline_rounded,
                    color: scheme.primary,
                  ),
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
                  EmptyState(
                    icon: _checkError == null
                        ? Icons.check_circle_outline_rounded
                        : Icons.cloud_off_outlined,
                    title: _checkError == null
                        ? 'برنامه به‌روز است'
                        : 'بررسی نسخه انجام نشد',
                    subtitle: _checkError ?? 'نسخه جدیدی برای نصب موجود نیست.',
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
                readyToInstall: _service.hasDownloaded,
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
    required this.readyToInstall,
    required this.downloading,
    required this.progress,
    required this.onInstall,
    required this.onOpenPermissionSettings,
  });

  final AppUpdate update;
  final bool readyToInstall;
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
                    borderRadius: BorderRadius.circular(Corner.control),
                  ),
                  child: const Icon(
                    Icons.system_update_rounded,
                    color: AppColors.moneyIn,
                    size: IconSize.heading,
                  ),
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
                borderRadius: BorderRadius.circular(Corner.chip),
                child: LinearProgressIndicator(value: progress, minHeight: 10),
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
                label: Text(
                    readyToInstall ? 'نصب فایل دانلودشده' : 'دانلود و نصب'),
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
              borderRadius: BorderRadius.circular(Corner.chip),
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
                  'قبل از حذف برنامه، همهٔ ثبت‌های آفلاین را ارسال کنید و '
                  'مطمئن شوید مورد ارسال‌نشده‌ای نمانده است. حذف برنامه '
                  'ثبت‌های ارسال‌نشدهٔ همین گوشی را پاک می‌کند.\n\n'
                  'اگر اختلاف امضای نسخهٔ قدیمی تأیید شد، پس از همگام‌سازی '
                  'برنامه را حذف و نسخهٔ جدید را نصب کنید.',
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
