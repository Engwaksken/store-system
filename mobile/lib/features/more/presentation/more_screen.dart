import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../../dashboard/data/dashboard_repository.dart';

class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final unread = ref.watch(unreadCountProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ResponsiveCenter(
            child: Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                leading: CircleAvatar(child: Text(user?.initials ?? '?')),
                title: Text(user?.name ?? ''),
                subtitle: Text(user?.roleLabel ?? ''),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => context.push('/profile'),
              ),
            ),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: Card(
              child: Column(
                children: [
                  if (user?.can.viewClaims ?? false) ...[
                    ListTile(
                      leading: const Icon(Icons.receipt_long_outlined),
                      title: const Text('Expense claims'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => context.push('/claims'),
                    ),
                    const Divider(height: 1),
                  ],
                  ListTile(
                    leading: Badge(
                      isLabelVisible: unread > 0,
                      label: Text('$unread'),
                      child: const Icon(Icons.notifications_outlined),
                    ),
                    title: const Text('Notifications'),
                    subtitle: unread > 0 ? Text('$unread unread') : null,
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push('/notifications'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.person_outline),
                    title: const Text('Profile'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push('/profile'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.info_outline),
                    title: const Text('About'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => context.push('/about'),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: Card(
              child: ListTile(
                leading: Icon(Icons.logout, color: Theme.of(context).colorScheme.error),
                title: Text('Sign out', style: TextStyle(color: Theme.of(context).colorScheme.error)),
                onTap: () => confirmSignOut(context, ref),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

Future<void> confirmSignOut(BuildContext context, WidgetRef ref) async {
  final ok = await confirmDialog(
    context,
    title: 'Sign out?',
    message: 'You will need your e-mail and password to sign in again.',
    confirmLabel: 'Sign out',
    destructive: true,
  );
  if (ok) await ref.read(authControllerProvider.notifier).logout();
}
