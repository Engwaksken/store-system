import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';

class Item {
  const Item({
    required this.id,
    required this.sku,
    required this.name,
    this.category,
    required this.unit,
    this.onHand,
    this.minLevel,
    required this.low,
    required this.isActive,
    this.avgCost,
  });

  final int id;
  final String sku;
  final String name;
  final String? category;
  final String unit;
  final String? onHand;
  final String? minLevel;
  final bool low;
  final bool isActive;

  /// Only present when the user may view stock value.
  final String? avgCost;

  factory Item.fromJson(Json json) => Item(
        id: asInt(json['id']),
        sku: asString(json['sku']),
        name: asString(json['name']),
        category: asStringOrNull(json['category']),
        unit: asString(json['unit']),
        onHand: asDecimalString(json['on_hand']),
        minLevel: asDecimalString(json['min_level']),
        low: asBool(json['low']),
        isActive: asBool(json['is_active'], true),
        avgCost: asDecimalString(json['avg_cost']),
      );
}

class StoreBalance {
  const StoreBalance({required this.store, this.onHand});
  final CodedRef store;
  final String? onHand;

  factory StoreBalance.fromJson(Json json) => StoreBalance(
        store: CodedRef.fromJson(asMap(json['store'])),
        onHand: asDecimalString(json['on_hand']),
      );
}

class ItemDetail {
  const ItemDetail({required this.item, required this.balances});
  final Item item;
  final List<StoreBalance> balances;

  factory ItemDetail.fromJson(Json json) => ItemDetail(
        item: Item.fromJson(json),
        balances: asList(json['balances'], StoreBalance.fromJson),
      );
}
