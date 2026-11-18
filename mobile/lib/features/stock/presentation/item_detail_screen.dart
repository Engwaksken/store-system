import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/format/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/stock_repository.dart';
import '../domain/stock_models.dart';
import 'item_widgets.dart';

class ItemDetailScreen extends ConsumerWidget {
  const ItemDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(itemDetailProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text(async.value?.item.sku ?? 'Item details')),
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
    final state = StockState.of(item);
    final onBand = BrandHeader.foreground(context);
    final value = _stockValue(item);
    final total = Decimal.tryParse(item.onHand ?? '') ?? Decimal.zero;
    final hasMin = stockLevelRatio(item) != null;

    final note = switch (state) {
      StockState.out => 'Nothing on hand in any store. Raise a purchase or transfer to restock.',
      StockState.low => 'At or below the minimum level. Plan to restock soon.',
      StockState.inactive => 'This item is no longer in use and cannot be requested.',
      StockState.ok => hasMin ? 'Above the minimum level.' : 'No minimum level is set for this item.',
    };

    final facts = [
      _Fact(label: 'Unit', value: item.unit, icon: Icons.straighten),
      _Fact(label: 'Minimum level', value: '${f.qty(item.minLevel)} ${item.unit}', icon: Icons.low_priority),
      // avg_cost is only sent to users who may see stock value.
      if (item.avgCost != null) _Fact(label: 'Average cost', value: f.money(item.avgCost), icon: Icons.sell_outlined),
      if (item.avgCost != null && value != null)
        _Fact(label: 'Stock value', value: f.money(value), icon: Icons.account_balance_wallet_outlined),
    ];

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.zero,
      children: [
        BrandHeader(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(color: theme.colorScheme.surface, borderRadius: BorderRadius.circular(18)),
                child: ItemAvatar(state: state, size: 52),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.name,
                      style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700, color: onBand),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      [item.sku, if ((item.category ?? '').isNotEmpty) item.category!].join('  ·  '),
                      style: theme.textTheme.bodyMedium?.copyWith(color: onBand.withValues(alpha: 0.8)),
                    ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        StatusBadge(label: state.label, tone: state.tone),
                        if (state != StockState.inactive) StatusBadge.status(label: 'Active', statusTone: 'approved'),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          child: Column(
            children: [
              ResponsiveCenter(
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'On hand, all stores',
                          style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                        ),
                        const SizedBox(height: 2),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.baseline,
                          textBaseline: TextBaseline.alphabetic,
                          children: [
                            Text(
                              f.qty(item.onHand),
                              style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              item.unit,
                              style: theme.textTheme.titleMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                            ),
                          ],
                        ),
                        if (hasMin) ...[
                          const SizedBox(height: 12),
                          StockLevelBar(item: item, height: 8),
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              Text('0', style: theme.textTheme.labelSmall),
                              const Spacer(),
                              Text(
                                '2 × minimum (${f.qty((Decimal.parse(item.minLevel!) * Decimal.fromInt(2)).toString())})',
                                style: theme.textTheme.labelSmall,
                              ),
                            ],
                          ),
                        ],
                        const SizedBox(height: 12),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(state.icon, size: 18, color: ToneColors.of(state.tone, theme.brightness).foreground),
                            const SizedBox(width: 8),
                            Expanded(child: Text(note, style: theme.textTheme.bodySmall)),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              ResponsiveCenter(
                child: LayoutBuilder(
                  builder: (context, c) {
                    const gap = 10.0;
                    final w = (c.maxWidth - gap) / 2;
                    return Wrap(
                      spacing: gap,
                      runSpacing: gap,
                      children: [for (final t in facts) SizedBox(width: w, child: t)],
                    );
                  },
                ),
              ),
              const ResponsiveCenter(child: SectionTitle('Balance per store')),
              ResponsiveCenter(
                child: Card(
                  child: detail.balances.isEmpty
                      ? const ListTile(
                          leading: Icon(Icons.store_mall_directory_outlined),
                          title: Text('Not held in any store yet'),
                        )
                      : Column(
                          children: [
                            for (var i = 0; i < detail.balances.length; i++) ...[
                              if (i > 0) const Divider(height: 1),
                              _StoreRow(balance: detail.balances[i], unit: item.unit, total: total, formatters: f),
                            ],
                          ],
                        ),
                ),
              ),
            ],
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

/// One store's balance, with a bar showing its share of the total on hand.
class _StoreRow extends StatelessWidget {
  const _StoreRow({required this.balance, required this.unit, required this.total, required this.formatters});
  final StoreBalance balance;
  final String unit;
  final Decimal total;
  final Formatters formatters;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final qty = Decimal.tryParse(balance.onHand ?? '') ?? Decimal.zero;
    final share = total <= Decimal.zero ? 0.0 : (qty.toDouble() / total.toDouble()).clamp(0.0, 1.0);
    return Semantics(
      label: '${balance.store.name}: ${formatters.qty(balance.onHand)} $unit',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Row(
          children: [
            Icon(Icons.store_mall_directory_outlined, color: theme.colorScheme.onSurfaceVariant),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(balance.store.name, style: theme.textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600)),
                  Text(
                    balance.store.code,
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: 6),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(3),
                    child: LinearProgressIndicator(
                      value: share,
                      minHeight: 5,
                      backgroundColor: theme.colorScheme.surfaceContainerHighest,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 16),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  formatters.qty(balance.onHand),
                  style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
                ),
                Text(unit, style: theme.textTheme.bodySmall),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value, required this.icon});
  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Semantics(
      label: '$label: $value',
      excludeSemantics: true,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Icon(icon, size: 20, color: theme.colorScheme.primary),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Text(
                        value.trim().isEmpty ? Formatters.dash : value,
                        style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
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
