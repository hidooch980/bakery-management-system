import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../widgets/common.dart';

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();

  bool _obscureCurrent = true;
  bool _obscureNext = true;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final auth = context.read<AuthProvider>();
    final message = await auth.changePassword(_current.text, _next.text);

    if (!mounted) return;

    if (message != null) {
      // Tokens are revoked server-side, so the app returns to the login screen.
      showMessage(context, message);
    } else if (auth.error != null) {
      showMessage(context, auth.error!, isError: true);
      auth.clearError();
    }
  }

  @override
  Widget build(BuildContext context) {
    final busy = context.watch<AuthProvider>().busy;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(
        title: const Text('تغییر رمز عبور'),
        actions: const [ThemeToggleButton()],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(
                      children: [
                        Icon(Icons.info_outline_rounded, color: scheme.primary),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'پس از تغییر رمز، از همه دستگاه‌ها خارج می‌شوید و باید دوباره وارد شوید.',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _current,
                  obscureText: _obscureCurrent,
                  decoration: InputDecoration(
                    labelText: 'رمز عبور فعلی',
                    prefixIcon: const Icon(Icons.lock_outline_rounded),
                    suffixIcon: IconButton(
                      onPressed: () =>
                          setState(() => _obscureCurrent = !_obscureCurrent),
                      icon: Icon(_obscureCurrent
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined),
                    ),
                  ),
                  validator: (v) => (v == null || v.isEmpty)
                      ? 'رمز عبور فعلی را وارد کنید'
                      : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _next,
                  obscureText: _obscureNext,
                  decoration: InputDecoration(
                    labelText: 'رمز عبور جدید',
                    helperText: 'حداقل ۸ کاراکتر',
                    prefixIcon: const Icon(Icons.lock_reset_rounded),
                    suffixIcon: IconButton(
                      onPressed: () =>
                          setState(() => _obscureNext = !_obscureNext),
                      icon: Icon(_obscureNext
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined),
                    ),
                  ),
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'رمز عبور جدید را وارد کنید';
                    if (v.length < 8) return 'رمز عبور باید حداقل ۸ کاراکتر باشد';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _confirm,
                  obscureText: _obscureNext,
                  decoration: const InputDecoration(
                    labelText: 'تکرار رمز عبور جدید',
                    prefixIcon: Icon(Icons.check_circle_outline_rounded),
                  ),
                  validator: (v) =>
                      v != _next.text ? 'تکرار رمز عبور مطابقت ندارد' : null,
                ),
                const SizedBox(height: 28),
                FilledButton.icon(
                  onPressed: busy ? null : _submit,
                  icon: busy
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.save_rounded),
                  label: Text(busy ? 'در حال ذخیره…' : 'تغییر رمز عبور'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
