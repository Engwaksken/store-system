import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/util/data_revision.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../data/requests_repository.dart';
import '../domain/request_models.dart';
import 'request_card.dart';

/// Requests waiting for my decision (oldest first).
class ApprovalsScreen extends ConsumerStatefulWidget {
  const ApprovalsScreen({super.key});

  @override
  ConsumerState<ApprovalsScreen> createState() => _ApprovalsScreenState();
}

class _ApprovalsScreenState extends ConsumerState<ApprovalsScreen> {
  final _controller = PagedListController();
  String _q = '';

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(dataRevisionProvider, (_, _) => _controller.refresh());
    final repo = ref.watch(requestsRepositoryProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Approvals')),
      body: Column(
        children: [
          SearchField(hint: 'Search number or purpose', onChanged: (v) => setState(() => _q = v)),
          Expanded(
            child: PagedListView<RequestSummary>(
              controller: _controller,
              filterKey: _q,
              fetch: (page) => repo.approvals(page: page, q: _q),
              emptyIcon: Icons.task_alt,
              emptyTitle: _q.isEmpty ? 'Nothing waiting for you' : 'No matches',
              emptyMessage: _q.isEmpty ? 'Requests that need your decision will appear here.' : 'Try another search.',
              itemBuilder: (context, r) => RequestCard(request: r),
            ),
          ),
        ],
      ),
    );
  }
}
