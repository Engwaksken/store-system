import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import 'edit_profile_screen.dart';
import 'more_screen.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final theme = Theme.of(context);
    if (user == null) return const Scaffold();
    return Scaffold(
      appBar: AppBar(
        title: const Text('Profile'),
        actions: [
          IconButton(
            tooltip: 'Edit profile',
            icon: const Icon(Icons.edit_outlined),
            onPressed: () => context.push('/profile/edit'),
          ),
        ],
      ),
      body: ListView(
        padding: EdgeInsets.zero,
        children: [
          BrandHeader(
            child: Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(3),
                  decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
                  child: ProfileAvatar(initials: user.initials, radius: 38),
                ),
                const SizedBox(height: 12),
                Text(
                  user.name,
                  textAlign: TextAlign.center,
                  style: theme.textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: BrandHeader.foreground(context),
                  ),
                ),
                const SizedBox(height: 6),
                StatusBadge.status(label: user.roleLabel, statusTone: 'partial'),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                ResponsiveCenter(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 16, 8, 16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  'Contact details',
                                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                                ),
                              ),
                              TextButton.icon(
                                onPressed: () => context.push('/profile/edit'),
                                icon: const Icon(Icons.edit_outlined, size: 18),
                                label: const Text('Edit'),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          InfoField(label: 'Full name', value: user.name, icon: Icons.person_outline),
                          const SizedBox(height: 14),
                          InfoField(label: 'E-mail', value: user.email, icon: Icons.alternate_email),
                          const SizedBox(height: 14),
                          InfoField(
                            label: 'Phone',
                            value: (user.phone ?? '').isEmpty ? 'Not set' : user.phone!,
                            icon: Icons.phone_outlined,
                          ),
                          if (user.companyName.isNotEmpty) ...[
                            const SizedBox(height: 14),
                            InfoField(label: 'Company', value: user.companyName, icon: Icons.business_outlined),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                ResponsiveCenter(
                  child: Card(
                    child: Column(
                      children: [
                        ListTile(
                          leading: const Icon(Icons.manage_accounts_outlined),
                          title: const Text('Edit profile'),
                          subtitle: const Text('Name, e-mail and phone'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => context.push('/profile/edit'),
                        ),
                        const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.lock_outline),
                          title: const Text('Change password'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => context.push('/change-password'),
                        ),
                        const Divider(height: 1),
                        ListTile(
                          leading: Icon(Icons.logout, color: theme.colorScheme.error),
                          title: Text('Sign out', style: TextStyle(color: theme.colorScheme.error)),
                          onTap: () => confirmSignOut(context, ref),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
