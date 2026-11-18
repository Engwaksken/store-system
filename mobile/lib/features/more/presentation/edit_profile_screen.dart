import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';

final _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

/// The signed-in user edits their own name, e-mail and phone (`PUT /me`).
/// Changing the e-mail, which is used to sign in, also asks for the current password.
class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key});

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _email;
  late final TextEditingController _phone;
  final _password = TextEditingController();
  late final String _originalEmail;
  bool _busy = false;
  bool _obscure = true;
  ApiException? _error;

  @override
  void initState() {
    super.initState();
    final user = ref.read(currentUserProvider);
    _name = TextEditingController(text: user?.name ?? '');
    _email = TextEditingController(text: user?.email ?? '');
    _phone = TextEditingController(text: user?.phone ?? '');
    _originalEmail = (user?.email ?? '').trim().toLowerCase();
    _email.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  bool get _emailChanged => _email.text.trim().toLowerCase() != _originalEmail;

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(authControllerProvider.notifier)
          .updateProfile(
            name: _name.text.trim(),
            email: _email.text.trim(),
            phone: _phone.text.trim(),
            currentPassword: _emailChanged ? _password.text : null,
          );
      if (!mounted) return;
      showSnack(context, 'Profile updated');
      if (context.canPop()) context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final theme = Theme.of(context);
    final generalError = _error != null && _error!.fieldErrors.isEmpty ? _error!.message : null;

    return Scaffold(
      appBar: AppBar(title: const Text('Edit profile')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ResponsiveCenter(
            maxWidth: 520,
            child: Form(
              key: _form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              ProfileAvatar(initials: user?.initials ?? '?', radius: 26),
                              const SizedBox(width: 14),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      'Your details',
                                      style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                                    ),
                                    Text(
                                      'Your role and projects are managed by an administrator.',
                                      style: theme.textTheme.bodySmall?.copyWith(
                                        color: theme.colorScheme.onSurfaceVariant,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 20),
                          if (generalError != null) ...[
                            Text(generalError, style: TextStyle(color: theme.colorScheme.error)),
                            const SizedBox(height: 12),
                          ],
                          TextFormField(
                            controller: _name,
                            textCapitalization: TextCapitalization.words,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.name],
                            maxLength: 255,
                            decoration: InputDecoration(
                              labelText: 'Full name',
                              prefixIcon: const Icon(Icons.person_outline),
                              counterText: '',
                              errorText: _error?.fieldError('name'),
                            ),
                            validator: (v) => (v ?? '').trim().isEmpty ? 'Enter your name' : null,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _email,
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.email],
                            decoration: InputDecoration(
                              labelText: 'E-mail',
                              helperText: 'Used to sign in',
                              prefixIcon: const Icon(Icons.alternate_email),
                              errorText: _error?.fieldError('email'),
                            ),
                            validator: (v) =>
                                _emailPattern.hasMatch((v ?? '').trim()) ? null : 'Enter a valid e-mail address',
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _phone,
                            keyboardType: TextInputType.phone,
                            textInputAction: _emailChanged ? TextInputAction.next : TextInputAction.done,
                            autofillHints: const [AutofillHints.telephoneNumber],
                            maxLength: 30,
                            decoration: InputDecoration(
                              labelText: 'Phone (optional)',
                              prefixIcon: const Icon(Icons.phone_outlined),
                              counterText: '',
                              errorText: _error?.fieldError('phone'),
                            ),
                            onFieldSubmitted: _emailChanged ? null : (_) => _save(),
                          ),
                          if (_emailChanged) ...[
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _password,
                              obscureText: _obscure,
                              autofillHints: const [AutofillHints.password],
                              decoration: InputDecoration(
                                labelText: 'Current password',
                                helperText: 'Needed to change the e-mail you sign in with',
                                prefixIcon: const Icon(Icons.lock_outline),
                                errorText: _error?.fieldError('current_password'),
                                suffixIcon: IconButton(
                                  tooltip: _obscure ? 'Show password' : 'Hide password',
                                  icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                                  onPressed: () => setState(() => _obscure = !_obscure),
                                ),
                              ),
                              validator: (v) => (v ?? '').isEmpty ? 'Enter your current password' : null,
                              onFieldSubmitted: (_) => _save(),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _busy ? null : _save,
                    icon: _busy
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : const Icon(Icons.check),
                    label: const Text('Save changes'),
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

/// Round avatar with the user's initials in the brand colour.
class ProfileAvatar extends StatelessWidget {
  const ProfileAvatar({super.key, required this.initials, this.radius = 20});
  final String initials;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primary,
      foregroundColor: scheme.onPrimary,
      child: Text(
        initials,
        style: TextStyle(fontSize: radius * 0.7, fontWeight: FontWeight.w700),
      ),
    );
  }
}
