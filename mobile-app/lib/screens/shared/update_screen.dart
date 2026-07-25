import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../services/update_service.dart';
import '../../widgets/common.dart';

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
      showMessage(context, '$e', isError: true);
    } finally {
      if (mounted) setState(() => _downloading = false);
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
  });

  final AppUpdate update;
  final bool downloading;
  final double progress;
  final VoidCallback onInstall;

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
                    color: const Color(0xFF2E9E6B).withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: const Icon(Icons.system_update_rounded,
                      color: Color(0xFF2E9E6B), size: 28),
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
            Text(
              'برای نصب، باید اجازه «نصب برنامه‌های ناشناس» را به این برنامه بدهید.',
              textAlign: TextAlign.center,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}
