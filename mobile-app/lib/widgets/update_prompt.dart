import 'package:flutter/material.dart';

import '../screens/shared/update_screen.dart';
import '../services/update_service.dart';
import '../theme/app_theme.dart';

/// Warns, once per launch and then persistently, that a newer build is out.
///
/// The updater used to be reachable only from Settings, which is fine until
/// an update actually matters — when the backend moves, a phone still on the
/// old build is looking for a server that is being switched off, and nobody
/// thinks to go looking in Settings for the fix.
///
/// So it warns rather than mentions: a dialog on the first screen after
/// sign-in naming both versions, and — for anyone who waves it off — a bar
/// that stays across every screen until the update is installed. It still
/// says nothing at all when there is nothing to say, and a failed check
/// stays silent rather than putting an error in front of someone working.
class UpdatePrompt extends StatefulWidget {
  const UpdatePrompt({super.key, required this.child, this.service});

  final Widget child;

  /// Injected in tests so the check never touches the network.
  final UpdateService? service;

  /// Lets a test start from a clean session.
  @visibleForTesting
  static void resetForTest() => _UpdatePromptState._askedThisSession = false;

  @override
  State<UpdatePrompt> createState() => _UpdatePromptState();
}

class _UpdatePromptState extends State<UpdatePrompt> {
  /// Held across rebuilds so switching screens does not ask again.
  static bool _askedThisSession = false;

  @override
  void initState() {
    super.initState();

    // After the first frame: a dialog needs a mounted navigator, and the
    // home screen should be on-screen before anything is laid over it.
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
  }

  Future<void> _check() async {
    if (_askedThisSession) return;
    _askedThisSession = true;

    final service = widget.service ?? UpdateService();
    final update = await service.checkForUpdate();

    if (update == null || !mounted) return;

    final current = await service.currentVersion();

    if (!mounted) return;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        icon: const Icon(Icons.warning_amber_rounded,
            size: 34, color: AppColors.emberHot),
        title: const Text('بروزرسانی لازم است'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _VersionLine(label: 'نسخه فعلی شما', version: current, faded: true),
            const SizedBox(height: 6),
            _VersionLine(label: 'نسخه جدید', version: update.version),
            const SizedBox(height: 14),
            const Text(
              'تا زمانی که بروزرسانی نکنید، ممکن است برنامه به سرور وصل نشود.',
              textAlign: TextAlign.center,
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.of(dialogContext).pop();
              _showStandingWarning(update);
            },
            child: const Text('بعداً'),
          ),
          FilledButton.icon(
            onPressed: () {
              Navigator.of(dialogContext).pop();
              _openUpdateScreen();
            },
            icon: const Icon(Icons.system_update_rounded, size: 18),
            label: const Text('بروزرسانی'),
          ),
        ],
      ),
    );
  }

  /// Stays up across screens, because waving the dialog off does not make
  /// the old build any more able to reach the server.
  void _showStandingWarning(AppUpdate update) {
    if (!mounted) return;

    ScaffoldMessenger.of(context).showMaterialBanner(
      MaterialBanner(
        backgroundColor: AppColors.emberHot.withValues(alpha: 0.15),
        leading: const Icon(Icons.warning_amber_rounded, color: AppColors.emberHot),
        content: Text('نسخه ${update.version} منتشر شده — بروزرسانی نکرده‌اید.'),
        actions: [
          TextButton(
            onPressed: () {
              ScaffoldMessenger.of(context).hideCurrentMaterialBanner();
              _openUpdateScreen();
            },
            child: const Text('بروزرسانی'),
          ),
        ],
      ),
    );
  }

  void _openUpdateScreen() {
    ScaffoldMessenger.of(context).hideCurrentMaterialBanner();

    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const UpdateScreen()),
    );
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

/// One version, labelled — so the user can see what they have against what
/// is waiting, rather than being told only that "a new version exists".
class _VersionLine extends StatelessWidget {
  const _VersionLine({
    required this.label,
    required this.version,
    this.faded = false,
  });

  final String label;
  final String version;
  final bool faded;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 13,
            color: faded ? scheme.onSurfaceVariant : scheme.onSurface,
          ),
        ),
        Text(
          version,
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w800,
            color: faded ? scheme.onSurfaceVariant : scheme.primary,
          ),
        ),
      ],
    );
  }
}
