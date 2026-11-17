import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../data/users_repository.dart';
import '../domain/account_models.dart';

/// Admin: every account, searchable by name, e-mail or phone. Users are deactivated, never deleted.
class UsersListScreen extends ConsumerStatefulWidget {
  const UsersListScreen({super.key});

  @override
  ConsumerState<UsersListScreen> createState() => _UsersListScreenState();
}

class _UsersListScreenState extends ConsumerState<UsersListScreen> {
  final _controller = PagedListController();
  String? _status;
  String _q = '';

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(dataRevisionProvider, (_, _) => _controller.refresh());
    final repo = ref.watch(usersRepositoryProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Users')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/users/new'),
        icon: const Icon(Icons.person_add_alt_1),
        label: const Text('Add user'),
      ),
      body: Column(
        children: [
          SearchField(hint: 'Name, e-mail or phone', onChanged: (v) => setState(() => _q = v)),
          FilterChipsBar<String?>(
            options: AccountStatuses.filters,
            selected: _status,
            onSelected: (s) => setState(() => _status = s),
          ),
          Expanded(
            child: PagedListView<Account>(
              controller: _controller,
              filterKey: (_status, _q),
              fetch: (page) => repo.list(page: page, status: _status, q: _q),
              emptyIcon: Icons.group_outlined,
              emptyTitle: 'No users',
              emptyMessage: (_status != null || _q.isNotEmpty) ? 'Nothing matches these filters.' : null,
              itemBuilder: (context, u) => _AccountCard(account: u),
            ),
          ),
        ],
      ),
    );
  }
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.account});
  final Account account;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final u = account;
    return Card(
      child: InkWell(
        onTap: () => context.push('/users/${u.id}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              CircleAvatar(
                backgroundColor: u.isActive ? null : theme.disabledColor.withValues(alpha: 0.15),
                child: Text(u.initials),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(u.name,
                        style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                        overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    Text(u.email, style: theme.textTheme.bodySmall?.copyWith(color: muted), overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: [
                        StatusBadge(label: u.roleLabel, tone: Tone.navy, dense: true),
                        if (u.needsProject)
                          const StatusBadge(label: 'No project', tone: Tone.pending, dense: true)
                        else if (u.projectsCount > 0)
                          StatusBadge(
                            label: '${u.projectsCount} project${u.projectsCount == 1 ? '' : 's'}',
                            tone: Tone.muted,
                            dense: true,
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              StatusBadge(
                label: u.isActive ? 'Active' : 'Inactive',
                tone: u.isActive ? Tone.approved : Tone.muted,
                dense: true,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
