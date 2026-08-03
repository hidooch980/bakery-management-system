import 'package:flutter/material.dart';

import '../screens/shared/update_screen.dart';
import '../services/update_service.dart';

/// Tells the user, once per launch, that a newer build is waiting.
///
/// The updater used to be reachable only from Settings, which is fine until
/// an update actually matters — when the backend moves, a phone still on the
/// old build is looking for a server that is being switched off, and nobody
/// thinks to go looking in Settings for the fix.
///
/// Deliberately not a wall: the check runs in the background, says nothing
/// at all when there is nothing to say, and can be waved off for the rest of
/// the session. Nothing here blocks the day's work.
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

    final update = await (widget.service ?? UpdateService()).checkForUpdate();

    if (update == null || !mounted) return;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        icon: const Icon(Icons.system_update_rounded, size: 32),
        title: const Text('نسخه جدید آماده است'),
        content: Text(
          'نسخه ${update.version} منتشر شده است.'
          '\n\nبروزرسانی را نصب کنید تا برنامه با سرور هماهنگ بماند.',
          textAlign: TextAlign.center,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('بعداً'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.of(dialogContext).pop();
              Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const UpdateScreen()),
              );
            },
            child: const Text('بروزرسانی'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
