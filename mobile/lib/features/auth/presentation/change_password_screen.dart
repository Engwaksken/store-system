import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/widgets/common.dart';
import '../data/auth_controller.dart';

/// Client-side rules mirroring the server: min 8, letters and numbers,
/// different from the current password.
String? validateNewPassword(String? value, String current) {
  final v = value ?? '';
  if (v.length < 8) return 'At least 8 characters';
  if (!RegExp(r'[A-Za-z]').hasMatch(v) || !RegExp(r'\d').hasMatch(v)) {
    return 'Use both letters and numbers';
  }
  if (v == current) return 'Must be different from your current password';
  return null;
}

class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  bool _obscure = true;
  ApiException? _error;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit(bool forced) async {
    FocusScope.of(context).unfocus();
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).changePassword(_current.text, _next.text, _confirm.text);
      if (!mounted) return;
      showSnack(context, 'Password changed');
      // When forced, the router redirects home automatically.
      if (!forced && context.canPop()) context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final forced = user?.mustChangePassword ?? false;
    final generalError = _error != null && _error!.fieldErrors.isEmpty ? _error!.message : null;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Change password'),
        automaticallyImplyLeading: !forced,
        actions: [
          if (forced)
            TextButton(
              onPressed: () => ref.read(authControllerProvider.notifier).logout(),
              style: TextButton.styleFrom(foregroundColor: Colors.white),
              child: const Text('Sign out'),
            ),
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: ResponsiveCenter(
            maxWidth: 480,
            child: Form(
              key: _form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (forced) ...[
                    Card(
                      child: ListTile(
                        leading: const Icon(Icons.lock_reset),
                        title: const Text('Set a new password'),
                        subtitle: const Text(
                          'For your security you must choose a new password before using the app.',
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],
                  if (generalError != null) ...[
                    Text(generalError, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    const SizedBox(height: 12),
                  ],
                  TextFormField(
                    controller: _current,
                    obscureText: _obscure,
                    autofillHints: const [AutofillHints.password],
                    decoration: InputDecoration(
                      labelText: 'Current password',
                      errorText: _error?.fieldError('current_password'),
                    ),
                    validator: (v) => (v ?? '').isEmpty ? 'Enter your current password' : null,
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _next,
                    obscureText: _obscure,
                    autofillHints: const [AutofillHints.newPassword],
                    decoration: InputDecoration(
                      labelText: 'New password',
                      helperText: 'At least 8 characters, with letters and numbers',
                      errorText: _error?.fieldError('password'),
                      errorMaxLines: 3,
                    ),
                    validator: (v) => validateNewPassword(v, _current.text),
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _confirm,
                    obscureText: _obscure,
                    autofillHints: const [AutofillHints.newPassword],
                    decoration: InputDecoration(
                      labelText: 'Confirm new password',
                      errorText: _error?.fieldError('password_confirmation'),
                    ),
                    validator: (v) => v != _next.text ? 'Passwords do not match' : null,
                    onFieldSubmitted: (_) => _submit(forced),
                  ),
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: !_obscure,
                    onChanged: (v) => setState(() => _obscure = !(v ?? false)),
                    title: const Text('Show passwords'),
                    controlAffinity: ListTileControlAffinity.leading,
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _busy ? null : () => _submit(forced),
                    child: _busy
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                          )
                        : const Text('Change password'),
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
