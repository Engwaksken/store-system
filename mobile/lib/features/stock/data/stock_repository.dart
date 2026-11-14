import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/api/refs.dart';
import '../../auth/data/auth_controller.dart';
import '../domain/stock_models.dart';

class StockRepository {
  StockRepository(this._api);
  final ApiClient _api;

  Future<Paginated<Item>> items({int page = 1, String? q, int? storeId, bool low = false, bool activeOnly = false}) async {
    final json = await _api.get('/items', query: {
      'page': page,
      'q': q,
      'store_id': storeId,
      if (low) 'low': 1,
      if (activeOnly) 'active': 1,
    });
    return Paginated.fromJson(json, Item.fromJson);
  }

  Future<ItemDetail> item(int id) async {
    final json = await _api.get('/items/$id');
    return ItemDetail.fromJson(asMap(json['data']));
  }

  Future<List<CodedRef>> stores() async {
    final json = await _api.get('/stores');
    return asList(json['data'], CodedRef.fromJson);
  }
}

final stockRepositoryProvider = Provider<StockRepository>((ref) => StockRepository(ref.watch(apiClientProvider)));

final storesProvider = FutureProvider<List<CodedRef>>((ref) {
  ref.watch(currentUserProvider.select((u) => u?.id));
  return ref.watch(stockRepositoryProvider).stores();
});

final itemDetailProvider = FutureProvider.autoDispose.family<ItemDetail, int>(
  (ref, id) => ref.watch(stockRepositoryProvider).item(id),
);
