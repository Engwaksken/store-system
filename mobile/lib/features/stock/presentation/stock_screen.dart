import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/refs.dart';
import '../../../core/format/formatters.dart';
import '../../../core/widgets/common.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/stock_repository.dart';
import '../domain/stock_models.dart';

class StockScreen extends ConsumerStatefulWidget {
  const StockScreen({super.key});

  @override
  ConsumerState<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends ConsumerState<StockScreen> {
  String _q = '';
  int? _storeId;
  bool _low = false;

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(stockRepositoryProvider);
    final stores = ref.watch(storesProvider).value ?? const <CodedRef>[];
    final f = ref.watch(formattersProvider);
    final storeName = stores.where((s) => s.id == _storeId).firstOrNull?.name;

    return Scaffold(
      appBar: AppBar(title: const Text('Stock')),
      body: Column(
        children: [
          SearchField(hint: 'Search SKU or item name', onChanged: (v) => setState(() => _q = v)),
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              children: [
                MenuAnchor(
                  builder: (context, menu, _) => InputChip(
                    avatar: const Icon(Icons.store_mall_directory_outlined, size: 18),
                    label: Text(storeName ?? 'All stores'),
                    selected: _storeId != null,
                    onPressed: () => menu.isOpen ? menu.close() : menu.open(),
                    onDeleted: _storeId == null ? null : () => setState(() => _storeId = null),
                    deleteButtonTooltipMessage: 'Show all stores',
                  ),
                  menuChildren: [
                    MenuItemButton(onPressed: () => setState(() => _storeId = null), child: const Text('All stores')),
                    for (final s in stores)
                      MenuItemButton(onPressed: () => setState(() => _storeId = s.id), child: Text(s.label)),
                  ],
                ),
                const SizedBox(width: 8),
                FilterChip(
                  avatar: _low ? null : const Icon(Icons.warning_amber, size: 18),
                  label: const Text('Low stock'),
                  selected: _low,
                  onSelected: (v) => setState(() => _low = v),
                ),
              ],
            ),
          ),
          Expanded(
            child: PagedListView<Item>(
              filterKey: (_q, _storeId, _low),
              fetch: (page) => repo.items(page: page, q: _q, storeId: _storeId, low: _low),
              emptyIcon: Icons.inventory_2_outlined,
              emptyTitle: _low ? 'No low-stock items' : 'No items found',
              emptyMessage: _q.isEmpty ? null : 'Try another search.',
              itemBuilder: (context, item) => _ItemTile(item: item, formatters: f, storeName: storeName),
            ),
          ),
        ],
      ),
    );
  }
}

class _ItemTile extends StatelessWidget {
  const _ItemTile({required this.item, required this.formatters, this.storeName});
  final Item item;
  final Formatters formatters;
  final String? storeName;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final f = formatters;
    return Card(
      child: InkWell(
        onTap: () => context.push('/item/${item.id}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(item.name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(
                      [item.sku, if ((item.category ?? '').isNotEmpty) item.category!].join(' · '),
                      style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    ),
                    if (item.low || !item.isActive) ...[
                      const SizedBox(height: 6),
                      Wrap(spacing: 6, children: [
                        if (item.low) StatusBadge.status(label: 'Low stock', statusTone: 'rejected', dense: true),
                        if (!item.isActive) StatusBadge.status(label: 'Inactive', statusTone: 'muted', dense: true),
                      ]),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Semantics(
                label: 'On hand ${f.qty(item.onHand)} ${item.unit}${storeName == null ? '' : ' in $storeName'}',
                excludeSemantics: true,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      f.qty(item.onHand),
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: item.low ? theme.colorScheme.error : null,
                      ),
                    ),
                    Text(item.unit, style: theme.textTheme.bodySmall),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
