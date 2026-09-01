import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// Signing the shop in to its own card terminal on nanino.
///
/// The card reader is what the flour quota is measured against, and its
/// figures reached this system by somebody opening another website and
/// reading them off the screen.
///
/// nanino asks for a captcha and an SMS code. **The person supplies
/// both.** A captcha exists to require a person, and nothing here tries
/// to get around that — the image is shown and what is typed goes
/// straight through.
///
/// This screen connects. Reading the sales history comes after somebody
/// has proved a session can be obtained at all.
class NaninoScreen extends StatefulWidget {
  const NaninoScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<NaninoScreen> createState() => _NaninoScreenState();
}

class _NaninoScreenState extends State<NaninoScreen> {
  final _mobile = TextEditingController();
  final _national = TextEditingController();
  final _captcha = TextEditingController();
  final _code = TextEditingController();

  Map<String, dynamic>? _status;
  String? _captchaImage;
  String? _accessKey;

  bool _loading = true;
  bool _busy = false;
  bool _codeSent = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _mobile.dispose();
    _national.dispose();
    _captcha.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    try {
      final status = await widget.api.naninoStatus();
      if (!mounted) return;

      setState(() {
        _status = status;
        // Prefilled, so the owner is not typing his own national number
        // into a phone every time the session lapses.
        _mobile.text = '${status['mobile'] ?? ''}';
        _national.text = '${status['national_number'] ?? ''}';
        _loading = false;
      });

      if (status['connected'] != true) await _newCaptcha();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _newCaptcha() async {
    try {
      final captcha = await widget.api.naninoCaptcha();
      if (!mounted) return;

      setState(() {
        _captchaImage = '${captcha['image'] ?? ''}';
        _accessKey = '${captcha['access_key'] ?? ''}';
        _captcha.clear();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    }
  }

  Future<void> _sendCode() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.api.naninoRequestCode(
        // A Persian keyboard types «۰۹۱۵…», and nanino reads figures.
        mobile: latinDigits(_mobile.text.trim()),
        nationalNumber: latinDigits(_national.text.trim()),
        accessKey: _accessKey ?? '',
        // Not the captcha: it is letters and figures read off an image,
        // and what the person typed is the point of asking them.
        captcha: _captcha.text.trim(),
      );

      if (!mounted) return;
      setState(() => _codeSent = true);
      showMessage(context, 'کد به گوشی شما ارسال شد.');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
      // A refused captcha is spent. Asking them to retype the same one
      // would fail again for a reason they cannot see.
      await _newCaptcha();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _connect() async {
    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.api.naninoConnect(
        mobile: latinDigits(_mobile.text.trim()),
        nationalNumber: latinDigits(_national.text.trim()),
        code: latinDigits(_code.text.trim()),
      );

      if (!mounted) return;
      showMessage(context, 'به نانینو وصل شد.');
      _code.clear();
      _codeSent = false;
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _disconnect() async {
    try {
      await widget.api.naninoDisconnect();
      if (!mounted) return;
      showMessage(context, 'اتصال قطع شد.');
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final connected = _status?['connected'] == true;

    return Scaffold(
      appBar: AppBar(title: const Text('اتصال به نانینو')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (connected)
                  _Connected(
                    at: '${_status?['connected_at_display'] ?? ''}',
                    onDisconnect: _disconnect,
                  )
                else ...[
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(
                            'برای خواندن سوابق کارتخوان، یک بار وارد '
                            'حساب نانینوی خودتان شوید.',
                            style: Theme.of(context)
                                .textTheme
                                .bodyMedium
                                ?.copyWith(color: scheme.onSurface),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _mobile,
                            keyboardType: TextInputType.phone,
                            decoration: const InputDecoration(
                              labelText: 'شمارهٔ همراه',
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _national,
                            keyboardType: TextInputType.number,
                            decoration: const InputDecoration(
                              labelText: 'کد ملی',
                            ),
                          ),
                          const SizedBox(height: 16),
                          _CaptchaBox(
                            image: _captchaImage,
                            controller: _captcha,
                            onRefresh: _newCaptcha,
                          ),
                          const SizedBox(height: 16),
                          FilledButton(
                            onPressed: _busy ? null : _sendCode,
                            style: FilledButton.styleFrom(
                              minimumSize: const Size.fromHeight(50),
                            ),
                            child: Text(_busy ? 'صبر کنید…' : 'ارسال کد'),
                          ),
                          if (_codeSent) ...[
                            const Divider(height: 32),
                            TextField(
                              controller: _code,
                              keyboardType: TextInputType.number,
                              decoration: const InputDecoration(
                                labelText: 'کد پیامک‌شده',
                              ),
                            ),
                            const SizedBox(height: 12),
                            FilledButton(
                              onPressed: _busy ? null : _connect,
                              style: FilledButton.styleFrom(
                                minimumSize: const Size.fromHeight(50),
                              ),
                              child: const Text('اتصال'),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 14),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Row(
                        children: [
                          Icon(Icons.error_outline_rounded,
                              color: scheme.error, size: IconSize.row),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              _error!,
                              style: Theme.of(context)
                                  .textTheme
                                  .bodyMedium
                                  ?.copyWith(color: scheme.error),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                Text(
                  // Said plainly rather than discovered later. This is
                  // nanino's own internal interface, not a published one.
                  'این اتصال از رابط داخلی نانینو استفاده می‌کند و ممکن است '
                  'با تغییر سایت آنها از کار بیفتد. اگر افتاد، همین‌جا '
                  'گفته می‌شود.',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
    );
  }
}

class _CaptchaBox extends StatelessWidget {
  const _CaptchaBox({
    required this.image,
    required this.controller,
    required this.onRefresh,
  });

  final String? image;
  final TextEditingController controller;
  final VoidCallback onRefresh;

  /// The image arrives as a data URI or as bare base64, depending on how
  /// nanino feels; both are decoded rather than one being assumed.
  Uint8List? get _bytes {
    final value = image;

    if (value == null || value.isEmpty) return null;

    final payload = value.contains(',') ? value.split(',').last : value;

    try {
      return base64Decode(payload);
    } catch (_) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final bytes = _bytes;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Container(
                height: 62,
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                alignment: Alignment.center,
                child: bytes == null
                    ? Text(
                        'تصویر نیامد',
                        style: TextStyle(color: scheme.error),
                      )
                    : Image.memory(bytes, fit: BoxFit.contain),
              ),
            ),
            IconButton(
              onPressed: onRefresh,
              icon: const Icon(Icons.refresh_rounded),
              tooltip: 'تصویر تازه',
            ),
          ],
        ),
        const SizedBox(height: 10),
        TextField(
          controller: controller,
          decoration: const InputDecoration(
            labelText: 'کد امنیتی تصویر',
          ),
        ),
      ],
    );
  }
}

class _Connected extends StatelessWidget {
  const _Connected({required this.at, required this.onDisconnect});

  final String at;
  final VoidCallback onDisconnect;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.verified_rounded,
                    color: AppColors.moneyIn, size: IconSize.button),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'به نانینو وصل است.',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: AppColors.moneyIn,
                        ),
                  ),
                ),
              ],
            ),
            if (at.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                'از $at',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
              ),
            ],
            const SizedBox(height: 14),
            OutlinedButton(
              onPressed: onDisconnect,
              child: const Text('قطع اتصال'),
            ),
          ],
        ),
      ),
    );
  }
}
