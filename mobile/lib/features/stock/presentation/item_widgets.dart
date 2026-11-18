import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/format/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/common.dart';
import '../domain/stock_models.dart';

/// Stock state of an item, with the tone, label and icon used everywhere items are shown.
enum StockState {
  out('Out of stock', Tone.rejected, Icons.remove_shopping_cart_outlined),
  low('Low stock', Tone.pending, Icons.warning_amber_rounded),
  ok('In stock', Tone.approved, Icons.inventory_2_outlined),
  inactive('Inactive', Tone.muted, Icons.block);

  const StockState(this.label, this.tone, this.icon);
  final String label;
  final Tone tone;
  final IconData icon;

  static StockState of(Item item) {
    if (!item.isActive) return StockState.inactive;
    if (item.outOfStock) return StockState.out;
    if (item.low) return StockState.low;
    return StockState.ok;
  }
}

/// Rounded square with the item's state icon on its tone colour.
class ItemAvatar extends StatelessWidget {
  const ItemAvatar({super.key, required this.state, this.size = 44});
  final StockState state;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = ToneColors.of(state.tone, Theme.of(context).brightness);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: c.background, borderRadius: BorderRadius.circular(size * 0.28)),
      child: Icon(state.icon, color: c.foreground, size: size * 0.5),
    );
  }
}

/// How full the item is against its minimum level: the bar is full at twice the minimum.
/// Returns null when the item has no minimum level.
double? stockLevelRatio(Item item) {
  final min = Decimal.tryParse(item.minLevel ?? '');
  final onHand = Decimal.tryParse(item.onHand ?? '') ?? Decimal.zero;
  if (min == null || min <= Decimal.zero) return null;
  final ratio = onHand.toDouble() / (min.toDouble() * 2);
  return ratio.clamp(0.0, 1.0);
}

class StockLevelBar extends StatelessWidget {
  const StockLevelBar({super.key, required this.item, this.height = 6});
  final Item item;
  final double height;

  @override
  Widget build(BuildContext context) {
    final ratio = stockLevelRatio(item);
    if (ratio == null) return const SizedBox.shrink();
    final theme = Theme.of(context);
    final c = ToneColors.of(StockState.of(item).tone, theme.brightness);
    return ClipRRect(
      borderRadius: BorderRadius.circular(height / 2),
      child: LinearProgressIndicator(
        value: ratio == 0 ? 0 : ratio.clamp(0.04, 1.0),
        minHeight: height,
        color: c.foreground,
        backgroundColor: theme.colorScheme.surfaceContainerHighest,
      ),
    );
  }
}

/// Card row for an item: state icon, name, SKU and category, stock bar and on-hand quantity.
class ItemTile extends StatelessWidget {
  const ItemTile({super.key, required this.item, required this.formatters, this.storeName, this.dense = false});
  final Item item;
  final Formatters formatters;
  final String? storeName;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final f = formatters;
    final state = StockState.of(item);
    final stateColor = ToneColors.of(state.tone, theme.brightness).foreground;
    final hasMin = stockLevelRatio(item) != null;
    final meta = [item.sku, if ((item.category ?? '').isNotEmpty) item.category!].join('  ·  ');

    return Semantics(
      button: true,
      label:
          '${item.name}, ${item.sku}, ${state.label}, on hand ${f.qty(item.onHand)} ${item.unit}'
          '${storeName == null ? '' : ' in $storeName'}',
      excludeSemantics: true,
      child: InkWell(
        onTap: () => context.push('/item/${item.id}'),
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 14, vertical: dense ? 10 : 14),
          child: Row(
            children: [
              ItemAvatar(state: state, size: dense ? 38 : 44),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, height: 1.25),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      meta,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    ),
                    if (!dense && (hasMin || state != StockState.ok)) ...[
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          if (state != StockState.ok) ...[
                            StatusBadge(label: state.label, tone: state.tone, dense: true),
                            const SizedBox(width: 8),
                          ],
                          if (hasMin)
                            Expanded(
                              child: Row(
                                children: [
                                  Expanded(child: StockLevelBar(item: item, height: 5)),
                                  const SizedBox(width: 6),
                                  Text(
                                    'min ${f.qty(item.minLevel)}',
                                    style: theme.textTheme.labelSmall?.copyWith(
                                      color: theme.colorScheme.onSurfaceVariant,
                                    ),
                                  ),
                                ],
                              ),
                            )
                          else
                            const Spacer(),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 12),
              ConstrainedBox(
                constraints: const BoxConstraints(minWidth: 56),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      f.qty(item.onHand),
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: state == StockState.out || state == StockState.low ? stateColor : null,
                      ),
                    ),
                    Text(
                      item.unit,
                      style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
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
