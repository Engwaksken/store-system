import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/claims_repository.dart';
import '../domain/claim_models.dart';

class ClaimsListScreen extends ConsumerStatefulWidget {
  const ClaimsListScreen({super.key});

  @override
  ConsumerState<ClaimsListScreen> createState() => _ClaimsListScreenState();
}

class _ClaimsListScreenState extends ConsumerState<ClaimsListScreen> {
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
    final repo = ref.watch(claimsRepositoryProvider);
    final user = ref.watch(currentUserProvider);
    final f = ref.watch(formattersProvider);
    final canSubmit = user?.can.submitClaim ?? false;

    return Scaffold(
      appBar: AppBar(title: const Text('Expense claims')),
      floatingActionButton: canSubmit
          ? FloatingActionButton.extended(
              onPressed: () => context.push('/claims/new'),
              icon: const Icon(Icons.add),
              label: const Text('New claim'),
            )
          : null,
      body: Column(
        children: [
          SearchField(hint: 'Search number or description', onChanged: (v) => setState(() => _q = v)),
          FilterChipsBar<String?>(
            options: ClaimStatuses.filters,
            selected: _status,
            onSelected: (s) => setState(() => _status = s),
          ),
          Expanded(
            child: PagedListView<Claim>(
              controller: _controller,
              filterKey: (_status, _q),
              fetch: (page) => repo.list(page: page, status: _status, q: _q),
              emptyIcon: Icons.receipt_long_outlined,
              emptyTitle: 'No claims',
              emptyMessage: (_status != null || _q.isNotEmpty) ? 'Nothing matches these filters.' : null,
              itemBuilder: (context, c) {
                final theme = Theme.of(context);
                return Card(
                  child: InkWell(
                    onTap: () => context.push('/claims/${c.id}'),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(c.claimNo,
                                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                              ),
                              Flexible(child: StatusBadge.status(label: c.statusLabel, statusTone: c.statusTone, dense: true)),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(c.description, maxLines: 2, overflow: TextOverflow.ellipsis),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  [
                                    c.claimant?.name,
                                    c.category?.name,
                                    f.date(c.incurredOn),
                                  ].whereType<String>().join(' · '),
                                  style: theme.textTheme.bodySmall
                                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if (c.hasReceipt)
                                Padding(
                                  padding: const EdgeInsets.only(right: 6),
                                  child: Icon(Icons.attach_file,
                                      size: 16,
                                      color: theme.colorScheme.onSurfaceVariant,
                                      semanticLabel: 'Has receipt'),
                                ),
                              Text(f.money(c.amount),
                                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
