import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/signed_in_device.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';

/// Where a lost phone gets signed out.
///
/// Until this screen there were three ways a session ended and all three
/// were the side effect of something else: changing a password, resetting
/// one, and an admin switching the account off. The last is the one that
/// got used, and it costs the person the rest of their day — the account
/// is off, so they cannot record a sale on the shop's own handset either.
class MyDevicesScreen extends StatefulWidget {
  const MyDevicesScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<MyDevicesScreen> createState() => _MyDevicesScreenState();
}

class _MyDevicesScreenState extends State<MyDevicesScreen> {
  late Future<List<SignedInDevice>> _devices;

  @override
  void initState() {
    super.initState();
    _devices = widget.api.devices();
  }

  void _reload() => setState(() => _devices = widget.api.devices());

  /// Signs [device] out, after asking.
  ///
  /// Ending a session is not undoable from here — whoever holds that
  /// handset has to know the password to get back in, which when it is
  /// your own spare phone in a drawer is a trip home.
  Future<void> _signOut(SignedInDevice device) async {
    final confirmed = await _confirm(
      title: device.isCurrent ? 'خروج از همین گوشی' : 'خروج «${device.name}»',
      body: device.isCurrent
          ? 'از همین گوشی خارج می‌شوید و باید دوباره وارد شوید.'
          : 'این دستگاه باید دوباره با رمز عبور وارد شود.',
    );

    if (!confirmed) return;

    try {
      final wasThisOne = await widget.api.signOutDevice(device.id);

      if (!mounted) return;

      // Signing this phone out leaves the screen holding a list it can no
      // longer read. Hand it to the auth provider, which is what clears
      // the stored token and returns to the login screen.
      if (wasThisOne) {
        await context.read<AuthProvider>().logout();

        return;
      }

      showMessage(context, 'آن دستگاه خارج شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  /// The button somebody actually presses.
  ///
  /// Standing in the shop having just realised the phone is gone, nobody
  /// can say which row it is — and the cost of picking wrong is signing
  /// out a colleague mid-shift.
  Future<void> _signOutOthers() async {
    final confirmed = await _confirm(
      title: 'خروج از بقیهٔ دستگاه‌ها',
      body: 'همهٔ دستگاه‌ها به‌جز همین گوشی خارج می‌شوند.',
    );

    if (!confirmed) return;

    try {
      final closed = await widget.api.signOutOtherDevices();

      if (!mounted) return;

      showMessage(
        context,
        closed == 0
            ? 'دستگاه دیگری وارد نبود.'
            : '$closed دستگاه خارج شد.',
      );
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<bool> _confirm({required String title, required String body}) async {
    final answer = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('خروج'),
          ),
        ],
      ),
    );

    return answer ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: const Text('دستگاه‌های من')),
      body: FutureBuilder<List<SignedInDevice>>(
        future: _devices,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
          }

          final devices = snapshot.data ?? const <SignedInDevice>[];
          final others = devices.where((d) => !d.isCurrent).length;

          return RefreshIndicator(
            onRefresh: () async => _reload(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  'هر ردیف یک گوشی است که با حساب شما وارد شده.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 16),
                Card(
                  child: Column(
                    children: [
                      for (final device in devices) ...[
                        if (device != devices.first) const Divider(height: 1),
                        ListTile(
                          leading: Icon(
                            device.isCurrent
                                ? Icons.smartphone_rounded
                                : Icons.phone_android_rounded,
                            color: device.isCurrent ? scheme.primary : null,
                          ),
                          title: Text(device.name),
                          subtitle: Text(
                            device.isCurrent
                                ? 'همین گوشی · ${device.when}'
                                : device.when,
                          ),
                          trailing: TextButton(
                            onPressed: () => _signOut(device),
                            child: Text(
                              'خروج',
                              style: TextStyle(color: scheme.error),
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                FilledButton.tonalIcon(
                  onPressed: others == 0 ? null : _signOutOthers,
                  icon: const Icon(Icons.logout_rounded),
                  label: Text(
                    others == 0
                        ? 'دستگاه دیگری وارد نیست'
                        : 'خروج از بقیهٔ دستگاه‌ها',
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
