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
import '../../stock/domain/stock_models.dart';
import '../../stock/presentation/item_picker.dart';
import '../data/ops_repository.dart';
import '../domain/ops_models.dart';
import 'ops_widgets.dart';

// ====================================================================== receipts

class ReceiptsScreen extends ConsumerStatefulWidget {
  const ReceiptsScreen({super.key});

  @override
  ConsumerState<ReceiptsScreen> createState() => _ReceiptsScreenState();
}

class _ReceiptsScreenState extends ConsumerState<ReceiptsScreen> {
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
    final repo = ref.watch(opsRepositoryProvider);
    final f = ref.watch(formattersProvider);
    final can = ref.watch(currentUserProvider)?.can;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Stock receipts'),
        actions: [
          if (can?.manageStock ?? false)
            IconButton(tooltip: 'Stock adjustment', onPressed: () => context.push('/adjustments/new'), icon: const Icon(Icons.tune)),
        ],
      ),
      floatingActionButton: (can?.manageStock ?? false)
          ? FloatingActionButton.extended(onPressed: () => context.push('/receipts/new'), icon: const Icon(Icons.add), label: const Text('Receive stock'))
          : null,
      body: Column(
        children: [
          SearchField(hint: 'Receipt, supplier or reference', onChanged: (v) => setState(() => _q = v)),
          Expanded(
            child: PagedListView<ReceiptSummary>(
              controller: _controller,
              filterKey: _q,
              fetch: (page) => repo.receipts(page: page, q: _q),
              emptyIcon: Icons.move_to_inbox_outlined,
              emptyTitle: 'No receipts',
              itemBuilder: (context, r) => DocCard(
                title: r.receiptNo,
                badge: r.typeLabel,
                badgeTone: r.type == 'opening' ? 'muted' : 'approved',
                subtitle: [r.supplierName, r.reference, r.purchaseOrder?.no].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
                meta: [r.store?.code, f.date(r.receivedOn), '${r.linesCount} item(s)'].whereType<String>().join(' · '),
                trailing: r.totalValue == null ? null : f.money(r.totalValue),
                onTap: () => context.push('/receipts/${r.id}'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class ReceiptDetailScreen extends ConsumerWidget {
  const ReceiptDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(receiptDetailProvider(id));
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: Text(async.value?.summary.receiptNo ?? 'Receipt')),
      body: async.when(
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(receiptDetailProvider(id))),
        data: (d) {
          final s = d.summary;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ResponsiveCenter(
                child: DetailHeader(
                  title: s.receiptNo,
                  badge: s.typeLabel,
                  badgeTone: s.type == 'opening' ? 'muted' : 'approved',
                  headline: s.totalValue == null ? null : f.money(s.totalValue),
                  fields: [
                    ('Store', s.store?.label ?? Formatters.dash, Icons.warehouse_outlined),
                    ('Received on', f.date(s.receivedOn), Icons.event_outlined),
                    if ((s.supplierName ?? '').isNotEmpty) ('Supplier', s.supplierName!, Icons.storefront_outlined),
                    if ((s.reference ?? '').isNotEmpty) ('Delivery note / reference', s.reference!, Icons.tag),
                    if (s.purchaseOrder != null) ('Purchase order', s.purchaseOrder!.no, Icons.shopping_cart_outlined),
                    ('Received by', s.receiver?.name ?? Formatters.dash, Icons.person_outline),
                    if ((d.notes ?? '').isNotEmpty) ('Notes', d.notes!, Icons.notes),
                  ],
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
                        figures: [
                          ('Qty', f.qty(l.qty)),
                          if (l.unitCost != null) ('Unit cost', f.money(l.unitCost)),
                          if (l.total != null) ('Total', f.money(l.total)),
                        ],
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// Store picker shared by the receipt / adjustment / transfer forms (stores the user operates).
class _StoreField extends ConsumerWidget {
  const _StoreField({required this.label, required this.value, required this.onChanged, this.all = false, this.exclude});
  final String label;
  final int? value;
  final ValueChanged<int?> onChanged;
  final bool all;
  final int? exclude;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final opts = ref.watch(storeOptionsProvider);
    return opts.when(
      loading: () => const LinearProgressIndicator(),
      error: (e, _) => Text(ApiException.messageOf(e)),
      data: (o) {
        final list = (all ? o.active : o.operable).where((s) => s.id != exclude).toList();
        if (value == null && list.isNotEmpty) WidgetsBinding.instance.addPostFrameCallback((_) => onChanged(list.first.id));
        return DropdownButtonFormField<int>(
          isExpanded: true,
          initialValue: list.any((s) => s.id == value) ? value : null,
          decoration: InputDecoration(labelText: label),
          items: [
            for (final s in list)
              DropdownMenuItem(
                value: s.id,
                child: Text(s.label, overflow: TextOverflow.ellipsis),
              ),
          ],
          onChanged: onChanged,
          validator: (v) => v == null ? 'Choose a store' : null,
        );
      },
    );
  }
}

ItemRef _ref(Item item) => ItemRef(id: item.id, sku: item.sku, name: item.name, unit: item.unit);

/// Editable list of item lines (with optional unit cost), used by new receipt and new transfer.
class _DraftLines extends StatelessWidget {
  const _DraftLines({required this.lines, required this.withCost, required this.onChanged, required this.enabled});
  final List<DraftLine> lines;
  final bool withCost;
  final VoidCallback onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) => Column(
    children: [
      for (final l in lines)
        Card(
          key: ObjectKey(l),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 8, 4, 12),
            child: Column(
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text('${l.item.name} (${l.item.unit})', style: const TextStyle(fontWeight: FontWeight.w600)),
                    ),
                    IconButton(
                      tooltip: 'Remove ${l.item.name}',
                      onPressed: enabled
                          ? () {
                              lines.remove(l);
                              onChanged();
                            }
                          : null,
                      icon: const Icon(Icons.delete_outline),
                    ),
                  ],
                ),
                Padding(
                  padding: const EdgeInsets.only(right: 10),
                  child: Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          initialValue: l.qty,
                          enabled: enabled,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: InputDecoration(labelText: 'Quantity', suffixText: l.item.unit, isDense: true),
                          onChanged: (v) => l.qty = v,
                          validator: (v) => validateQty(v),
                        ),
                      ),
                      if (withCost) ...[
                        const SizedBox(width: 10),
                        Expanded(
                          child: TextFormField(
                            initialValue: l.unitCost,
                            enabled: enabled,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: const InputDecoration(labelText: 'Unit cost', isDense: true),
                            onChanged: (v) => l.unitCost = v,
                            validator: (v) {
                              final d = Decimal.tryParse((v ?? '').trim().replaceAll(',', ''));
                              return d == null || d < Decimal.zero ? 'Enter the price' : null;
                            },
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: OutlinedButton.icon(
          onPressed: !enabled
              ? null
              : () async {
                  final item = await pickItem(context);
                  if (item == null) return;
                  if (lines.any((x) => x.item.id == item.id)) {
                    if (context.mounted) showSnack(context, '${item.name} is already on the list.');
                    return;
                  }
                  lines.add(DraftLine(item: _ref(item)));
                  onChanged();
                },
          icon: const Icon(Icons.add),
          label: const Text('Add item'),
        ),
      ),
    ],
  );
}

class NewReceiptScreen extends ConsumerStatefulWidget {
  const NewReceiptScreen({super.key});

  @override
  ConsumerState<NewReceiptScreen> createState() => _NewReceiptScreenState();
}

class _NewReceiptScreenState extends ConsumerState<NewReceiptScreen> {
  final _formKey = GlobalKey<FormState>();
  final _supplier = TextEditingController();
  final _reference = TextEditingController();
  final _notes = TextEditingController();
  final _lines = <DraftLine>[];
  final _key = newIdempotencyKey();
  DateTime _date = DateTime.now();
  int? _storeId;
  bool _busy = false;

  @override
  void dispose() {
    _supplier.dispose();
    _reference.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    if (_lines.isEmpty) {
      showSnack(context, 'Add at least one item.', error: true);
      return;
    }
    for (final l in _lines) {
      l.unitCost = l.unitCost.replaceAll(',', '');
    }
    setState(() => _busy = true);
    try {
      final r = await ref
          .read(opsRepositoryProvider)
          .createReceipt(
            storeId: _storeId!,
            receivedOn: Formatters.ymd(_date),
            supplierName: _supplier.text,
            reference: _reference.text,
            notes: _notes.text,
            lines: _lines,
            idempotencyKey: _key,
          );
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Stock recorded: ${r.summary.receiptNo}');
      context.pushReplacement('/receipts/${r.summary.id}');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Receive stock'), bottom: busyBar(_busy)),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ResponsiveCenter(
              child: _StoreField(label: 'Into store', value: _storeId, onChanged: (v) => setState(() => _storeId = v)),
            ),
            const SizedBox(height: 12),
            ResponsiveCenter(
              child: ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.event_outlined),
                title: const Text('Received on'),
                subtitle: Text(f.date(Formatters.ymd(_date))),
                onTap: _busy
                    ? null
                    : () async {
                        final d = await showDatePicker(context: context, initialDate: _date, firstDate: DateTime(2020), lastDate: DateTime.now());
                        if (d != null) setState(() => _date = d);
                      },
              ),
            ),
            ResponsiveCenter(
              child: TextFormField(
                controller: _supplier,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'Supplier'),
                validator: (v) => (v ?? '').trim().isEmpty ? 'Required' : null,
              ),
            ),
            const SizedBox(height: 12),
            ResponsiveCenter(
              child: TextFormField(
                controller: _reference,
                enabled: !_busy,
                decoration: const InputDecoration(labelText: 'Delivery note / reference (optional)'),
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
            const ResponsiveCenter(child: SectionTitle('Items received')),
            ResponsiveCenter(
              child: _DraftLines(lines: _lines, withCost: true, enabled: !_busy, onChanged: () => setState(() {})),
            ),
            const SizedBox(height: 80),
          ],
        ),
      ),
      bottomNavigationBar: BottomActions(
        children: [FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.move_to_inbox_outlined), label: const Text('Record receipt'))],
      ),
    );
  }
}

