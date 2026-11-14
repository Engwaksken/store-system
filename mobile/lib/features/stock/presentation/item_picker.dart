import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/paged_list_view.dart';
import '../../auth/data/auth_controller.dart';
import '../data/stock_repository.dart';
import '../domain/stock_models.dart';

/// Searchable item picker (GET /items?q=&active=1) in a bottom sheet.
Future<Item?> pickItem(BuildContext context) => showModalBottomSheet<Item>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => const FractionallySizedBox(heightFactor: 0.9, child: _ItemPicker()),
    );

class _ItemPicker extends ConsumerStatefulWidget {
  const _ItemPicker();

  @override
  ConsumerState<_ItemPicker> createState() => _ItemPickerState();
}

class _ItemPickerState extends ConsumerState<_ItemPicker> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(stockRepositoryProvider);
    final f = ref.watch(formattersProvider);
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              Expanded(child: Text('Choose an item', style: Theme.of(context).textTheme.titleMedium)),
              IconButton(
                tooltip: 'Close',
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.close),
              ),
            ],
          ),
        ),
        SearchField(hint: 'Search SKU or name', onChanged: (v) => setState(() => _q = v)),
        Expanded(
          child: PagedListView<Item>(
            filterKey: _q,
            padding: const EdgeInsets.fromLTRB(8, 4, 8, 24),
            separator: 0,
            fetch: (page) => repo.items(page: page, q: _q, activeOnly: true),
            emptyTitle: 'No items found',
            emptyIcon: Icons.search_off,
            itemBuilder: (context, item) => ListTile(
              title: Text(item.name),
              subtitle: Text('${item.sku} · ${f.qty(item.onHand)} ${item.unit} on hand'),
              trailing: item.low ? const Icon(Icons.warning_amber, color: Colors.orange, semanticLabel: 'Low stock') : null,
              onTap: () => Navigator.pop(context, item),
            ),
          ),
        ),
      ],
    );
  }
}
