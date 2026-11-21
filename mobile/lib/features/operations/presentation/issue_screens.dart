import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/format/formatters.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../../requests/domain/request_models.dart';
import '../../requests/presentation/request_card.dart';
import '../data/ops_repository.dart';
import '../domain/ops_models.dart';
import 'ops_widgets.dart';

// ====================================================================== list: to issue + vouchers

/// `/issues`: storekeepers get "To issue" (requests with both approvals) and "Vouchers"; others see vouchers only.
class IssuesScreen extends ConsumerStatefulWidget {
  const IssuesScreen({super.key});

  @override
  ConsumerState<IssuesScreen> createState() => _IssuesScreenState();
}

class _IssuesScreenState extends ConsumerState<IssuesScreen> {
  final _queue = PagedListController();
  final _vouchers = PagedListController();
  String _q = '';
  bool _unacknowledged = false;

  @override
  void dispose() {
    _queue.dispose();
    _vouchers.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(dataRevisionProvider, (_, _) {
      _queue.refresh();
      _vouchers.refresh();
    });
    final canIssue = ref.watch(currentUserProvider)?.can.issueStock ?? false;
    final repo = ref.watch(opsRepositoryProvider);
    final f = ref.watch(formattersProvider);

    final vouchers = Column(
      children: [
        SearchField(hint: 'Voucher, request or recipient', onChanged: (v) => setState(() => _q = v)),
        FilterChipsBar<bool>(
          options: const [(false, 'All'), (true, 'Not acknowledged')],
          selected: _unacknowledged,
          onSelected: (v) => setState(() => _unacknowledged = v),
        ),
        Expanded(
          child: PagedListView<IssueSummary>(
            expandAllToggle: true,
            controller: _vouchers,
            filterKey: (_q, _unacknowledged),
            fetch: (page) => repo.issues(page: page, q: _q, unacknowledged: _unacknowledged),
            emptyIcon: Icons.receipt_outlined,
            emptyTitle: 'No issue vouchers',
            itemBuilder: (context, i) => DocCard(
              title: i.issueNo,
              badge: i.acknowledged ? 'Acknowledged' : 'Awaiting acknowledgment',
              badgeTone: i.acknowledged ? 'approved' : 'pending',
              subtitle: [i.request?.no, i.project?.code, 'to ${i.recipientName}'].whereType<String>().join(' · '),
              meta: [i.store?.code, f.dateTime(i.issuedAt), '${i.linesCount} item(s)'].whereType<String>().join(' · '),
              trailing: i.totalValue == null ? null : f.money(i.totalValue),
              onTap: () => context.push('/issues/${i.id}'),
            ),
          ),
        ),
      ],
    );

    if (!canIssue) {
      return Scaffold(
        appBar: AppBar(title: const Text('Issue vouchers')),
        body: vouchers,
      );
    }
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Issue materials'),
          bottom: const TabBar(
            tabs: [
              Tab(text: 'To issue'),
              Tab(text: 'Vouchers'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            PagedListView<RequestSummary>(
              expandAllToggle: true,
              controller: _queue,
              fetch: (page) => repo.issueQueue(page: page),
              emptyIcon: Icons.task_alt,
              emptyTitle: 'Nothing waiting to issue',
              emptyMessage: 'Requests appear here once the Project Manager and Overall Manager have approved them.',
              itemBuilder: (context, r) => RequestCard(
                request: r,
                actions: [
                  FilledButton.tonalIcon(
                    onPressed: () => context.push('/request/${r.id}/issue'),
                    icon: const Icon(Icons.outbox_outlined),
                    label: Text('Issue ${r.requestNo}'),
                  ),
                ],
              ),
            ),
            vouchers,
          ],
        ),
      ),
    );
  }
}

// ====================================================================== issue form

/// `/request/:id/issue`: issue what is in stock; the balance goes to the Overall Manager for restocking.
class IssueFormScreen extends ConsumerStatefulWidget {
  const IssueFormScreen({super.key, required this.requestId});
  final int requestId;

  @override
  ConsumerState<IssueFormScreen> createState() => _IssueFormScreenState();
}