// ====================================================================== adjustment

class NewAdjustmentScreen extends ConsumerStatefulWidget {
  const NewAdjustmentScreen({super.key});

  @override
  ConsumerState<NewAdjustmentScreen> createState() => _NewAdjustmentScreenState();
}

class _NewAdjustmentScreenState extends ConsumerState<NewAdjustmentScreen> {
  final _formKey = GlobalKey<FormState>();
  final _qty = TextEditingController();
  final _reason = TextEditingController();
  ItemRef? _item;
  int? _storeId;
  bool _decrease = true;
  bool _busy = false;

  @override
  void dispose() {
    _qty.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    if (_item == null) {
      showSnack(context, 'Choose an item.', error: true);
      return;
    }
    final delta = '${_decrease ? '-' : ''}${_qty.text.trim()}';
    final ok = await confirmDialog(
      context,
      title: 'Post adjustment?',
      message: '${_decrease ? 'Remove' : 'Add'} ${_qty.text.trim()} ${_item!.unit} of ${_item!.name}.',
      confirmLabel: 'Post',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      final onHand = await ref.read(opsRepositoryProvider).adjust(storeId: _storeId!, itemId: _item!.id, qtyDelta: delta, reason: _reason.text);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Adjustment posted. ${_item!.name} now ${ref.read(formattersProvider).qty(onHand)} ${_item!.unit} in this store.');
      context.pop();
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Stock adjustment'), bottom: busyBar(_busy)),
    body: Form(
      key: _formKey,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const ResponsiveCenter(
            child: Text('Corrects the quantity in a store (damage, loss, count differences). Every adjustment is logged with its reason.'),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: _StoreField(label: 'Store', value: _storeId, onChanged: (v) => setState(() => _storeId = v)),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: Card(
              child: ListTile(
                leading: const Icon(Icons.inventory_2_outlined),
                title: Text(_item?.name ?? 'Choose an item'),
                subtitle: _item == null ? null : Text('${_item!.sku} · ${_item!.unit}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: _busy
                    ? null
                    : () async {
                        final i = await pickItem(context);
                        if (i != null) setState(() => _item = _ref(i));
                      },
              ),
            ),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: true, label: Text('Remove'), icon: Icon(Icons.remove)),
                ButtonSegment(value: false, label: Text('Add'), icon: Icon(Icons.add)),
              ],
              selected: {_decrease},
              onSelectionChanged: _busy ? null : (s) => setState(() => _decrease = s.first),
            ),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: TextFormField(
              controller: _qty,
              enabled: !_busy,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: 'Quantity', suffixText: _item?.unit),
              validator: (v) => validateQty(v),
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
        ],
      ),
    ),
    bottomNavigationBar: BottomActions(
      children: [FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.tune), label: const Text('Post adjustment'))],
    ),
  );
}

