import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/config/branding.dart';
import '../../../core/format/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/charts.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../../reports/data/reports_repository.dart';
import '../../requests/presentation/request_card.dart';
import '../../stock/data/stock_repository.dart';
import '../../stock/presentation/item_widgets.dart';
import '../data/dashboard_repository.dart';
import '../domain/dashboard_models.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    final dashboard = ref.watch(dashboardProvider);
    final unread = ref.watch(unreadCountProvider);

    Future<void> refresh() async {
      ref.read(dataRevisionProvider.notifier).bump();
      try {
        await ref.read(dashboardProvider.future);
      } catch (_) {}
    }

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Hello, ${user?.name.split(' ').first ?? ''}'),
            Text(
              ref.watch(appNameProvider),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: Colors.white70),
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: unread > 0 ? 'Notifications, $unread unread' : 'Notifications',
            onPressed: () => context.push('/notifications'),
            icon: Badge(
              isLabelVisible: unread > 0,
              label: Text(unread > 99 ? '99+' : '$unread'),
              child: const Icon(Icons.notifications_outlined),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: dashboard.when(
          skipLoadingOnRefresh: true,
          skipLoadingOnReload: true,
          loading: () => const _DashboardSkeleton(),
          error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(dashboardProvider)),
          data: (d) => _DashboardBody(dashboard: d),
        ),
      ),
    );
  }
}

class _DashboardBody extends ConsumerWidget {
  const _DashboardBody({required this.dashboard});
  final Dashboard dashboard;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(bottom: 32),
      children: [
        BrandHeader(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                DateFormat('EEEE, d MMMM').format(DateTime.now()),
                style: theme.textTheme.labelLarge?.copyWith(
                  color: BrandHeader.foreground(context).withValues(alpha: 0.85),
                ),
              ),
              const SizedBox(height: 12),
              LayoutBuilder(
                builder: (context, c) {
                  // Compact tiles: 3 across on phones, 4 on tablets, 2 only on very narrow screens.
                  final columns = c.maxWidth >= 600 ? 4 : (c.maxWidth >= 330 ? 3 : 2);
                  const gap = 8.0;
                  final w = (c.maxWidth - gap * (columns - 1)) / columns;
                  return Wrap(
                    spacing: gap,
                    runSpacing: gap,
                    children: [
                      for (final card in dashboard.cards)
                        SizedBox(
                          width: w,
                          child: _StatCard(card: card, formatters: f),
                        ),
                    ],
                  );
                },
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [const _OutOfStockSection(), const _OverviewSection(), ..._approvalsAndActions(context, ref)],
          ),
        ),
      ],
    );
  }

  List<Widget> _approvalsAndActions(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentUserProvider);
    return [
      if (user?.can.approveRequests ?? false) ...[
        ResponsiveCenter(
          child: SectionTitle(
            'Waiting for me',
            trailing: dashboard.awaitingMe.isEmpty
                ? null
                : TextButton(onPressed: () => context.go('/approvals'), child: const Text('See all')),
          ),
        ),
        if (dashboard.awaitingMe.isEmpty)
          const ResponsiveCenter(
            child: Card(
              child: ListTile(
                leading: Icon(Icons.task_alt, color: Colors.green),
                title: Text('You are all caught up'),
                subtitle: Text('No requests are waiting for your decision.'),
              ),
            ),
          )
        else
          for (final r in dashboard.awaitingMe)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: ResponsiveCenter(child: RequestCard(request: r)),
            ),
      ],
      const ResponsiveCenter(child: SectionTitle('Quick actions')),
      ResponsiveCenter(
        child: Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            if (user?.can.createRequest ?? false)
              ActionChip(
                avatar: const Icon(Icons.add_box_outlined, size: 18),
                label: const Text('New request'),
                onPressed: () => context.push('/request/new'),
              ),
            if (user?.can.submitClaim ?? false)
              ActionChip(
                avatar: const Icon(Icons.receipt_long_outlined, size: 18),
                label: const Text('New claim'),
                onPressed: () => context.push('/claims/new'),
              ),
            ActionChip(
              avatar: const Icon(Icons.search, size: 18),
              label: const Text('Check stock'),
              onPressed: () => context.go('/stock'),
            ),
            ActionChip(
              avatar: const Icon(Icons.insights_outlined, size: 18),
              label: const Text('Reports'),
              onPressed: () => context.push('/reports'),
            ),
          ],
        ),
      ),
    ];
  }
}

/// Items with nothing on hand: the first five, with a link to the full list in Stock.
/// Uses `GET /items?out=1`, so it also works on servers without the dashboard card.
class _OutOfStockSection extends ConsumerWidget {
  const _OutOfStockSection();

  static const _preview = 5;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final async = ref.watch(outOfStockPreviewProvider);
    final page = async.value;
    // Quietly hidden while first loading or if it fails; the dashboard has its own error view.
    if (page == null) return const SizedBox.shrink();
    final items = page.data;
    final total = page.meta.total > items.length ? page.meta.total : items.length;

