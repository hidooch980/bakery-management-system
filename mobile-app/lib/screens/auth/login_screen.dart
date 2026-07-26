import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../services/biometric_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _loginController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _obscure = true;

  /// Whether to save the login for a fingerprint or face unlock next time.
  bool _remember = false;

  /// Null until the device has been asked what it supports.
  BiometricAvailability? _availability;
  bool _biometricEnabled = false;
  String _biometricLabel = 'اثر انگشت';
  late final AnimationController _animation = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 700),
  )..forward();

  @override
  void initState() {
    super.initState();
    _prepareBiometrics();
  }

  Future<void> _prepareBiometrics() async {
    final auth = context.read<AuthProvider>().biometrics;

    final availability = await auth.availability();
    final enabled = await auth.isEnabled();
    final label = await auth.enrolledLabel();
    final savedLogin = await auth.savedLogin();

    if (!mounted) return;

    setState(() {
      _availability = availability;
      _biometricEnabled = enabled;
      _biometricLabel = label;
      // Pre-fill the username so only the fingerprint is left to give.
      if (savedLogin != null) _loginController.text = savedLogin;
    });

    // Offer the unlock straight away, so the common case is one tap.
    if (enabled && availability == BiometricAvailability.ready) {
      await _unlock();
    }
  }

  Future<void> _unlock() async {
    final auth = context.read<AuthProvider>();
    final ok = await auth.loginWithBiometrics();

    if (!mounted || ok) return;

    // A saved password that no longer works is cleared by the provider, so
    // reflect that here rather than leaving a button that cannot succeed.
    final stillEnabled = await auth.biometrics.isEnabled();

    if (!mounted) return;
    setState(() => _biometricEnabled = stillEnabled);
  }

  @override
  void dispose() {
    _loginController.dispose();
    _passwordController.dispose();
    _animation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    FocusScope.of(context).unfocus();

    final auth = context.read<AuthProvider>();
    final ok = await auth.login(
      _loginController.text.trim(),
      _passwordController.text,
      rememberForBiometrics: _remember,
    );

    if (!mounted) return;

    if (!ok && auth.error != null) {
      showMessage(context, auth.error!, isError: true);
      auth.clearError();
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final busy = context.watch<AuthProvider>().busy;

    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              scheme.primary.withValues(alpha: 0.12),
              scheme.surface.withValues(alpha: 0.0),
            ],
          ),
        ),
        child: SafeArea(
          child: Stack(
            children: [
              const Positioned(
                top: 8,
                left: 8,
                child: ThemeToggleButton(),
              ),
              Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 440),
                    child: FadeTransition(
                      opacity: _animation,
                      child: SlideTransition(
                        position: Tween(
                          begin: const Offset(0, 0.06),
                          end: Offset.zero,
                        ).animate(CurvedAnimation(
                          parent: _animation,
                          curve: Curves.easeOutCubic,
                        )),
                        child: Form(
                          key: _formKey,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              _Logo(scheme: scheme),
                              const SizedBox(height: 28),
                              Text(
                                'سیستم مدیریت نانوایی',
                                textAlign: TextAlign.center,
                                style: Theme.of(context)
                                    .textTheme
                                    .headlineSmall
                                    ?.copyWith(fontWeight: FontWeight.w800),
                              ),
                              const SizedBox(height: 8),
                              Text(
                                'برای ورود، اطلاعات حساب خود را وارد کنید',
                                textAlign: TextAlign.center,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(color: scheme.onSurfaceVariant),
                              ),
                              const SizedBox(height: 32),
                              TextFormField(
                                controller: _loginController,
                                textInputAction: TextInputAction.next,
                                keyboardType: TextInputType.emailAddress,
                                decoration: const InputDecoration(
                                  labelText: 'ایمیل یا شماره تلفن',
                                  prefixIcon: Icon(Icons.person_outline_rounded),
                                ),
                                validator: (value) =>
                                    (value == null || value.trim().isEmpty)
                                        ? 'ایمیل یا شماره تلفن را وارد کنید'
                                        : null,
                              ),
                              const SizedBox(height: 16),
                              TextFormField(
                                controller: _passwordController,
                                obscureText: _obscure,
                                textInputAction: TextInputAction.done,
                                onFieldSubmitted: (_) => _submit(),
                                decoration: InputDecoration(
                                  labelText: 'رمز عبور',
                                  prefixIcon: const Icon(Icons.lock_outline_rounded),
                                  suffixIcon: IconButton(
                                    onPressed: () =>
                                        setState(() => _obscure = !_obscure),
                                    icon: Icon(_obscure
                                        ? Icons.visibility_outlined
                                        : Icons.visibility_off_outlined),
                                  ),
                                ),
                                validator: (value) =>
                                    (value == null || value.isEmpty)
                                        ? 'رمز عبور را وارد کنید'
                                        : null,
                              ),
                              if (_availability == BiometricAvailability.ready) ...[
                                const SizedBox(height: 8),
                                SwitchListTile(
                                  value: _remember,
                                  onChanged: (value) =>
                                      setState(() => _remember = value),
                                  title: Text('ورود بعدی با $_biometricLabel'),
                                  subtitle: const Text(
                                    'رمز به‌صورت رمزنگاری‌شده روی همین دستگاه ذخیره می‌شود',
                                  ),
                                  secondary: const Icon(Icons.fingerprint_rounded),
                                  contentPadding: EdgeInsets.zero,
                                ),
                              ],
                              const SizedBox(height: 20),
                              FilledButton.icon(
                                onPressed: busy ? null : _submit,
                                icon: busy
                                    ? const SizedBox(
                                        width: 20,
                                        height: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : const Icon(Icons.login_rounded),
                                label: Text(busy ? 'در حال ورود…' : 'ورود'),
                              ),
                              if (_biometricEnabled &&
                                  _availability == BiometricAvailability.ready) ...[
                                const SizedBox(height: 14),
                                OutlinedButton.icon(
                                  onPressed: busy ? null : _unlock,
                                  icon: const Icon(Icons.fingerprint_rounded),
                                  label: Text('ورود با $_biometricLabel'),
                                ),
                              ],
                              const SizedBox(height: 20),
                              Text(
                                'حساب کاربری فقط توسط مدیر ساخته می‌شود',
                                textAlign: TextAlign.center,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodySmall
                                    ?.copyWith(color: scheme.onSurfaceVariant),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Logo extends StatelessWidget {
  const _Logo({required this.scheme});

  final ColorScheme scheme;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        width: 104,
        height: 104,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [AppColors.wheat, AppColors.crust],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(30),
          boxShadow: [
            BoxShadow(
              color: AppColors.crust.withValues(alpha: 0.3),
              blurRadius: 24,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: const Icon(Icons.bakery_dining_rounded,
            size: 54, color: Colors.white),
      ),
    );
  }
}
