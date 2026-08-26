import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// Whether the shop's data is being kept, said to the person responsible
/// for it.
///
/// The dumps have run twice a day for weeks and there was no way to see
/// that without an ssh session — so no way to notice if they stopped.
/// That is the failure this screen is for: not a backup that fails
/// loudly, but one that quietly is not happening while everything else
/// looks fine.
///
/// It shows status and takes one on demand. It does not hand the file
/// over, and should not: the whole shop — wages, debts, every customer —
/// in one download is not a convenience worth the risk, and a .sql.gz is
/// of no use on a phone anyway.
class BackupScreen extends StatefulWidget {
  const BackupScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<BackupScreen> createState() => _BackupScreenState();
}

class _BackupScreenState extends State<BackupScreen> {
  late Future<Map<String, dynamic>> _future;
  bool _running = false;

  @override
  void initState() {
    super.initState();
    _future = widget.api.backupStatus();
  }

  Future<void> _refresh() async {
    final future = widget.api.backupStatus();
    setState(() => _future = future);
    await future;
  }

  Future<void> _takeOne() async {
    setState(() => _running = true);

    try {
      await widget.api.takeBackup();
      if (!mounted) return;
      showMessage(context, 'پشتیبان گرفته شد.');
      await _refresh();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _running = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('پشتیبان‌گیری')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              final error = snapshot.error;

              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _Note(
                    text: error is ApiException
                        ? error.message
                        : 'وضعیت پشتیبان خوانده نشد.',
                    isError: true,
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            final status = snapshot.data!;

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _StatusCard(status: status),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _running ? null : _takeOne,
                  icon: _running
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2.2),
                        )
                      : const Icon(Icons.backup_rounded),
                  label: Text(_running ? 'در حال گرفتن…' : 'همین حالا پشتیبان بگیر'),
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(52),
                  ),
                ),
                const SizedBox(height: 20),
                _RecentList(status: status),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _StatusCard extends StatelessWidget {
  const _StatusCard({required this.status});

  final Map<String, dynamic> status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final stale = status['is_stale'] == true;
    final count = (status['count'] as num?)?.toInt() ?? 0;
    final hours = (status['hours_since'] as num?)?.toInt();
    final tone = stale ? AppColors.moneyOut : AppColors.moneyIn;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  stale ? Icons.error_outline_rounded : Icons.verified_rounded,
                  color: tone,
                  size: IconSize.button,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    // The headline is the answer to the only question
                    // being asked, in words: is the shop's data safe.
                    count == 0
                        ? 'هیچ پشتیبانی وجود ندارد.'
                        : stale
                            ? 'پشتیبان‌گیری عقب افتاده است.'
                            : 'پشتیبان‌گیری به‌روز است.',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: tone,
                        ),
                  ),
                ),
              ],
            ),
            if (count > 0) ...[
              const Divider(height: 26),
              _Line(
                label: 'آخرین پشتیبان',
                value: '${status['latest_at_display'] ?? '—'}',
              ),
              const SizedBox(height: 8),
              _Line(
                label: 'یعنی',
                value: hours == null
                    ? '—'
                    : hours == 0
                        ? 'همین ساعت'
                        : '$hours ساعت پیش',
              ),
              const SizedBox(height: 8),
              _Line(label: 'تعداد نسخه‌ها', value: '$count'),
            ],
            const SizedBox(height: 14),
            Text(
              // Said plainly, because the owner is the one who has to
              // know where his data actually is.
              'پشتیبان‌ها روزی دو بار روی سرور گرفته می‌شوند و هر شب روی '
              'کامپیوتر مغازه کپی می‌شوند.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Row(
      children: [
        Text(
          label,
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
        ),
        const Spacer(),
        Text(
          value,
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w700,
                color: scheme.onSurface,
              ),
        ),
      ],
    );
  }
}

class _RecentList extends StatelessWidget {
  const _RecentList({required this.status});

  final Map<String, dynamic> status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final recent = (status['recent'] as List?) ?? const [];

    if (recent.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'آخرین نسخه‌ها',
          style: Theme.of(context).textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w800,
                color: scheme.onSurfaceVariant,
              ),
        ),
        const SizedBox(height: 8),
        Card(
          child: Column(
            children: [
              for (var i = 0; i < recent.length; i++) ...[
                if (i > 0) const Divider(height: 1),
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 12,
                  ),
                  child: Row(
                    children: [
                      Icon(
                        Icons.description_outlined,
                        size: IconSize.row,
                        color: scheme.onSurfaceVariant,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          '${(recent[i] as Map)['at_display'] ?? '—'}',
                          style:
                              Theme.of(context).textTheme.bodyMedium?.copyWith(
                                    color: scheme.onSurface,
                                  ),
                        ),
                      ),
                      Text(
                        _size(((recent[i] as Map)['size'] as num?)?.toInt() ?? 0),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

String _size(int bytes) {
  if (bytes >= 1024 * 1024) {
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} مگابایت';
  }

  return '${(bytes / 1024).round()} کیلوبایت';
}

class _Note extends StatelessWidget {
  const _Note({required this.text, this.isError = false, this.onRetry});

  final String text;
  final bool isError;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              text,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: isError ? scheme.error : scheme.onSurfaceVariant,
                  ),
            ),
            if (onRetry != null)
              TextButton(onPressed: onRetry, child: const Text('دوباره')),
          ],
        ),
      ),
    );
  }
}
