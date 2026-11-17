import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/users_repository.dart';
import '../domain/account_models.dart';

/// Add a user ([id] null) or edit one. Same rules as the web form: the person is
/// e-mailed a temporary password and must change it at first sign-in.
class UserFormScreen extends ConsumerWidget {
  const UserFormScreen({super.key, this.id});
  final int? id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final options = ref.watch(userFormOptionsProvider(id));
    final account = id == null ? null : ref.watch(accountDetailProvider(id!));
    void retry() {
      ref.invalidate(userFormOptionsProvider(id));
      if (id != null) ref.invalidate(accountDetailProvider(id!));
    }

    final Widget body;
    if (options.hasError || (account?.hasError ?? false)) {
      body = ErrorView(error: options.error ?? account!.error!, onRetry: retry);
    } else if (!options.hasValue || (account != null && !account.hasValue)) {
      body = const Center(child: CircularProgressIndicator());
    } else {
      body = _UserForm(options: options.requireValue, account: account?.requireValue);
    }
    return Scaffold(
      appBar: AppBar(title: Text(id == null ? 'Add user' : (account?.value?.name ?? 'Edit user'))),
      body: body,
    );
  }
}

class _UserForm extends ConsumerStatefulWidget {
  const _UserForm({required this.options, this.account});
  final UserFormOptions options;
  final Account? account;

  @override
  ConsumerState<_UserForm> createState() => _UserFormState();
}

