import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/refs.dart';
import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/stock_repository.dart';
import '../domain/stock_models.dart';
import 'item_widgets.dart';

class StockScreen extends ConsumerStatefulWidget {
  const StockScreen({super.key});

  @override
  ConsumerState<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends ConsumerState<StockScreen> {
  String _q = '';
  int? _storeId;

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(stockRepositoryProvider);
    final stores = ref.watch(storesProvider).value ?? const <CodedRef>[];
    final f = ref.watch(formattersProvider);
    final storeName = stores.where((s) => s.id == _storeId).firstOrNull?.name;
    final filter = ref.watch(stockFilterProvider);
    void setFilter(StockFilter f) => ref.read(stockFilterProvider.notifier).set(f);

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
                  avatar: filter == StockFilter.low ? null : const Icon(Icons.warning_amber, size: 18),
                  label: const Text('Low stock'),
                  selected: filter == StockFilter.low,
                  onSelected: (v) => setFilter(v ? StockFilter.low : StockFilter.all),
                ),
                const SizedBox(width: 8),
                FilterChip(
                  avatar: filter == StockFilter.out ? null : const Icon(Icons.remove_shopping_cart_outlined, size: 18),
                  label: const Text('Out of stock'),
                  selected: filter == StockFilter.out,
                  onSelected: (v) => setFilter(v ? StockFilter.out : StockFilter.all),
                ),
              ],
            ),
          ),
          Expanded(
            child: PagedListView<Item>(
              filterKey: (_q, _storeId, filter),
              fetch: (page) => repo.items(
                page: page,
                q: _q,
                storeId: _storeId,
                low: filter == StockFilter.low,
                outOfStock: filter == StockFilter.out,
              ),
              emptyIcon: filter == StockFilter.out ? Icons.check_circle_outline : Icons.inventory_2_outlined,
              emptyTitle: switch (filter) {
                StockFilter.low => 'No low-stock items',
                StockFilter.out => 'Nothing is out of stock',
                StockFilter.all => 'No items found',
              },
              emptyMessage: _q.isEmpty ? null : 'Try another search.',
              itemBuilder: (context, item) => Card(
                child: ItemTile(item: item, formatters: f, storeName: storeName),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
