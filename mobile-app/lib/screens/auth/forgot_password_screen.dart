import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// Getting back in, for someone who cannot get in.
///
/// Two steps on one screen: the phone number, then the code that arrives
/// by text and the new password. Two screens would mean a back button that
/// loses the code, and the code lives five minutes.
///
/// Nobody here reads email, so there is no link and no inbox — a six-digit
/// number read off a text, which is the one flow every person in this shop
/// already knows from their bank.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key, required this.api, this.phone});

  final BakeryApi api;

  /// Carried over from the login box, so somebody who typed their number
  /// and then remembered they have no password does not type it twice.
  final String? phone;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

enum _Step { askingForCode, enteringIt }

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  late final TextEditingController _phone;
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();

  _Step _step = _Step.askingForCode;
  bool _busy = false;
  bool _hidden = true;

  /// Seconds until another code may be asked for. Without it, somebody who
  /// does not see a message in ten seconds presses the button four more
  /// times, spends four more messages, and invalidates the one that was
  /// already on its way.
  int _waitLeft = 0;
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    _phone = TextEditingController(text: widget.phone ?? '');
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _phone.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  void _startWaiting() {
    _ticker?.cancel();
    setState(() => _waitLeft = 60);

    _ticker = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();

      setState(() => _waitLeft--);
      if (_waitLeft <= 0) t.cancel();
    });
  }

  Future<void> _askForCode() async {
    final phone = latinDigits(_phone.text.trim());

    if (phone.length < 10) {
      showMessage(context, 'شمارهٔ موبایل را کامل وارد کنید.', isError: true);
      return;
    }

    setState(() => _busy = true);

    try {
      await widget.api.requestPasswordCode(phone);

      if (!mounted) return;
      setState(() => _step = _Step.enteringIt);
      _startWaiting();

      // The same sentence the server sends, and it says «if». It cannot
      // say whether the number is registered without becoming a way of
      // finding out who works here.
      showMessage(context, 'اگر این شماره در سامانه باشد، کد برایش پیامک شد.');
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    if (_password.text.length < 8) {
      showMessage(context, 'رمز تازه باید دست‌کم ۸ نویسه باشد.', isError: true);
      return;
    }

    if (_password.text != _confirm.text) {
      showMessage(context, 'رمز و تکرارش یکی نیست.', isError: true);
      return;
    }

    setState(() => _busy = true);

    try {
      await widget.api.resetPasswordWithCode(
        phone: latinDigits(_phone.text.trim()),
        code: latinDigits(_code.text.trim()),
        password: _password.text,
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(context, 'رمز عوض شد. حالا با رمز تازه وارد شوید.');
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final asking = _step == _Step.askingForCode;

    return Scaffold(
      appBar: AppBar(title: const Text('فراموشی رمز')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Icon(
                asking ? Icons.sms_rounded : Icons.password_rounded,
                size: IconSize.hero,
                color: AppColors.signalFor(theme.brightness),
              ),
              const SizedBox(height: Gap.block),

              Text(
                asking
                    ? 'شمارهٔ موبایلتان را بزنید تا کد برایتان پیامک شود.'
                    : 'کدی که پیامک شد را بزنید و رمز تازه انتخاب کنید.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: Gap.section),

              TextField(
                controller: _phone,
                enabled: asking,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'شمارهٔ موبایل',
                  hintText: '۰۹۱۵…',
                  prefixIcon: Icon(Icons.phone_android_rounded),
                ),
              ),

              if (!asking) ...[
                const SizedBox(height: Gap.block),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    letterSpacing: 8,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'کد پیامک‌شده',
                    counterText: '',
                  ),
                  maxLength: 6,
                ),
                const SizedBox(height: Gap.block),
                TextField(
                  controller: _password,
                  obscureText: _hidden,
                  decoration: InputDecoration(
                    labelText: 'رمز تازه',
                    helperText: 'دست‌کم ۸ نویسه — و نه ۱۲۳۴۵۶۷۸',
                    suffixIcon: IconButton(
                      onPressed: () => setState(() => _hidden = !_hidden),
                      icon: Icon(_hidden
                          ? Icons.visibility_rounded
                          : Icons.visibility_off_rounded),
                    ),
                  ),
                ),
                const SizedBox(height: Gap.block),
                TextField(
                  controller: _confirm,
                  obscureText: _hidden,
                  decoration: const InputDecoration(labelText: 'تکرار رمز تازه'),
                ),
              ],

              const SizedBox(height: Gap.section),
              FilledButton.icon(
                onPressed: _busy ? null : (asking ? _askForCode : _submit),
                icon: _busy
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Icon(asking ? Icons.send_rounded : Icons.check_rounded),
                label: Text(asking ? 'کد را بفرست' : 'رمز را عوض کن'),
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              ),

              if (!asking) ...[
                const SizedBox(height: Gap.item),
                TextButton(
                  onPressed: _waitLeft > 0 || _busy ? null : _askForCode,
                  child: Text(
                    _waitLeft > 0
                        ? 'ارسال دوباره تا $_waitLeft ثانیه'
                        : 'کد نرسید؟ دوباره بفرست',
                  ),
                ),
                Text(
                  'کد ۵ دقیقه معتبر است.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
