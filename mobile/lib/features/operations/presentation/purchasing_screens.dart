import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/format/formatters.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/ops_repository.dart';
import '../domain/ops_models.dart';
import 'ops_widgets.dart';

// ====================================================================== store hub

/// `/store`: entry points to every store operation the user may use.
class StoreHubScreen extends ConsumerWidget {
  const StoreHubScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final can = ref.watch(currentUserProvider)?.can;
    final entries = <(IconData, String, String, String)>[
      if (can?.issueStock ?? false) (Icons.outbox_outlined, 'Issue materials', 'Approved requests waiting, and issue vouchers', '/issues'),
      if (!(can?.issueStock ?? false)) (Icons.receipt_outlined, 'Issue vouchers', 'Materials issued to you; acknowledge receipt', '/issues'),
      if (can?.viewReceipts ?? false)
        (
          Icons.move_to_inbox_outlined,
          'Stock receipts',
          'Goods received into stores${(can?.manageStock ?? false) ? '; receive stock and adjust' : ''}',
          '/receipts',
        ),
      if (can?.viewTransfers ?? false) (Icons.local_shipping_outlined, 'Transfers', 'Stock moving between stores', '/transfers'),
      if (can?.viewReturns ?? false) (Icons.assignment_return_outlined, 'Returns', 'Materials returned from site', '/returns'),
      if (can?.viewPurchasing ?? false)
        (
          Icons.shopping_cart_outlined,
          'Purchasing',
          (can?.decidePurchasing ?? false) ? 'Approve requisitions (incl. restocking) and orders' : 'Requisitions and purchase orders',
          '/purchasing',
        ),
    ];
    return Scaffold(
      appBar: AppBar(title: const Text('Store')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ResponsiveCenter(
            child: Card(
              child: Column(
                children: [
                  for (var i = 0; i < entries.length; i++) ...[
                    if (i > 0) const Divider(height: 1),
                    ListTile(
                      leading: Icon(entries[i].$1),
                      title: Text(entries[i].$2),
                      subtitle: Text(entries[i].$3),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => context.push(entries[i].$4),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ====================================================================== lists

/// `/purchasing`: requisitions and purchase orders in two tabs.
class PurchasingScreen extends ConsumerStatefulWidget {
  const PurchasingScreen({super.key, this.initialTab = 0});
  final int initialTab;

  @override
  ConsumerState<PurchasingScreen> createState() => _PurchasingScreenState();
}

class _PurchasingScreenState extends ConsumerState<PurchasingScreen> {
  final _prs = PagedListController();
  final _pos = PagedListController();
  String? _prStatus;
  String? _poStatus;
  bool _restockOnly = false;

  @override
  void dispose() {
    _prs.dispose();
    _pos.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(dataRevisionProvider, (_, _) {
      _prs.refresh();
      _pos.refresh();
    });
    final repo = ref.watch(opsRepositoryProvider);
    final f = ref.watch(formattersProvider);
    return DefaultTabController(
      length: 2,
      initialIndex: widget.initialTab,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Purchasing'),
          bottom: const TabBar(
            tabs: [
              Tab(text: 'Requisitions'),
              Tab(text: 'Orders'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            Column(
              children: [
                FilterChipsBar<String?>(options: PurchaseStatuses.prFilters, selected: _prStatus, onSelected: (s) => setState(() => _prStatus = s)),
                FilterChipsBar<bool>(
                  options: const [(false, 'All requisitions'), (true, 'Restock only')],
                  selected: _restockOnly,
                  onSelected: (v) => setState(() => _restockOnly = v),
                ),
                Expanded(
                  child: PagedListView<PrSummary>(
                    expandAllToggle: true,
                    controller: _prs,
                    filterKey: (_prStatus, _restockOnly),
                    fetch: (page) => repo.requisitions(page: page, status: _prStatus, restockOnly: _restockOnly),
                    emptyIcon: Icons.request_quote_outlined,
                    emptyTitle: 'No requisitions',
                    itemBuilder: (context, pr) => DocCard(
                      title: pr.prNo,
                      leadingIcon: pr.isRestock ? Icons.local_shipping_outlined : null,
                      badge: pr.statusLabel,
                      badgeTone: pr.statusTone,
                      subtitle: pr.purpose,
                      meta: [
                        pr.isRestock ? 'Restock${pr.materialRequest != null ? ' for ${pr.materialRequest!.no}' : ''}' : pr.project?.code ?? 'Stock',
                        pr.store?.code,
                        pr.requester?.name,
                      ].whereType<String>().join(' · '),
                      trailing: pr.estimatedTotal == null ? null : f.money(pr.estimatedTotal),
                      onTap: () => context.push('/purchasing/pr/${pr.id}'),
                    ),
                  ),
                ),
              ],
            ),
            Column(
              children: [
                FilterChipsBar<String?>(options: PurchaseStatuses.poFilters, selected: _poStatus, onSelected: (s) => setState(() => _poStatus = s)),
                Expanded(
                  child: PagedListView<PoSummary>(
                    expandAllToggle: true,
                    controller: _pos,
                    filterKey: _poStatus,
                    fetch: (page) => repo.orders(page: page, status: _poStatus),
                    emptyIcon: Icons.shopping_cart_outlined,
                    emptyTitle: 'No purchase orders',
                    itemBuilder: (context, po) => DocCard(
                      title: po.poNo,
                      badge: po.statusLabel,
                      badgeTone: po.statusTone,
                      subtitle: po.supplier?.name,
                      meta: [po.project?.code, po.store?.code, f.date(po.orderDate)].whereType<String>().join(' · '),
                      trailing: f.money(po.total),
                      onTap: () => context.push('/purchasing/po/${po.id}'),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ====================================================================== decisions

Future<void> _decide(
  BuildContext context,
  WidgetRef ref, {
  required String docNo,
  required bool approve,
  required String summary,
  required Future<void> Function(String? reason) send,
  required void Function(bool) setBusy,
}) async {
  String? reason;
  if (approve) {
    final ok = await confirmDialog(context, title: 'Approve $docNo?', message: summary, confirmLabel: 'Approve');
    if (!ok) return;
  } else {
    reason = await askReason(context, title: 'Reject $docNo');
    if (reason == null) return;
  }
  setBusy(true);
  try {
    await send(reason);
    ref.read(dataRevisionProvider.notifier).bump();
    if (context.mounted) showSnack(context, '$docNo ${approve ? 'approved' : 'rejected'}');
  } catch (e) {
    if (!context.mounted) return;
    if (e is ApiException && e.isConflict) {
      await showConflictDialog(context, e.message);
      ref.read(dataRevisionProvider.notifier).bump();
    } else {
      showSnack(context, ApiException.messageOf(e), error: true);
    }
  } finally {
    setBusy(false);
  }
}

class PrDetailScreen extends ConsumerStatefulWidget {
  const PrDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<PrDetailScreen> createState() => _PrDetailScreenState();
}

class _PrDetailScreenState extends ConsumerState<PrDetailScreen> {
  bool _busy = false;

  void _setBusy(bool v) {
    if (mounted) setState(() => _busy = v);
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(prDetailProvider(widget.id));
    final d = async.value;
    final f = ref.watch(formattersProvider);
    final repo = ref.read(opsRepositoryProvider);
    return Scaffold(
      appBar: AppBar(title: Text(d?.summary.prNo ?? 'Requisition'), bottom: busyBar(_busy)),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(prDetailProvider(widget.id))),
        data: (d) {
          final s = d.summary;
          return RefreshIndicator(
            onRefresh: () => ref.refresh(prDetailProvider(widget.id).future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                if (s.isRestock)
                  ResponsiveCenter(
                    child: NoticeCard(
                      icon: Icons.local_shipping_outlined,
                      title: 'Restock request',
                      lines: [
                        'Raised automatically: the store could not issue${s.materialRequest != null ? ' ${s.materialRequest!.no}' : ' a request'} in full. '
                            'Quantities are the larger of the balance not in stock and the minimum stock level.',
                      ],
                    ),
                  ),
                ResponsiveCenter(
                  child: DetailHeader(
                    title: s.prNo,
                    badge: s.statusLabel,
                    badgeTone: s.statusTone,
                    headline: s.estimatedTotal == null ? null : 'Est. ${f.money(s.estimatedTotal)}',
                    fields: [
                      ('Purpose', s.purpose, Icons.notes),
                      ('Project', s.project?.label ?? 'Stock (no project)', Icons.apartment_outlined),
                      ('Deliver to', s.store?.label ?? Formatters.dash, Icons.warehouse_outlined),
                      ('Requested by', '${s.requester?.name ?? ''}${s.submittedAt != null ? ', ${f.dateTime(s.submittedAt)}' : ''}', Icons.person_outline),
                      if (s.neededOn != null) ('Needed by', f.date(s.neededOn), Icons.event_outlined),
                      if (d.decidedAt != null)
                        (
                          'Decision',
                          '${d.decider?.name ?? ''}, ${f.dateTime(d.decidedAt)}${(d.decisionReason ?? '').isNotEmpty ? ': ${d.decisionReason}' : ''}',
                          Icons.gavel_outlined,
                        ),
                    ],
                  ),
                ),
                if (s.materialRequest != null)
                  ResponsiveCenter(
                    child: TextButton.icon(
                      onPressed: () => context.push('/request/${s.materialRequest!.id}'),
                      icon: const Icon(Icons.open_in_new),
                      label: Text('Open request ${s.materialRequest!.no}'),
                    ),
                  ),
                const ResponsiveCenter(child: SectionTitle('Items')),
                ResponsiveCenter(
                  child: LinesCard(
                    children: [
                      for (final l in d.lines)
                        LineTile(
                          name: l.item.name,
                          subtitle: '${l.item.sku} · ${l.item.unit}',
                          note: l.notes,
                          figures: [('Qty', f.qty(l.qty)), if (l.estUnitCost != null) ('Est.', f.money(l.estUnitCost))],
                        ),
                    ],
                  ),
                ),
                if (d.orders.isNotEmpty) ...[
                  const ResponsiveCenter(child: SectionTitle('Purchase orders')),
                  ResponsiveCenter(
                    child: Card(
                      child: Column(
                        children: [
                          for (final o in d.orders)
                            ListTile(
                              leading: const Icon(Icons.shopping_cart_outlined),
                              title: Text(o.no),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: () => context.push('/purchasing/po/${o.id}'),
                            ),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
          );
        },
      ),
      bottomNavigationBar: d == null || (!d.canDecide && !d.canSubmit)
          ? null
          : BottomActions(
              children: [
                if (d.canSubmit)
                  FilledButton.icon(
                    onPressed: _busy
                        ? null
                        : () async {
                            _setBusy(true);
                            try {
                              await repo.submitRequisition(d.summary.id);
                              ref.read(dataRevisionProvider.notifier).bump();
                              if (context.mounted) showSnack(context, 'Requisition submitted for approval');
                            } catch (e) {
                              if (context.mounted) showSnack(context, ApiException.messageOf(e), error: true);
                            } finally {
                              _setBusy(false);
                            }
                          },
                    icon: const Icon(Icons.send),
                    label: const Text('Submit'),
                  ),
                if (d.canDecide) ...[
                  DangerOutlinedButton(
                    label: 'Reject',
                    icon: Icons.close,
                    onPressed: _busy
                        ? null
                        : () => _decide(
                            context,
                            ref,
                            docNo: d.summary.prNo,
                            approve: false,
                            summary: '',
                            setBusy: _setBusy,
                            send: (r) => repo.decideRequisition(d.summary.id, approve: false, reason: r),
                          ),
                  ),
                  FilledButton.icon(
                    onPressed: _busy
                        ? null
                        : () => _decide(
                            context,
                            ref,
                            docNo: d.summary.prNo,
                            approve: true,
                            summary:
                                '${d.lines.length} item(s) for ${d.summary.store?.label ?? 'the store'}. Procurement then gets quotes and raises the order.',
                            setBusy: _setBusy,
                            send: (r) => repo.decideRequisition(d.summary.id, approve: true),
                          ),
                    icon: const Icon(Icons.check),
                    label: const Text('Approve'),
                  ),
                ],
              ],
            ),
    );
  }
}

class PoDetailScreen extends ConsumerStatefulWidget {
  const PoDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<PoDetailScreen> createState() => _PoDetailScreenState();
}

class _PoDetailScreenState extends ConsumerState<PoDetailScreen> {
  bool _busy = false;

  void _setBusy(bool v) {
    if (mounted) setState(() => _busy = v);
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(poDetailProvider(widget.id));
    final d = async.value;
    final f = ref.watch(formattersProvider);
    final repo = ref.read(opsRepositoryProvider);
    return Scaffold(
      appBar: AppBar(title: Text(d?.summary.poNo ?? 'Purchase order'), bottom: busyBar(_busy)),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(poDetailProvider(widget.id))),
        data: (d) {
          final s = d.summary;
          return RefreshIndicator(
            onRefresh: () => ref.refresh(poDetailProvider(widget.id).future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                ResponsiveCenter(
                  child: DetailHeader(
                    title: s.poNo,
                    badge: s.statusLabel,
                    badgeTone: s.statusTone,
                    headline: f.money(s.total),
                    fields: [
                      ('Supplier', s.supplier?.name ?? Formatters.dash, Icons.storefront_outlined),
                      ('Project', s.project?.label ?? 'Stock (no project)', Icons.apartment_outlined),
                      ('Deliver to', s.store?.label ?? Formatters.dash, Icons.warehouse_outlined),
                      ('Order date', f.date(s.orderDate), Icons.event_outlined),
                      if (s.expectedOn != null) ('Expected', f.date(s.expectedOn), Icons.schedule),
                      if (d.requisition != null) ('Requisition', d.requisition!.no, Icons.request_quote_outlined),
                      ('Raised by', s.creator?.name ?? Formatters.dash, Icons.person_outline),
                      if ((d.terms ?? '').isNotEmpty) ('Terms', d.terms!, Icons.description_outlined),
                      if (d.decidedAt != null)
                        (
                          'Decision',
                          '${d.decider?.name ?? ''}, ${f.dateTime(d.decidedAt)}${(d.decisionReason ?? '').isNotEmpty ? ': ${d.decisionReason}' : ''}',
                          Icons.gavel_outlined,
                        ),
                    ],
                  ),
                ),
                const ResponsiveCenter(child: SectionTitle('Lines')),
                ResponsiveCenter(
                  child: LinesCard(
                    children: [
                      for (final l in d.lines)
                        LineTile(
                          name: l.item.name,
                          subtitle: '${l.item.sku} · ${f.money(l.unitPrice)} / ${l.item.unit}',
                          figures: [('Qty', f.qty(l.qty)), if (l.receivedQty != null) ('Received', f.qty(l.receivedQty)), ('Total', f.money(l.lineTotal))],
                        ),
                    ],
                  ),
                ),
              ],
            ),
          );
        },
      ),
      bottomNavigationBar: d == null || !d.canDecide
          ? null
          : BottomActions(
              children: [
                DangerOutlinedButton(
                  label: 'Reject',
                  icon: Icons.close,
                  onPressed: _busy
                      ? null
                      : () => _decide(
                          context,
                          ref,
                          docNo: d.summary.poNo,
                          approve: false,
                          summary: '',
                          setBusy: _setBusy,
                          send: (r) => repo.decideOrder(d.summary.id, approve: false, reason: r, lockVersion: d.lockVersion),
                        ),
                ),
                FilledButton.icon(
                  onPressed: _busy
                      ? null
                      : () => _decide(
                          context,
                          ref,
                          docNo: d.summary.poNo,
                          approve: true,
                          summary: '${f.money(d.summary.total)} to ${d.summary.supplier?.name ?? 'the supplier'}.',
                          setBusy: _setBusy,
                          send: (r) => repo.decideOrder(d.summary.id, approve: true, lockVersion: d.lockVersion),
                        ),
                  icon: const Icon(Icons.check),
                  label: const Text('Approve'),
                ),
              ],
            ),
    );
  }
}
