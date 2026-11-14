import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/providers.dart';
import '../../../core/config/app_config.dart';
import '../../../core/fake/fake_backend.dart';
import '../../../core/theme/app_theme.dart';
import '../data/auth_controller.dart';
import '../data/auth_repository.dart';
import 'splash_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _error = null;
      _fieldErrors = const {};
    });
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      final device = await ref.read(deviceNameProvider.future);
      await ref.read(authControllerProvider.notifier).login(_email.text, _password.text, device);
      // The router redirects on success.
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.isValidation && e.fieldErrors.isNotEmpty) {
          _fieldErrors = {
            for (final k in ['email', 'password'])
              if (e.fieldError(k) != null) k: e.fieldError(k)!,
          };
          if (_fieldErrors.isEmpty) _error = e.message;
        } else {
          _error = e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final config = ref.watch(appConfigProvider);
    final auth = ref.watch(authControllerProvider);
    final signedOutReason = auth is AuthSignedOut ? auth.message : null;
    final banner = _error ?? signedOutReason;

    return Scaffold(
      backgroundColor: Brand.navy,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                children: [
                  const BrandMark(size: 64),
                  const SizedBox(height: 14),
                  Text(
                    AppConfig.appName,
                    style: theme.textTheme.headlineSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text('Construction store management', style: TextStyle(color: Colors.white.withValues(alpha: 0.8))),
                  const SizedBox(height: 24),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(20),
                      child: Form(
                        key: _form,
                        child: AutofillGroup(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Text('Sign in', style: theme.textTheme.titleLarge, textAlign: TextAlign.left),
                              const SizedBox(height: 16),
                              if (config.useFakeApi) ...[
                                _Notice(
                                  icon: Icons.science_outlined,
                                  text: 'Demo mode: any e-mail, password "${FakeBackend.password}". '
                                      'Use an e-mail containing "engineer" for a site engineer, "new" to test the '
                                      'forced password change.',
                                ),
                                const SizedBox(height: 12),
                              ],
                              if (banner != null) ...[
                                _Notice(icon: Icons.error_outline, text: banner, error: true),
                                const SizedBox(height: 12),
                              ],
                              TextFormField(
                                key: const Key('login-email'),
                                controller: _email,
                                keyboardType: TextInputType.emailAddress,
                                textInputAction: TextInputAction.next,
                                autofillHints: const [AutofillHints.username, AutofillHints.email],
                                autocorrect: false,
                                enabled: !_busy,
                                decoration: InputDecoration(
                                  labelText: 'E-mail',
                                  prefixIcon: const Icon(Icons.alternate_email),
                                  errorText: _fieldErrors['email'],
                                  errorMaxLines: 3,
                                ),
                                validator: (v) {
                                  final s = (v ?? '').trim();
                                  if (s.isEmpty) return 'Enter your e-mail';
                                  if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s)) {
                                    return 'Enter a valid e-mail address';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 14),
                              TextFormField(
                                key: const Key('login-password'),
                                controller: _password,
                                obscureText: _obscure,
                                textInputAction: TextInputAction.done,
                                autofillHints: const [AutofillHints.password],
                                enabled: !_busy,
                                onFieldSubmitted: (_) => _submit(),
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: const Icon(Icons.lock_outline),
                                  errorText: _fieldErrors['password'],
                                  suffixIcon: IconButton(
                                    tooltip: _obscure ? 'Show password' : 'Hide password',
                                    icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                                    onPressed: () => setState(() => _obscure = !_obscure),
                                  ),
                                ),
                                validator: (v) => (v ?? '').isEmpty ? 'Enter your password' : null,
                              ),
                              const SizedBox(height: 20),
                              FilledButton(
                                key: const Key('login-submit'),
                                onPressed: _busy ? null : _submit,
                                child: _busy
                                    ? const SizedBox(
                                        width: 22,
                                        height: 22,
                                        child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                                      )
                                    : const Text('Sign in'),
                              ),
                              const SizedBox(height: 12),
                              Text(
                                'Forgot your password? Ask your administrator to reset it.',
                                style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                                textAlign: TextAlign.center,
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.text, this.error = false});
  final IconData icon;
  final String text;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final c = ToneColors.of(error ? Tone.rejected : Tone.gold, Theme.of(context).brightness);
    return Semantics(
      liveRegion: error,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: c.background, borderRadius: BorderRadius.circular(10)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: c.foreground, size: 20),
            const SizedBox(width: 10),
            Expanded(child: Text(text, style: TextStyle(color: c.foreground))),
          ],
        ),
      ),
    );
  }
}