// ====================================================================== transfers

class TransfersScreen extends ConsumerStatefulWidget {
  const TransfersScreen({super.key});

  @override
  ConsumerState<TransfersScreen> createState() => _TransfersScreenState();
}

class _TransfersScreenState extends ConsumerState<TransfersScreen> {
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
    final repo = ref.watch(opsRepositoryProvider);
    final f = ref.watch(formattersProvider);
    final canCreate = ref.watch(currentUserProvider)?.can.createTransfer ?? false;
    return Scaffold(
      appBar: AppBar(title: const Text('Stock transfers')),
      floatingActionButton: canCreate
          ? FloatingActionButton.extended(
              onPressed: () => context.push('/transfers/new'),
              icon: const Icon(Icons.local_shipping_outlined),
              label: const Text('Dispatch'),
            )
          : null,
      body: Column(
        children: [
          SearchField(hint: 'Transfer number', onChanged: (v) => setState(() => _q = v)),
          FilterChipsBar<String?>(options: TransferStatuses.filters, selected: _status, onSelected: (s) => setState(() => _status = s)),
          Expanded(
            child: PagedListView<TransferSummary>(
              controller: _controller,
              filterKey: (_status, _q),
              fetch: (page) => repo.transfers(page: page, status: _status, q: _q),
              emptyIcon: Icons.local_shipping_outlined,
              emptyTitle: 'No transfers',
              itemBuilder: (context, t) => DocCard(
                title: t.transferNo,
                badge: t.statusLabel,
                badgeTone: t.statusTone,
                subtitle: '${t.fromStore?.code ?? '?'} → ${t.toStore?.code ?? '?'}',
                meta: [f.dateTime(t.dispatchedAt), t.dispatcher?.name, '${t.linesCount} item(s)'].whereType<String>().join(' · '),
                onTap: () => context.push('/transfers/${t.id}'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class TransferDetailScreen extends ConsumerStatefulWidget {
  const TransferDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<TransferDetailScreen> createState() => _TransferDetailScreenState();
}

class _TransferDetailScreenState extends ConsumerState<TransferDetailScreen> {
  final _received = <int, TextEditingController>{};
  final _reasons = <int, TextEditingController>{};
  final _formKey = GlobalKey<FormState>();
  bool _receiving = false;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [..._received.values, ..._reasons.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action, String done) async {
    setState(() => _busy = true);
    try {
      await action();
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      setState(() => _receiving = false);
      showSnack(context, done);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startReceive(TransferDetail d) {
    for (final l in d.lines) {
      _received.putIfAbsent(l.id, () => TextEditingController(text: Decimal.parse(l.qty).toString()));
      _reasons.putIfAbsent(l.id, TextEditingController.new);
    }
    setState(() => _receiving = true);
  }

  Future<void> _confirmReceive(TransferDetail d) async {
    if (!_formKey.currentState!.validate()) return;
    await _run(
      () => ref
          .read(opsRepositoryProvider)
          .receiveTransfer(
            d.summary.id,
            received: {for (final l in d.lines) l.id: _received[l.id]!.text.trim()},
            reasons: {for (final l in d.lines) l.id: _reasons[l.id]!.text},
          ),
      '${d.summary.transferNo} received into stock',
    );
  }

  Future<void> _cancel(TransferDetail d) async {
    final reason = await askReason(context, title: 'Cancel ${d.summary.transferNo}', hint: 'Why is it cancelled?');
    if (reason == null || !mounted) return;
    if (reason.trim().length < 5) {
      showSnack(context, 'Give a reason of at least 5 characters.', error: true);
      return;
    }
    await _run(() => ref.read(opsRepositoryProvider).cancelTransfer(d.summary.id, reason), 'Transfer cancelled; stock returned to the source store');
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(transferDetailProvider(widget.id));
    final d = async.value;
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: Text(d?.summary.transferNo ?? 'Transfer'), bottom: busyBar(_busy)),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(transferDetailProvider(widget.id))),
        data: (d) {
          final s = d.summary;
          return Form(
            key: _formKey,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                ResponsiveCenter(
                  child: DetailHeader(
                    title: s.transferNo,
                    badge: s.statusLabel,
                    badgeTone: s.statusTone,
                    fields: [
                      ('From', s.fromStore?.label ?? Formatters.dash, Icons.outbox_outlined),
                      ('To', s.toStore?.label ?? Formatters.dash, Icons.move_to_inbox_outlined),
                      ('Dispatched', '${f.dateTime(s.dispatchedAt)} by ${s.dispatcher?.name ?? ''}', Icons.local_shipping_outlined),
                      if (s.receivedAt != null) ('Received', '${f.dateTime(s.receivedAt)} by ${s.receiver?.name ?? ''}', Icons.verified_outlined),
                      if ((d.notes ?? '').isNotEmpty) ('Notes', d.notes!, Icons.notes),
                      if ((d.receiveNotes ?? '').isNotEmpty) ('Receiving notes', d.receiveNotes!, Icons.notes),
                    ],
                  ),
                ),
                ResponsiveCenter(child: SectionTitle(_receiving ? 'Quantities that arrived' : 'Items')),
                ResponsiveCenter(
                  child: _receiving
                      ? Card(
                          child: Column(
                            children: [
                              for (final l in d.lines) ...[
                                if (l != d.lines.first) const Divider(height: 1),
                                Padding(
                                  padding: const EdgeInsets.all(14),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text('${l.item.name} · sent ${f.qty(l.qty)} ${l.item.unit}', style: const TextStyle(fontWeight: FontWeight.w600)),
                                      const SizedBox(height: 8),
                                      TextFormField(
                                        controller: _received[l.id],
                                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                        decoration: InputDecoration(labelText: 'Received', suffixText: l.item.unit, isDense: true),
                                        onChanged: (_) => setState(() {}),
                                        validator: (v) => validateQty(v, max: l.qty, allowZero: true),
                                      ),
                                      if (Decimal.tryParse(_received[l.id]!.text.trim()) != null &&
                                          Decimal.parse(_received[l.id]!.text.trim()) < Decimal.parse(l.qty))
                                        TextFormField(
                                          controller: _reasons[l.id],
                                          decoration: const InputDecoration(labelText: 'Reason for the shortfall', isDense: true),
                                          validator: (v) => (v ?? '').trim().isEmpty ? 'Say why less arrived' : null,
                                        ),
                                    ],
                                  ),
                                ),
                              ],
                            ],
                          ),
                        )
                      : LinesCard(
                          children: [
                            for (final l in d.lines)
                              LineTile(
                                name: l.item.name,
                                subtitle: '${l.item.sku} · ${l.item.unit}',
                                note: l.shortfallReason,
                                figures: [('Sent', f.qty(l.qty)), if (l.receivedQty != null) ('Received', f.qty(l.receivedQty))],
                              ),
                          ],
                        ),
                ),
                const SizedBox(height: 80),
              ],
            ),
          );
        },
      ),
      bottomNavigationBar: d == null || (!d.canReceive && !d.canCancel)
          ? null
          : BottomActions(
              children: [
                if (_receiving)
                  OutlinedButton(onPressed: _busy ? null : () => setState(() => _receiving = false), child: const Text('Back'))
                else if (d.canCancel)
                  DangerOutlinedButton(label: 'Cancel', icon: Icons.close, onPressed: _busy ? null : () => _cancel(d)),
                if (d.canReceive)
                  FilledButton.icon(
                    onPressed: _busy ? null : () => _receiving ? _confirmReceive(d) : _startReceive(d),
                    icon: const Icon(Icons.move_to_inbox_outlined),
                    label: Text(_receiving ? 'Confirm receipt' : 'Receive'),
                  ),
              ],
            ),
    );
  }
}

class NewTransferScreen extends ConsumerStatefulWidget {
  const NewTransferScreen({super.key});

  @override
  ConsumerState<NewTransferScreen> createState() => _NewTransferScreenState();
}

class _NewTransferScreenState extends ConsumerState<NewTransferScreen> {
  final _formKey = GlobalKey<FormState>();
  final _notes = TextEditingController();
  final _lines = <DraftLine>[];
  final _key = newIdempotencyKey();
  int? _from;
  int? _to;
  bool _busy = false;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    if (_lines.isEmpty) {
      showSnack(context, 'Add at least one item.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final t = await ref
          .read(opsRepositoryProvider)
          .dispatchTransfer(fromStoreId: _from!, toStoreId: _to!, lines: _lines, notes: _notes.text, idempotencyKey: _key);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Transfer ${t.summary.transferNo} dispatched. The receiving store confirms quantities on arrival.');
      context.pushReplacement('/transfers/${t.summary.id}');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Dispatch transfer'), bottom: busyBar(_busy)),
    body: Form(
      key: _formKey,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ResponsiveCenter(
            child: _StoreField(label: 'From store', value: _from, onChanged: (v) => setState(() => _from = v)),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: _StoreField(label: 'To store', all: true, exclude: _from, value: _to, onChanged: (v) => setState(() => _to = v)),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: TextFormField(
              controller: _notes,
              enabled: !_busy,
              decoration: const InputDecoration(labelText: 'Notes (optional)'),
            ),
          ),
          const ResponsiveCenter(child: SectionTitle('Items to send')),
          ResponsiveCenter(
            child: _DraftLines(lines: _lines, withCost: false, enabled: !_busy, onChanged: () => setState(() {})),
          ),
          const SizedBox(height: 80),
        ],
      ),
    ),
    bottomNavigationBar: BottomActions(
      children: [FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.local_shipping_outlined), label: const Text('Dispatch'))],
    ),
  );
}

