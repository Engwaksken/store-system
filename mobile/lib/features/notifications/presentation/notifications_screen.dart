import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../../dashboard/data/dashboard_repository.dart';
import '../data/notifications_repository.dart';
import '../domain/notification_models.dart';

class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  final _controller = PagedListController();
  bool _unreadOnly = false;

  /// Ids read in this session (the list item objects are immutable).
  final _readIds = <String>{};

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _open(AppNotification n) async {
    final repo = ref.read(notificationsRepositoryProvider);
    if (!n.read && !_readIds.contains(n.id)) {
      setState(() => _readIds.add(n.id));
      try {
        await repo.markRead(n.id);
        ref.invalidate(dashboardProvider); // badge
      } catch (_) {
        // Not critical; the next refresh shows the true state.
      }
    }
    final route = n.target?.route;
    if (route != null && mounted) context.push(route);
  }

  Future<void> _markAll() async {
    try {
      await ref.read(notificationsRepositoryProvider).markAllRead();
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      _controller.refresh();
      showSnack(context, 'All notifications marked as read');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(notificationsRepositoryProvider);
    final unread = ref.watch(unreadCountProvider);
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          TextButton(
            onPressed: unread > 0 ? _markAll : null,
            style: TextButton.styleFrom(foregroundColor: Colors.white, disabledForegroundColor: Colors.white54),
            child: const Text('Mark all read'),
          ),
        ],
      ),
      body: Column(
        children: [
          FilterChipsBar<bool>(
            options: const [(false, 'All'), (true, 'Unread')],
            selected: _unreadOnly,
            onSelected: (v) => setState(() => _unreadOnly = v),
          ),
          Expanded(
            child: PagedListView<AppNotification>(
              controller: _controller,
              filterKey: _unreadOnly,
              fetch: (page) {
                // Refresh the badge too (after this frame: providers must not change during build).
                if (page == 1) Future.microtask(() => ref.invalidate(dashboardProvider));
                return repo.list(page: page, unreadOnly: _unreadOnly);
              },
              separator: 8,
              emptyIcon: Icons.notifications_none,
              emptyTitle: _unreadOnly ? 'No unread notifications' : 'No notifications yet',
              itemBuilder: (context, n) {
                final read = n.read || _readIds.contains(n.id);
                final theme = Theme.of(context);
                final c = ToneColors.of(toneFromKind(n.kind), theme.brightness);
                return Card(
                  color: read ? null : theme.colorScheme.primaryContainer.withValues(alpha: 0.35),
                  child: InkWell(
                    onTap: () => _open(n),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          CircleAvatar(
                            radius: 18,
                            backgroundColor: c.background,
                            child: Icon(_icon(n.kind), color: c.foreground, size: 20),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  n.subject,
                                  style: theme.textTheme.titleSmall?.copyWith(
                                    fontWeight: read ? FontWeight.w500 : FontWeight.w800,
                                  ),
                                ),
                                if (n.body.isNotEmpty) ...[
                                  const SizedBox(height: 4),
                                  Text(n.body, maxLines: 3, overflow: TextOverflow.ellipsis),
                                ],
                                const SizedBox(height: 6),
                                Text(
                                  f.relative(n.createdAt),
                                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                                ),
                              ],
                            ),
                          ),
                          if (!read)
                            Semantics(
                              label: 'Unread',
                              child: Container(
                                width: 10,
                                height: 10,
                                margin: const EdgeInsets.only(left: 8, top: 4),
                                decoration: BoxDecoration(color: Brand.teal, borderRadius: BorderRadius.circular(5)),
                              ),
                            )
                          else if (n.target?.route != null)
                            const Icon(Icons.chevron_right),
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

  static IconData _icon(String kind) => switch (kind) {
        'warn' => Icons.pending_actions,
        'bad' => Icons.cancel_outlined,
        'ok' => Icons.check_circle_outline,
        _ => Icons.info_outline,
      };
}
