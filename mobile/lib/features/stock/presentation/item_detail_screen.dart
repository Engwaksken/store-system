import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/format/formatters.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/stock_repository.dart';
import '../domain/stock_models.dart';

class ItemDetailScreen extends ConsumerWidget {
  const ItemDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(itemDetailProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text(async.value?.item.sku ?? 'Item')),
      body: async.when(
        skipLoadingOnRefresh: true,
        loading: () => const SkeletonList(count: 3),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(itemDetailProvider(id))),
        data: (d) => RefreshIndicator(
          onRefresh: () => ref.refresh(itemDetailProvider(id).future),
          child: _ItemBody(detail: d),
        ),
      ),
    );
  }
}

class _ItemBody extends ConsumerWidget {
  const _ItemBody({required this.detail});
  final ItemDetail detail;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final item = detail.item;
    final theme = Theme.of(context);
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        ResponsiveCenter(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(item.name, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(
                    [item.sku, if ((item.category ?? '').isNotEmpty) item.category!].join(' · '),
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: 8),
                  Wrap(spacing: 6, children: [
                    if (item.low) StatusBadge.status(label: 'Low stock', statusTone: 'rejected'),
                    StatusBadge.status(
                      label: item.isActive ? 'Active' : 'Inactive',
                      statusTone: item.isActive ? 'approved' : 'muted',
                    ),
                  ]),
                  const Divider(height: 28),
                  Wrap(
                    spacing: 24,
                    runSpacing: 14,
                    children: [
                      _Big(label: 'On hand (all stores)', value: '${f.qty(item.onHand)} ${item.unit}'),
                      _Big(label: 'Minimum level', value: '${f.qty(item.minLevel)} ${item.unit}'),
                      // avg_cost is only sent to users who may see stock value.
                      if (item.avgCost != null) ...[
                        _Big(label: 'Average cost', value: f.money(item.avgCost)),
                        if (_stockValue(item) != null) _Big(label: 'Stock value', value: f.money(_stockValue(item))),
                      ],
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
        const ResponsiveCenter(child: SectionTitle('Balance per store')),
        ResponsiveCenter(
          child: Card(
            child: detail.balances.isEmpty
                ? const ListTile(title: Text('No store balances'))
                : Column(
                    children: [
                      for (var i = 0; i < detail.balances.length; i++) ...[
                        if (i > 0) const Divider(height: 1),
                        ListTile(
                          leading: const Icon(Icons.store_mall_directory_outlined),
                          title: Text(detail.balances[i].store.name),
                          subtitle: Text(detail.balances[i].store.code),
                          trailing: Text(
                            '${f.qty(detail.balances[i].onHand)} ${item.unit}',
                            style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                          ),
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

/// on_hand x avg_cost, computed exactly with [Decimal].
String? _stockValue(Item item) {
  final q = Decimal.tryParse(item.onHand ?? '');
  final c = Decimal.tryParse(item.avgCost ?? '');
  if (q == null || c == null) return null;
  return (q * c).toString();
}

class _Big extends StatelessWidget {
  const _Big({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Semantics(
      label: '$label: $value',
      excludeSemantics: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
          Text(value.trim().isEmpty ? Formatters.dash : value,
              style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}