// ====================================================================== returns

class ReturnsScreen extends ConsumerStatefulWidget {
  const ReturnsScreen({super.key});

  @override
  ConsumerState<ReturnsScreen> createState() => _ReturnsScreenState();
}

class _ReturnsScreenState extends ConsumerState<ReturnsScreen> {
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
    final repo = ref.watch(opsRepositoryProvider);
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Returns to store')),
      body: Column(
        children: [
          SearchField(hint: 'Return number or returned by', onChanged: (v) => setState(() => _q = v)),
          Expanded(
            child: PagedListView<ReturnSummary>(
              controller: _controller,
              filterKey: _q,
              fetch: (page) => repo.returns(page: page, q: _q),
              emptyIcon: Icons.assignment_return_outlined,
              emptyTitle: 'No returns',
              emptyMessage: 'Record a return from the issue voucher the materials came from.',
              itemBuilder: (context, r) => DocCard(
                title: r.returnNo,
                subtitle: [r.issue?.no, r.project?.code, 'by ${r.returnedByName}'].whereType<String>().join(' · '),
                meta: [r.store?.code, f.dateTime(r.returnedAt)].whereType<String>().join(' · '),
                trailing: r.totalValue == null ? null : f.money(r.totalValue),
                onTap: () => context.push('/returns/${r.id}'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class ReturnDetailScreen extends ConsumerWidget {
  const ReturnDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(returnDetailProvider(id));
    final f = ref.watch(formattersProvider);
    return Scaffold(
      appBar: AppBar(title: Text(async.value?.summary.returnNo ?? 'Return')),
      body: async.when(
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(returnDetailProvider(id))),
        data: (d) {
          final s = d.summary;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ResponsiveCenter(
                child: DetailHeader(
                  title: s.returnNo,
                  headline: s.totalValue == null ? null : 'Project credit ${f.money(s.totalValue)}',
                  fields: [
                    ('Voucher', s.issue?.no ?? Formatters.dash, Icons.receipt_outlined),
                    ('Project', s.project?.label ?? Formatters.dash, Icons.apartment_outlined),
                    ('Into store', s.store?.label ?? Formatters.dash, Icons.warehouse_outlined),
                    ('Returned', '${f.dateTime(s.returnedAt)} by ${s.returnedByName}', Icons.event_outlined),
                    if ((s.reason ?? '').isNotEmpty) ('Reason', s.reason!, Icons.notes),
                    ('Received by', s.receiver?.name ?? Formatters.dash, Icons.person_outline),
                  ],
                ),
              ),
              if (s.issue != null)
                ResponsiveCenter(
                  child: TextButton.icon(
                    onPressed: () => context.push('/issues/${s.issue!.id}'),
                    icon: const Icon(Icons.open_in_new),
                    label: Text('Open voucher ${s.issue!.no}'),
                  ),
                ),
              const ResponsiveCenter(child: SectionTitle('Items returned')),
              ResponsiveCenter(
                child: LinesCard(
                  children: [
                    for (final l in d.lines)
                      LineTile(
                        name: l.item.name,
                        subtitle: '${l.item.sku} · ${l.item.unit}',
                        figures: [('Qty', f.qty(l.qty)), if (l.total != null) ('Credit', f.money(l.total))],
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
