import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import 'more_screen.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final theme = Theme.of(context);
    if (user == null) return const Scaffold();
    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ResponsiveCenter(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  children: [
                    CircleAvatar(radius: 36, child: Text(user.initials, style: theme.textTheme.headlineSmall)),
                    const SizedBox(height: 12),
                    Text(user.name, style: theme.textTheme.titleLarge, textAlign: TextAlign.center),
                    const SizedBox(height: 6),
                    StatusBadge.status(label: user.roleLabel, statusTone: 'partial'),
                    const Divider(height: 32),
                    InfoField(label: 'E-mail', value: user.email, icon: Icons.alternate_email),
                    if ((user.phone ?? '').isNotEmpty) ...[
                      const SizedBox(height: 12),
                      InfoField(label: 'Phone', value: user.phone!, icon: Icons.phone_outlined),
                    ],
                    if (user.companyName.isNotEmpty) ...[
                      const SizedBox(height: 12),
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
    );
  }
}
