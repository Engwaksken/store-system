import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/feedback_repository.dart';
import '../domain/feedback_models.dart';

/// The user's own feedback; admins see everyone's and can switch to "Mine".
class FeedbackListScreen extends ConsumerStatefulWidget {
  const FeedbackListScreen({super.key});

  @override
  ConsumerState<FeedbackListScreen> createState() => _FeedbackListScreenState();
}

class _FeedbackListScreenState extends ConsumerState<FeedbackListScreen> {
  final _controller = PagedListController();
  String? _status;
  String _q = '';
  bool _mineOnly = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(dataRevisionProvider, (_, _) => _controller.refresh());
    final repo = ref.watch(feedbackRepositoryProvider);
    final manage = ref.watch(currentUserProvider)?.can.manageFeedback ?? false;
    final f = ref.watch(formattersProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(manage && !_mineOnly ? 'All feedback' : 'My feedback'),
        actions: [
          if (manage)
            IconButton(
              tooltip: _mineOnly ? 'Show everyone\'s feedback' : 'Show only mine',
              onPressed: () => setState(() => _mineOnly = !_mineOnly),
              icon: Icon(_mineOnly ? Icons.groups_outlined : Icons.person_outline),
            ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/feedback/new'),
        icon: const Icon(Icons.rate_review_outlined),
        label: const Text('Send feedback'),
      ),
      body: Column(
        children: [
          SearchField(hint: 'Subject or message', onChanged: (v) => setState(() => _q = v)),
          FilterChipsBar<String?>(
            options: FeedbackStatuses.filters,
            selected: _status,
            onSelected: (s) => setState(() => _status = s),
          ),
          Expanded(
            child: PagedListView<FeedbackItem>(
              controller: _controller,
              filterKey: (_status, _q, _mineOnly),
              fetch: (page) => repo.list(page: page, status: _status, q: _q, scope: _mineOnly ? 'mine' : null),
              emptyIcon: Icons.forum_outlined,
              emptyTitle: 'No feedback yet',
              emptyMessage: (_status != null || _q.isNotEmpty)
                  ? 'Nothing matches these filters.'
                  : 'Tell the administrators what works, what does not, and what you would like.',
              itemBuilder: (context, item) {
                final theme = Theme.of(context);
                final muted = theme.colorScheme.onSurfaceVariant;
                return Card(
                  child: InkWell(
                    onTap: () => context.push('/feedback/${item.id}'),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(item.subject,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                              ),
                              const SizedBox(width: 8),
                              StatusBadge.status(label: item.statusLabel, statusTone: item.statusTone, dense: true),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(item.message, maxLines: 2, overflow: TextOverflow.ellipsis),
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  [
                                    item.reference,
                                    item.categoryLabel,
                                    if (manage && !_mineOnly) item.user?.name,
                                    f.relative(item.createdAt),
                                  ].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                                  style: theme.textTheme.bodySmall?.copyWith(color: muted),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if (item.rating != null)
                                Text(ratingStars(item.rating!),
                                    semanticsLabel: '${item.rating} of 5 stars',
                                    style: theme.textTheme.bodySmall?.copyWith(color: Colors.amber.shade700)),
                              if (item.hasReply)
                                Padding(
                                  padding: const EdgeInsets.only(left: 6),
                                  child: Icon(Icons.reply, size: 16, color: muted, semanticLabel: 'Replied'),
                                ),
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
