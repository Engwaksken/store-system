import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/api/refs.dart';
import '../../../core/util/data_revision.dart';
import '../../auth/data/auth_controller.dart';
import '../domain/stock_models.dart';

class StockRepository {
  StockRepository(this._api);
  final ApiClient _api;

  Future<Paginated<Item>> items({int page = 1, String? q, int? storeId, bool low = false, bool outOfStock = false, bool activeOnly = false, bool inStock = false}) async {
    Future<Paginated<Item>> fetch(int p) async => Paginated.fromJson(
          await _api.get('/items', query: {
            'page': p,
            'q': q,
            'store_id': storeId,
            if (outOfStock) 'per_page': 50,
            if (low) 'low': 1,
            if (outOfStock) 'out': 1,
            if (activeOnly) 'active': 1,
            if (inStock) 'in_stock': 1,
          }),
          Item.fromJson,
        );

    if (!outOfStock) return fetch(page);

    // The server filters with `out=1`; servers without that filter ignore it and return
    // every item, so the list is also filtered here. Pages with no match are skipped so the
    // list does not look empty while later pages still hold out-of-stock items.
    var current = page;
    while (true) {
      final res = await fetch(current);
      final matches = res.data.where((i) => i.outOfStock).toList();
      if (matches.isNotEmpty || !res.meta.hasMore || current - page >= 20) {
        return Paginated(
          data: matches,
          meta: PageMeta(
            currentPage: res.meta.currentPage,
            lastPage: res.meta.lastPage,
            perPage: res.meta.perPage,
            total: matches.length == res.data.length ? res.meta.total : matches.length,
          ),
        );
      }
      current = res.meta.currentPage + 1;
    }
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

/// First page of out-of-stock items for the Home screen. Reloads whenever data changes.
final outOfStockPreviewProvider = FutureProvider<Paginated<Item>>((ref) {
  ref.watch(currentUserProvider.select((u) => u?.id));
  ref.watch(dataRevisionProvider);
  return ref.watch(stockRepositoryProvider).items(outOfStock: true);
});

/// Which stock list the Stock tab shows. Set by the Home cards before switching tabs.
enum StockFilter { all, low, out }

class StockFilterNotifier extends Notifier<StockFilter> {
  @override
  StockFilter build() => StockFilter.all;

  void set(StockFilter f) => state = f;
}

final stockFilterProvider = NotifierProvider<StockFilterNotifier, StockFilter>(StockFilterNotifier.new);