    void seeAll() {
      ref.read(stockFilterProvider.notifier).set(StockFilter.out);
      context.go('/stock');
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ResponsiveCenter(
          child: SectionTitle(
            'Out of stock',
            trailing: items.isEmpty
                ? null
                : Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      StatusBadge(label: '$total', tone: Tone.rejected, dense: true),
                      TextButton(onPressed: seeAll, child: const Text('See all')),
                    ],
                  ),
          ),
        ),
        ResponsiveCenter(
          child: Card(
            child: items.isEmpty
                ? const ListTile(
                    leading: Icon(Icons.check_circle_outline, color: ChartColors.good),
                    title: Text('Nothing is out of stock'),
                    subtitle: Text('Every active item has stock in at least one store.'),
                  )
                : Column(
                    children: [
                      for (var i = 0; i < items.length && i < _preview; i++) ...[
                        if (i > 0) const Divider(height: 1, indent: 64),
                        ItemTile(item: items[i], formatters: f, dense: true),
                      ],
                      if (total > _preview) ...[
                        const Divider(height: 1),
                        ListTile(
                          dense: true,
                          title: Text(
                            'View all $total out-of-stock items',
                            style: TextStyle(color: Theme.of(context).colorScheme.primary, fontWeight: FontWeight.w600),
                          ),
                          trailing: Icon(Icons.arrow_forward, color: Theme.of(context).colorScheme.primary, size: 18),
                          onTap: seeAll,
                        ),
                      ],
                    ],
                  ),
          ),
        ),
      ],
    );
  }
}

/// Two headline charts from `GET /reports` (last 30 days), linking to the Reports screen.
/// Hidden on servers without the reports endpoint.
class _OverviewSection extends ConsumerWidget {
  const _OverviewSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final summary = ref.watch(reportSummaryProvider(30)).value;
    if (summary == null) return const SizedBox.shrink();
    final charts = [
      ?summary.chart('stock_status'),
      ?summary.chart('requests_by_status'),
    ].where((c) => !c.isEmpty).toList();
    if (charts.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ResponsiveCenter(
          child: SectionTitle(
            'Overview',
            trailing: TextButton(onPressed: () => context.push('/reports'), child: const Text('All reports')),
          ),
        ),
        for (var i = 0; i < charts.length; i++) ...[
          if (i > 0) const SizedBox(height: 10),
          ResponsiveCenter(
            child: ChartCard(chart: charts[i], formatters: f, maxBars: 5),
          ),
        ],
      ],
    );
  }
}

class _StatCard extends ConsumerWidget {
  const _StatCard({required this.card, required this.formatters});
  final DashboardCard card;
  final Formatters formatters;

  String get _value {
    final v = card.value;
    if (v is num) return formatters.count(v);
    if (v is String) return formatters.decimal(v, maxDecimals: 2);
    return Formatters.dash;
  }

  String? get _route => switch (card.key) {
    'awaiting_me' => '/approvals',
    'my_open_requests' => '/requests',
    'low_stock' || 'out_of_stock' => '/stock',
    'claims_awaiting' || 'claims_unpaid' => '/claims',
    'issued_vouchers_month' || 'issued_items_month' || 'issues_unacknowledged' || 'ready_to_issue' => '/issues',
    _ => null,
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final c = ToneColors.of(toneFromCard(card.tone), theme.brightness);
    final route = _route;
    return Semantics(
      button: route != null,
      label: '${card.label}: $_value',
      excludeSemantics: true,
      child: Card(
        child: InkWell(
          onTap: route == null
              ? null
              : () {
                  if (route == '/stock') {
                    ref.read(stockFilterProvider.notifier).set(switch (card.key) {
                      'low_stock' => StockFilter.low,
                      'out_of_stock' => StockFilter.out,
                      _ => StockFilter.all,
                    });
                  }
                  route == '/claims' || route == '/issues' ? context.push(route) : context.go(route);
                },
          child: Container(
            height: 72,
            decoration: BoxDecoration(
              border: Border(left: BorderSide(color: c.foreground, width: 3)),
            ),
            padding: const EdgeInsets.fromLTRB(10, 8, 8, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    _value,
                    maxLines: 1,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: c.foreground,
                      height: 1.1,
                    ),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  card.label,
                  style: theme.textTheme.bodySmall?.copyWith(height: 1.2),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _DashboardSkeleton extends StatelessWidget {
  const _DashboardSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
    physics: const AlwaysScrollableScrollPhysics(),
    padding: const EdgeInsets.all(16),
    children: [
      for (var row = 0; row < 2; row++) ...[
        Row(
          children: [
            for (var i = 0; i < 3; i++) ...[
              if (i > 0) const SizedBox(width: 8),
              const Expanded(
                child: Card(
                  child: SizedBox(
                    height: 72,
                    child: Padding(padding: EdgeInsets.all(10), child: _CardSkel()),
                  ),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 8),
      ],
      const SizedBox(height: 24),
      const Skeleton(width: 140, height: 16),
      const SizedBox(height: 12),
      for (var i = 0; i < 3; i++) ...[
        const Card(
          child: Padding(padding: EdgeInsets.all(16), child: _CardSkel()),
        ),
        const SizedBox(height: 10),
      ],
    ],
  );
}

class _CardSkel extends StatelessWidget {
  const _CardSkel();

  @override
  Widget build(BuildContext context) => const Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [Skeleton(width: 36, height: 20), SizedBox(height: 8), Skeleton(width: 70, height: 10)],
  );
}