class _IssueFormScreenState extends ConsumerState<IssueFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _qty = <int, TextEditingController>{};
  final _recipient = TextEditingController();
  final _notes = TextEditingController();
  final _key = newIdempotencyKey();
  IssueForm? _form;
  Object? _error;
  int? _storeId;
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _qty.values) {
      c.dispose();
    }
    _recipient.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final form = await ref.read(opsRepositoryProvider).issueForm(widget.requestId, storeId: _storeId);
      if (!mounted) return;
      for (final c in _qty.values) {
        c.dispose();
      }
      _qty
        ..clear()
        ..addAll({
          for (final l in form.lines)
            if (Decimal.parse(l.outstanding) > Decimal.zero) l.id: TextEditingController(text: _trim(l.maxIssue)),
        });
      if (_recipient.text.isEmpty) _recipient.text = form.recipientName;
      setState(() {
        _form = form;
        _storeId = form.storeId;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  static String _trim(String q) {
    final d = Decimal.tryParse(q);
    return d == null ? q : d.toString();
  }

  Future<void> _issue() async {
    final form = _form!;
    if (!_formKey.currentState!.validate()) return;
    final qty = <int, String>{
      for (final e in _qty.entries)
        if (e.value.text.trim().isNotEmpty && Decimal.parse(e.value.text.trim()) > Decimal.zero) e.key: e.value.text.trim(),
    };
    if (qty.isEmpty) {
      showSnack(
        context,
        form.shortfall.isNotEmpty ? 'Nothing to issue. Use "Request restock only" instead.' : 'Enter a quantity for at least one item.',
        error: true,
      );
      return;
    }
    final ok = await confirmDialog(
      context,
      title: 'Issue ${form.request.requestNo}?',
      message:
          '${qty.length} item(s) to ${_recipient.text.trim()}.'
          '${form.shortfall.isNotEmpty ? ' The balance not in stock is sent to the Overall Manager for restocking.' : ''}',
      confirmLabel: 'Issue',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final res = await ref
          .read(opsRepositoryProvider)
          .issueRequest(widget.requestId, storeId: _storeId!, qty: qty, recipientName: _recipient.text, notes: _notes.text, idempotencyKey: _key);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(
        context,
        res.replayed
            ? 'Voucher ${res.issue.summary.issueNo} was already created; nothing was issued twice.'
            : 'Voucher ${res.issue.summary.issueNo} created.'
                  '${res.issue.lines.any((l) => l.hasBalance) ? ' A balance remains outstanding on ${form.request.requestNo}.' : ''}'
                  '${res.restock != null ? ' Restock ${res.restock!.no} sent to the Overall Manager.' : ''}',
      );
      context.pushReplacement('/issues/${res.issue.summary.id}');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _restockOnly() async {
    final ok = await confirmDialog(
      context,
      title: 'Request restock?',
      message: 'Send the shortfall to the Overall Manager as a restock requisition, without issuing anything now.',
      confirmLabel: 'Send',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final (pr, message) = await ref.read(opsRepositoryProvider).requestRestock(widget.requestId, storeId: _storeId!);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, message, error: pr == null);
      if (pr != null) await _load();
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final form = _form;
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: Text(form == null ? 'Issue materials' : 'Issue ${form.request.requestNo}'), bottom: busyBar(_busy || (_loading && form != null))),
      body: form == null
          ? (_error != null ? ErrorView(error: _error!, onRetry: _load) : const SkeletonList(count: 3))
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  ResponsiveCenter(
                    child: Text(
                      '${form.request.project?.label ?? ''} · requested by ${form.request.requester?.name ?? ''}',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (form.stores.length > 1)
                    ResponsiveCenter(
                      child: DropdownButtonFormField<int>(
                        isExpanded: true,
                        initialValue: _storeId,
                        decoration: const InputDecoration(labelText: 'Issue from store'),
                        items: [
                          for (final s in form.stores)
                            DropdownMenuItem(
                              value: s.id,
                              child: Text(s.label, overflow: TextOverflow.ellipsis),
                            ),
                        ],
                        onChanged: _busy
                            ? null
                            : (v) {
                                setState(() => _storeId = v);
                                _load();
                              },
                      ),
                    ),
                  if (form.shortfall.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    ResponsiveCenter(
                      child: NoticeCard(
                        icon: Icons.local_shipping_outlined,
                        title: 'Not enough stock for ${form.shortfall.length} item(s)',
                        lines: [
                          'Issue what is in stock now. The balance goes to the Overall Manager as a restock requisition '
                              '(the larger of the balance and the minimum stock level):',
                          for (final s in form.shortfall)
                            '• ${s.itemName}: ${f.qty(s.onHand)} in store, ${f.qty(s.shortQty)} short → restock ${f.qty(s.restockQty)} ${s.unit}',
                          if (form.openRestock.isNotEmpty) 'Already requested: ${form.openRestock.map((d) => d.no).join(', ')}',
                        ],
                      ),
                    ),
                  ],
                  const ResponsiveCenter(child: SectionTitle('Items')),
                  ResponsiveCenter(
                    child: Card(
                      child: Column(
                        children: [
                          for (final l in form.lines) ...[
                            if (l != form.lines.first) const Divider(height: 1),
                            Padding(
                              padding: const EdgeInsets.all(14),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(l.item.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                                        Text(
                                          'Outstanding ${f.qty(l.outstanding)} · in store ${f.qty(l.onHand)} ${l.item.unit}',
                                          style: Theme.of(context).textTheme.bodySmall,
                                        ),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  SizedBox(
                                    width: 110,
                                    child: _qty[l.id] == null
                                        ? const Text('Done', textAlign: TextAlign.end)
                                        : TextFormField(
                                            controller: _qty[l.id],
                                            enabled: !_busy,
                                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                            textAlign: TextAlign.end,
                                            decoration: InputDecoration(labelText: 'Issue', suffixText: l.item.unit, isDense: true),
                                            validator: (v) => validateQty(v, max: l.maxIssue, allowEmpty: true, allowZero: true),
                                          ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  ResponsiveCenter(
                    child: TextFormField(
                      controller: _recipient,
                      enabled: !_busy,
                      decoration: const InputDecoration(labelText: 'Recipient (person collecting)'),
                      validator: (v) => (v ?? '').trim().isEmpty ? 'Required' : null,
                    ),
                  ),
                  const SizedBox(height: 12),
                  ResponsiveCenter(
                    child: TextFormField(
                      controller: _notes,
                      enabled: !_busy,
                      decoration: const InputDecoration(labelText: 'Notes (optional)'),
                    ),
                  ),
                  if (form.shortfall.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    ResponsiveCenter(
                      child: OutlinedButton.icon(
                        onPressed: _busy ? null : _restockOnly,
                        icon: const Icon(Icons.local_shipping_outlined),
                        label: const Text('Request restock only'),
                      ),
                    ),
                  ],
                  const SizedBox(height: 80),
                ],
              ),
            ),
      bottomNavigationBar: form == null
          ? null
          : BottomActions(
              children: [
                FilledButton.icon(
                  onPressed: _busy || _qty.isEmpty ? null : _issue,
                  icon: const Icon(Icons.outbox_outlined),
                  label: const Text('Issue & create voucher'),
                ),
              ],
            ),
    );
  }
}

// ====================================================================== voucher detail

class IssueDetailScreen extends ConsumerStatefulWidget {
  const IssueDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<IssueDetailScreen> createState() => _IssueDetailScreenState();
}

class _IssueDetailScreenState extends ConsumerState<IssueDetailScreen> {
  bool _busy = false;

  Future<void> _acknowledge(IssueDetail d) async {
    final remarks = await showDialog<String>(context: context, builder: (_) => const _AckDialog());
    if (remarks == null || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(opsRepositoryProvider).acknowledge(d.summary.id, remarks: remarks);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Receipt of ${d.summary.issueNo} acknowledged');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(issueDetailProvider(widget.id));
    final d = async.value;
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(d?.summary.issueNo ?? 'Issue voucher'),
        actions: [IconButton(tooltip: 'Reload', onPressed: () => ref.invalidate(issueDetailProvider(widget.id)), icon: const Icon(Icons.refresh))],
        bottom: busyBar(_busy),
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(issueDetailProvider(widget.id))),
        data: (d) {
          final s = d.summary;
          return RefreshIndicator(
            onRefresh: () => ref.refresh(issueDetailProvider(widget.id).future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                ResponsiveCenter(
                  child: DetailHeader(
                    title: s.issueNo,
                    badge: s.acknowledged ? 'Acknowledged' : 'Awaiting acknowledgment',
                    badgeTone: s.acknowledged ? 'approved' : 'pending',
                    headline: s.totalValue == null ? null : f.money(s.totalValue),
                    fields: [
                      ('Request', s.request?.no ?? Formatters.dash, Icons.assignment_outlined),
                      ('Project', s.project?.label ?? Formatters.dash, Icons.apartment_outlined),
                      ('Store', s.store?.label ?? Formatters.dash, Icons.warehouse_outlined),
                      ('Issued', '${f.dateTime(s.issuedAt)} by ${s.issuer?.name ?? ''}', Icons.event_outlined),
                      ('Recipient', s.recipientName, Icons.person_outline),
                      if ((d.notes ?? '').isNotEmpty) ('Notes', d.notes!, Icons.notes),
                      if (d.ackAt != null)
                        (
                          'Acknowledged',
                          '${f.dateTime(d.ackAt)} by ${d.ackBy?.name ?? d.ackRecipient ?? ''}'
                              '${(d.ackRemarks ?? '').isNotEmpty ? ': ${d.ackRemarks}' : ''}',
                          Icons.verified_outlined,
                        ),
                    ],
                  ),
                ),
                if (s.request != null)
                  ResponsiveCenter(
                    child: TextButton.icon(
                      onPressed: () => context.push('/request/${s.request!.id}'),
                      icon: const Icon(Icons.open_in_new),
                      label: Text('Open request ${s.request!.no}'),
                    ),
                  ),
                if (d.lines.any((l) => l.hasBalance))
                  ResponsiveCenter(
                    child: Card(
                      key: const Key('issue-balance-note'),
                      color: Theme.of(context).colorScheme.tertiaryContainer,
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Text(
                          'Partial issue. Balance remaining on ${s.request?.no ?? 'the request'}: '
                          '${d.lines.where((l) => l.hasBalance).map((l) => '${l.item.name} ${f.qty(l.balanceQty)} ${l.item.unit}').join(', ')}. '
                          'It stays outstanding and is issued when stock is available.',
                          style: TextStyle(color: Theme.of(context).colorScheme.onTertiaryContainer),
                        ),
                      ),
                    ),
                  ),
                const ResponsiveCenter(child: SectionTitle('Items issued')),
                ResponsiveCenter(
                  child: LinesCard(
                    children: [
                      for (final l in d.lines)
                        LineTile(
                          name: l.item.name,
                          subtitle: '${l.item.sku} · ${l.item.unit}',
                          figures: [
                            if (l.approvedQty != null) ('Approved', f.qty(l.approvedQty)),
                            ('Issued', f.qty(l.qty)),
                            if (l.balanceQty != null) ('Balance', f.qty(l.balanceQty)),
                            if (l.returnedQty != null && Decimal.parse(l.returnedQty!) > Decimal.zero) ('Returned', f.qty(l.returnedQty)),
                            if (l.lineValue != null) ('Value', f.money(l.lineValue)),
                          ],
                        ),
                    ],
                  ),
                ),
                if (d.returns.isNotEmpty) ...[
                  const ResponsiveCenter(child: SectionTitle('Returns')),
                  ResponsiveCenter(
                    child: Card(
                      child: Column(
                        children: [
                          for (final r in d.returns)
                            ListTile(
                              leading: const Icon(Icons.assignment_return_outlined),
                              title: Text(r.no),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: () => context.push('/returns/${r.id}'),
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
      bottomNavigationBar: d == null || (!d.canAcknowledge && !d.canReturn)
          ? null
          : BottomActions(
              children: [
                if (d.canReturn)
                  OutlinedButton.icon(
                    onPressed: _busy ? null : () => context.push('/issues/${d.summary.id}/return'),
                    icon: const Icon(Icons.assignment_return_outlined),
                    label: const Text('Record return'),
                  ),
                if (d.canAcknowledge)
                  FilledButton.icon(
                    onPressed: _busy ? null : () => _acknowledge(d),
                    icon: const Icon(Icons.verified_outlined),
                    label: const Text('Acknowledge receipt'),
                  ),
              ],
            ),
    );
  }
}

class _AckDialog extends StatefulWidget {
  const _AckDialog();

  @override
  State<_AckDialog> createState() => _AckDialogState();
}

class _AckDialogState extends State<_AckDialog> {
  final _c = TextEditingController();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Acknowledge receipt'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Confirm the materials on this voucher were collected.'),
        const SizedBox(height: 12),
        TextField(
          controller: _c,
          decoration: const InputDecoration(labelText: 'Remarks (optional)'),
          maxLength: 1000,
        ),
      ],
    ),
    actions: [
      TextButton(onPressed: () => Navigator.pop(context), child: const Text('Back')),
      FilledButton(onPressed: () => Navigator.pop(context, _c.text), child: const Text('Acknowledge')),
    ],
  );
}

// ====================================================================== return form

/// `/issues/:id/return`: record materials coming back from site into a store.
class ReturnFormScreen extends ConsumerStatefulWidget {
  const ReturnFormScreen({super.key, required this.issueId});
  final int issueId;

  @override
  ConsumerState<ReturnFormScreen> createState() => _ReturnFormScreenState();
}

class _ReturnFormScreenState extends ConsumerState<ReturnFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _qty = <int, TextEditingController>{};
  final _by = TextEditingController();
  final _reason = TextEditingController();
  final _key = newIdempotencyKey();
  int? _storeId;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in _qty.values) {
      c.dispose();
    }
    _by.dispose();
    _reason.dispose();
    super.dispose();
  }

  TextEditingController _ctl(int id) => _qty.putIfAbsent(id, TextEditingController.new);

  Future<void> _save(IssueDetail d) async {
    if (!_formKey.currentState!.validate()) return;
    final qty = <int, String>{
      for (final e in _qty.entries)
        if (e.value.text.trim().isNotEmpty && Decimal.parse(e.value.text.trim()) > Decimal.zero) e.key: e.value.text.trim(),
    };
    if (qty.isEmpty) {
      showSnack(context, 'Enter a quantity for at least one item.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final r = await ref
          .read(opsRepositoryProvider)
          .recordReturn(widget.issueId, storeId: _storeId!, qty: qty, returnedByName: _by.text, reason: _reason.text, idempotencyKey: _key);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Return ${r.summary.returnNo} recorded');
      context.pushReplacement('/returns/${r.summary.id}');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(issueDetailProvider(widget.issueId));
    final stores = ref.watch(storeOptionsProvider);
    final f = ref.watch(formattersProvider);
    final operable = stores.value?.operable ?? const [];
    _storeId ??= async.value?.summary.store != null && operable.any((s) => s.id == async.value!.summary.store!.id)
        ? async.value!.summary.store!.id
        : (operable.isEmpty ? null : operable.first.id);
    final d = async.value;
    return Scaffold(
      appBar: AppBar(title: Text(d == null ? 'Record return' : 'Return on ${d.summary.issueNo}'), bottom: busyBar(_busy)),
      body: async.when(
        loading: () => const SkeletonList(count: 3),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(issueDetailProvider(widget.issueId))),
        data: (d) => Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ResponsiveCenter(
                child: DropdownButtonFormField<int>(
                  isExpanded: true,
                  initialValue: _storeId,
                  decoration: const InputDecoration(labelText: 'Return into store'),
                  items: [
                    for (final s in operable)
                      DropdownMenuItem(
                        value: s.id,
                        child: Text(s.label, overflow: TextOverflow.ellipsis),
                      ),
                  ],
                  onChanged: (v) => setState(() => _storeId = v),
                  validator: (v) => v == null ? 'Choose a store' : null,
                ),
              ),
              const ResponsiveCenter(child: SectionTitle('Quantities coming back')),
              ResponsiveCenter(
                child: Card(
                  child: Column(
                    children: [
                      for (final l in d.lines) ...[
                        if (l != d.lines.first) const Divider(height: 1),
                        Builder(
                          builder: (context) {
                            final returnable = (Decimal.parse(l.qty) - Decimal.parse(l.returnedQty ?? '0')).toString();
                            return Padding(
                              padding: const EdgeInsets.all(14),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(l.item.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                                        Text(
                                          'Issued ${f.qty(l.qty)} · can return ${f.qty(returnable)} ${l.item.unit}',
                                          style: Theme.of(context).textTheme.bodySmall,
                                        ),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  SizedBox(
                                    width: 110,
                                    child: TextFormField(
                                      controller: _ctl(l.id),
                                      enabled: !_busy && Decimal.parse(returnable) > Decimal.zero,
                                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                      textAlign: TextAlign.end,
                                      decoration: InputDecoration(labelText: 'Return', suffixText: l.item.unit, isDense: true),
                                      validator: (v) => validateQty(v, max: returnable, allowEmpty: true, allowZero: true),
                                    ),
                                  ),
                                ],
                              ),
                            );
                          },
                        ),
                      ],
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              ResponsiveCenter(
                child: TextFormField(
                  controller: _by,
                  enabled: !_busy,
                  decoration: const InputDecoration(labelText: 'Returned by (name)'),
                  validator: (v) => (v ?? '').trim().isEmpty ? 'Required' : null,
                ),
              ),
              const SizedBox(height: 12),
              ResponsiveCenter(
                child: TextFormField(
                  controller: _reason,
                  enabled: !_busy,
                  maxLines: 2,
                  decoration: const InputDecoration(labelText: 'Reason'),
                  validator: (v) => (v ?? '').trim().length < 5 ? 'At least 5 characters' : null,
                ),
              ),
              const SizedBox(height: 80),
            ],
          ),
        ),
      ),
      bottomNavigationBar: d == null
          ? null
          : BottomActions(
              children: [
                FilledButton.icon(
                  onPressed: _busy || operable.isEmpty ? null : () => _save(d),
                  icon: const Icon(Icons.assignment_return_outlined),
                  label: const Text('Record return'),
                ),
              ],
            ),
    );
  }
}
