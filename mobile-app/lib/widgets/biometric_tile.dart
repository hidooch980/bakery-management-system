import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import '../services/biometric_service.dart';
import 'common.dart';

/// Settings row for turning fingerprint or face unlock on and off.
///
/// Turning it *on* asks for the password again and verifies it against the
/// server before storing anything, so a mistyped password cannot be saved
/// and then fail silently on every future launch.
class BiometricTile extends StatefulWidget {
  const BiometricTile({super.key});

  @override
  State<BiometricTile> createState() => _BiometricTileState();
}

class _BiometricTileState extends State<BiometricTile> {
  BiometricAvailability? _availability;
  bool _enabled = false;
  String _label = 'اثر انگشت';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final service = context.read<AuthProvider>().biometrics;

    final availability = await service.availability();
    final enabled = await service.isEnabled();
    final label = await service.enrolledLabel();

    if (!mounted) return;

    setState(() {
      _availability = availability;
      _enabled = enabled;
      _label = label;
    });
  }

  Future<void> _toggle(bool value) async {
    final auth = context.read<AuthProvider>();

    if (!value) {
      await auth.biometrics.disable();
      if (mounted) setState(() => _enabled = false);
      return;
    }

    final password = await _askForPassword();

    if (password == null || !mounted) return;

    // The login identifier the account was created with; the phone is what
    // staff actually sign in with, with email as the fallback.
    final identifier = auth.user?.phone?.isNotEmpty == true
        ? auth.user!.phone!
        : (auth.user?.email ?? '');

    if (identifier.isEmpty) {
      if (mounted) {
        showMessage(context,
            'شناسه ورود شما در دسترس نیست. لطفاً دوباره وارد شوید.',
            isError: true);
      }
      return;
    }

    setState(() => _busy = true);

    // Verified against the server rather than trusted, so only a password
    // that actually works is ever stored.
    final ok = await auth.verifyPassword(identifier, password);

    if (!mounted) return;

    if (!ok) {
      setState(() => _busy = false);
      showMessage(context, 'رمز عبور نادرست است.', isError: true);
      return;
    }

    await auth.biometrics.enable(login: identifier, password: password);

    if (!mounted) return;

    setState(() {
      _enabled = true;
      _busy = false;
    });

    showMessage(context, 'ورود با $_label فعال شد.');
  }

  Future<String?> _askForPassword() {
    final controller = TextEditingController();

    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.fingerprint_rounded, size: 32),
        title: Text('فعال‌سازی ورود با $_label'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'برای ذخیره امن رمز روی این دستگاه، یک بار رمز عبور خود را وارد کنید.',
            ),
            const SizedBox(height: 16),
            TextField(
              controller: controller,
              obscureText: true,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'رمز عبور',
                prefixIcon: Icon(Icons.lock_outline_rounded),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text),
            child: const Text('تأیید'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Nothing useful to offer on a device with no sensor.
    if (_availability == BiometricAvailability.unsupported) {
      return const SizedBox.shrink();
    }

    if (_availability == BiometricAvailability.notEnrolled) {
      return const ListTile(
        leading: Icon(Icons.fingerprint_rounded),
        title: Text('ورود با اثر انگشت و چهره'),
        subtitle: Text(
          'ابتدا در تنظیمات دستگاه، اثر انگشت یا چهره خود را ثبت کنید.',
        ),
        enabled: false,
      );
    }

    return SwitchListTile(
      value: _enabled,
      onChanged: _busy ? null : _toggle,
      secondary: _busy
          ? const SizedBox(
              width: 24,
              height: 24,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : const Icon(Icons.fingerprint_rounded),
      title: Text('ورود با $_label'),
      subtitle: Text(
        _enabled
            ? 'رمز به‌صورت رمزنگاری‌شده روی همین دستگاه ذخیره شده است'
            : 'به‌جای تایپ رمز، با $_label وارد شوید',
      ),
    );
  }
}
