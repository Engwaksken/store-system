import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/widgets/common.dart';
import '../../../core/security/biometric_lock.dart';
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
    ref.watch(currentUserProvider);
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
                              _AvatarEditor(busy: _busy),
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
                  const SizedBox(height: 12),
                  const Card(child: BiometricToggle()),
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
/// Tap the photo to take a new one, pick one from the gallery, or remove it.
class _AvatarEditor extends ConsumerStatefulWidget {
  const _AvatarEditor({required this.busy});
  final bool busy;

  @override
  ConsumerState<_AvatarEditor> createState() => _AvatarEditorState();
}

class _AvatarEditorState extends ConsumerState<_AvatarEditor> {
  bool _uploading = false;

  Future<void> _change() async {
    final user = ref.read(currentUserProvider);
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera_outlined), title: const Text('Take a photo'), onTap: () => Navigator.pop(context, 'camera')),
          ListTile(leading: const Icon(Icons.photo_library_outlined), title: const Text('Choose from gallery'), onTap: () => Navigator.pop(context, 'gallery')),
          if (user?.avatarUrl != null)
            ListTile(
              leading: Icon(Icons.delete_outline, color: Theme.of(context).colorScheme.error),
              title: const Text('Remove photo'),
              onTap: () => Navigator.pop(context, 'remove'),
            ),
        ]),
      ),
    );
    if (choice == null || !mounted) return;
    final auth = ref.read(authControllerProvider.notifier);
    try {
      if (choice == 'remove') {
        setState(() => _uploading = true);
        await auth.deleteAvatar();
      } else {
        final picked = await ImagePicker().pickImage(
          source: choice == 'camera' ? ImageSource.camera : ImageSource.gallery,
          maxWidth: 800,
          maxHeight: 800,
          imageQuality: 85,
          preferredCameraDevice: CameraDevice.front,
        );
        if (picked == null || !mounted) return;
        setState(() => _uploading = true);
        await auth.uploadAvatar(picked.path, picked.name);
      }
      if (mounted) showSnack(context, choice == 'remove' ? 'Photo removed' : 'Photo updated');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      button: true,
      label: 'Change profile photo',
      child: InkWell(
        key: const Key('avatar-edit'),
        customBorder: const CircleBorder(),
        onTap: widget.busy || _uploading ? null : _change,
        child: Stack(clipBehavior: Clip.none, children: [
          ProfileAvatar(initials: user?.initials ?? '?', radius: 30, imageUrl: user?.avatarUrl),
          if (_uploading) const Positioned.fill(child: CircularProgressIndicator(strokeWidth: 2.5)),
          Positioned(
            right: -2,
            bottom: -2,
            child: CircleAvatar(
              radius: 11,
              backgroundColor: scheme.secondaryContainer,
              child: Icon(Icons.photo_camera, size: 13, color: scheme.onSecondaryContainer),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Turn fingerprint / face sign-in on or off for this phone.
class BiometricToggle extends ConsumerStatefulWidget {
  const BiometricToggle({super.key});

  @override
  ConsumerState<BiometricToggle> createState() => _BiometricToggleState();
}

class _BiometricToggleState extends ConsumerState<BiometricToggle> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final on = ref.watch(biometricProvider).enabled;
    return SwitchListTile(
      key: const Key('biometric-toggle'),
      secondary: const Icon(Icons.fingerprint),
      title: const Text('Sign in with fingerprint or face'),
      subtitle: const Text('No password needed on this phone. Also locks the app after 30 s in the background.'),
      value: on,
      onChanged: _busy
          ? null
          : (v) async {
              setState(() => _busy = true);
              final error = await ref.read(biometricProvider.notifier).setEnabled(v);
              if (!mounted) return;
              setState(() => _busy = false);
              showSnack(
                this.context,
                error ?? (v ? 'Fingerprint sign-in is on' : 'Fingerprint sign-in is off'),
                error: error != null,
              );
            },
    );
  }
}

class ProfileAvatar extends StatelessWidget {
  const ProfileAvatar({super.key, required this.initials, this.radius = 20, this.imageUrl});
  final String initials;
  final double radius;

  /// Profile photo; initials show while it loads or when it cannot be loaded.
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final fallback = CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primary,
      foregroundColor: scheme.onPrimary,
      child: Text(
        initials,
        style: TextStyle(fontSize: radius * 0.7, fontWeight: FontWeight.w700),
      ),
    );
    final url = imageUrl;
    if (url == null || url.isEmpty) return fallback;
    return ClipOval(
      child: Image.network(
        url,
        width: radius * 2,
        height: radius * 2,
        fit: BoxFit.cover,
        semanticLabel: 'Profile photo',
        loadingBuilder: (context, child, progress) => progress == null ? child : fallback,
        errorBuilder: (_, _, _) => fallback,
      ),
    );
  }
}
