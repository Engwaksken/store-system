import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/requests_repository.dart';
import '../domain/request_models.dart';
import 'request_card.dart';

class RequestsListScreen extends ConsumerStatefulWidget {
  const RequestsListScreen({super.key});

  @override
  ConsumerState<RequestsListScreen> createState() => _RequestsListScreenState();
}

class _RequestsListScreenState extends ConsumerState<RequestsListScreen> {
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
    final repo = ref.watch(requestsRepositoryProvider);
    final canCreate = ref.watch(currentUserProvider)?.can.createRequest ?? false;
    return Scaffold(
      appBar: AppBar(title: const Text('Material requests')),
      floatingActionButton: canCreate
          ? FloatingActionButton.extended(
              onPressed: () => context.push('/request/new'),
              icon: const Icon(Icons.add),
              label: const Text('New request'),
            )
          : null,
      body: Column(
        children: [
          SearchField(hint: 'Search number or purpose', onChanged: (v) => setState(() => _q = v)),
          FilterChipsBar<String?>(
            options: RequestStatuses.filters,
            selected: _status,
            onSelected: (s) => setState(() => _status = s),
          ),
          Expanded(
            child: PagedListView<RequestSummary>(
              controller: _controller,
              filterKey: (_status, _q),
              fetch: (page) => repo.list(page: page, status: _status, q: _q),
              emptyIcon: Icons.assignment_outlined,
              emptyTitle: 'No requests',
              emptyMessage: (_status != null || _q.isNotEmpty)
                  ? 'Nothing matches these filters.'
                  : canCreate
                      ? 'Tap "New request" to ask the store for materials.'
                      : null,
              itemBuilder: (context, r) => RequestCard(request: r),
            ),
          ),
        ],
      ),
    );
  }
}