class _UserFormState extends ConsumerState<_UserForm> {
  final _form = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.account?.name);
  late final _email = TextEditingController(text: widget.account?.email);
  late final _phone = TextEditingController(text: widget.account?.phone);
  final _password = TextEditingController();
  late String _role = widget.account?.role ?? 'staff';
  late int? _customRoleId = widget.account?.customRoleId;
  late bool _active = widget.account?.isActive ?? true;
  bool _resetPassword = false;
  bool _obscure = true;
  late final Set<int> _projects = {...?widget.account?.projects.map((p) => p.id)};
  bool _busy = false;
  ApiException? _error;

  bool get _editing => widget.account != null;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() => _error = null);
    if (!_form.currentState!.validate()) {
      showSnack(context, 'Please fix the highlighted fields.', error: true);
      return;
    }
    final me = ref.read(currentUserProvider);
    if (_editing && me?.id == widget.account!.id && (!_active || _role != 'admin')) {
      showSnack(context, 'You cannot deactivate or demote your own admin account.', error: true);
      return;
    }
    final input = AccountInput(
      name: _name.text,
      email: _email.text,
      phone: _phone.text,
      role: _role,
      customRoleId: _customRoleId,
      isActive: _active,
      password: _password.text,
      resetPassword: _resetPassword,
      projectIds: _projects.toList()..sort(),
    );
    setState(() => _busy = true);
    try {
      final repo = ref.read(usersRepositoryProvider);
      final result = _editing ? await repo.update(widget.account!.id, input) : await repo.create(input);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      if (_editing && widget.account!.id == me?.id) ref.read(authControllerProvider.notifier).refreshUser();
      final a = result.account;
      if (result.temporaryPassword != null) {
        await _showTemporaryPassword(a.email, result.temporaryPassword!);
      } else if (result.credentialsEmailed == true) {
        showSnack(context, 'Sign-in details e-mailed to ${a.email}.');
      } else {
        showSnack(context, _editing ? '${a.name} updated' : '${a.name} added');
      }
      if (mounted) context.pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e);
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showTemporaryPassword(String email, String password) => showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.key_outlined),
          title: const Text('E-mail could not be sent'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Give these sign-in details to the person. They must change the password at first sign-in. '
                  'This password is shown only once.'),
              const SizedBox(height: 12),
              InfoField(label: 'E-mail', value: email, icon: Icons.alternate_email),
              const SizedBox(height: 8),
              SelectableText(password,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(fontFamily: 'monospace', letterSpacing: 1.5)),
            ],
          ),
          actions: [
            TextButton.icon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: password));
                showSnack(context, 'Password copied');
              },
              icon: const Icon(Icons.copy),
              label: const Text('Copy'),
            ),
            FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Done')),
          ],
        ),
      );

  String? _required(String? v, String what) => (v ?? '').trim().isEmpty ? 'Enter the $what' : null;

  @override
  Widget build(BuildContext context) {
    final o = widget.options;
    final theme = Theme.of(context);
    final customRoles = o.customRoles.where((c) => c.profile == _role).toList();
    if (_customRoleId != null && !customRoles.any((c) => c.id == _customRoleId)) _customRoleId = null;
    final scopedRole = _role == 'staff' || _role == 'project_manager';

    return Form(
      key: _form,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          ResponsiveCenter(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (!_editing)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Text(
                      'The person receives an e-mail with their sign-in details and a temporary password, '
                      'and must change it at first sign-in.',
                      style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    ),
                  ),
                TextFormField(
                  controller: _name,
                  enabled: !_busy,
                  textCapitalization: TextCapitalization.words,
                  decoration: InputDecoration(
                    labelText: 'Full name',
                    prefixIcon: const Icon(Icons.person_outline),
                    errorText: _error?.fieldError('name'),
                  ),
                  validator: (v) => _required(v, 'full name'),
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _email,
                  enabled: !_busy,
                  keyboardType: TextInputType.emailAddress,
                  autocorrect: false,
                  decoration: InputDecoration(
                    labelText: 'E-mail',
                    prefixIcon: const Icon(Icons.alternate_email),
                    errorText: _error?.fieldError('email'),
                  ),
                  validator: (v) {
                    final t = (v ?? '').trim();
                    if (t.isEmpty) return 'Enter the e-mail';
                    return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(t) ? null : 'Enter a valid e-mail';
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _phone,
                  enabled: !_busy,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: 'Phone (optional)',
                    prefixIcon: const Icon(Icons.phone_outlined),
                    errorText: _error?.fieldError('phone'),
                  ),
                ),
                const SizedBox(height: 14),
                DropdownButtonFormField<String>(
                  initialValue: o.roles.any((r) => r.value == _role) ? _role : null,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: 'Role',
                    prefixIcon: const Icon(Icons.badge_outlined),
                    errorText: _error?.fieldError('role'),
                  ),
                  items: [for (final r in o.roles) DropdownMenuItem(value: r.value, child: Text(r.label))],
                  onChanged: _busy ? null : (v) => setState(() => _role = v ?? _role),
                  validator: (v) => v == null ? 'Choose a role' : null,
                ),
                if (customRoles.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  DropdownButtonFormField<int?>(
                    key: ValueKey('custom-$_role'),
                    initialValue: _customRoleId,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: 'Custom role name (optional)',
                      prefixIcon: const Icon(Icons.label_outline),
                      helperText: 'Uses the ${o.roleLabel(_role)} permissions.',
                      errorText: _error?.fieldError('custom_role_id'),
                    ),
                    items: [
                      const DropdownMenuItem<int?>(value: null, child: Text('Use standard role name')),
                      for (final c in customRoles) DropdownMenuItem<int?>(value: c.id, child: Text(c.name)),
                    ],
                    onChanged: _busy ? null : (v) => setState(() => _customRoleId = v),
                  ),
                ],
                const SizedBox(height: 8),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Account active'),
                  subtitle: const Text('Inactive users cannot sign in. Users are never deleted.'),
                  value: _active,
                  onChanged: _busy ? null : (v) => setState(() => _active = v),
                ),
                const SectionTitle('Password'),
                TextFormField(
                  controller: _password,
                  enabled: !_busy,
                  obscureText: _obscure,
                  autocorrect: false,
                  enableSuggestions: false,
                  decoration: InputDecoration(
                    labelText: _editing ? 'New temporary password (optional)' : 'Temporary password (optional)',
                    helperText: 'Leave blank to generate a strong one.',
                    helperMaxLines: 2,
                    prefixIcon: const Icon(Icons.key_outlined),
                    errorText: _error?.fieldError('password'),
                    suffixIcon: IconButton(
                      tooltip: _obscure ? 'Show password' : 'Hide password',
                      onPressed: () => setState(() => _obscure = !_obscure),
                      icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                    ),
                  ),
                  validator: (v) {
                    final t = v ?? '';
                    if (t.isEmpty) return null;
                    if (t.length < 8) return 'At least 8 characters';
                    if (!RegExp(r'[A-Za-z]').hasMatch(t) || !RegExp(r'\d').hasMatch(t)) {
                      return 'Use letters and numbers';
                    }
                    return null;
                  },
                ),
                if (_editing)
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    title: const Text('Reset password'),
                    subtitle: const Text('Generate a new temporary password and e-mail it.'),
                    value: _resetPassword,
                    onChanged: _busy ? null : (v) => setState(() => _resetPassword = v ?? false),
                  ),
                SectionTitle('Projects (${_projects.length})'),
                Text(
                  scopedRole
                      ? 'Staff and Project Managers only see, and can only request for, the projects ticked here.'
                      : 'Optional for this role.',
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
                if (_error?.fieldError('project_ids') != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(_error!.fieldError('project_ids')!, style: TextStyle(color: theme.colorScheme.error)),
                  ),
                const SizedBox(height: 6),
                if (o.projects.isEmpty)
                  const Card(child: ListTile(title: Text('No open projects')))
                else
                  Card(
                    child: Column(
                      children: [
                        for (final p in o.projects)
                          CheckboxListTile(
                            value: _projects.contains(p.ref.id),
                            title: Text(p.ref.label, overflow: TextOverflow.ellipsis),
                            subtitle: p.isClosed ? const Text('Closed') : null,
                            onChanged: _busy
                                ? null
                                : (v) => setState(() => v == true ? _projects.add(p.ref.id) : _projects.remove(p.ref.id)),
                          ),
                      ],
                    ),
                  ),
                const SizedBox(height: 20),
                FilledButton.icon(
                  onPressed: _busy ? null : _submit,
                  icon: _busy
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : Icon(_editing ? Icons.save_outlined : Icons.person_add_alt_1),
                  label: Text(_editing ? 'Save changes' : 'Add user'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
