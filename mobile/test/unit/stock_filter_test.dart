import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/api_client.dart';
import 'package:store_system/core/api/json.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/features/stock/data/stock_repository.dart';
import 'package:store_system/features/stock/domain/stock_models.dart';

import '../helpers.dart';

/// A server from before `out=1` existed: it ignores the filter and sends no `out_of_stock`.
class _OldServer extends ApiClient {
  _OldServer(this.pages) : super(Dio());
  final List<List<Json>> pages;
  final requested = <int>[];

  @override
  Future<Json> get(String path, {Map<String, dynamic>? query, bool skipSession = false}) async {
    final page = query?['page'] as int? ?? 1;
    requested.add(page);
    return {
      'data': pages[page - 1],
      'meta': {'current_page': page, 'last_page': pages.length, 'per_page': 2, 'total': pages.fold(0, (n, p) => n + p.length)},
    };
  }
}

Json _item(int id, String onHand, {bool active = true}) =>
    {'id': id, 'sku': 'S$id', 'name': 'Item $id', 'unit': 'pc', 'on_hand': onHand, 'is_active': active};

void main() {
  test('without out_of_stock from the server, it is worked out from on_hand', () {
    expect(Item.fromJson(_item(1, '0.000')).outOfStock, isTrue);
    expect(Item.fromJson(_item(1, '-2.000')).outOfStock, isTrue);
    expect(Item.fromJson(_item(1, '3.500')).outOfStock, isFalse);
    expect(Item.fromJson(_item(1, '0.000', active: false)).outOfStock, isFalse);
    expect(Item.fromJson({..._item(1, '0.000'), 'out_of_stock': false}).outOfStock, isFalse);
  });

  test('old server: out-of-stock list is filtered in the app, skipping pages with no match', () async {
    final api = _OldServer([
      [_item(1, '5.000'), _item(2, '1.000')], // no match on page 1
      [_item(3, '0.000'), _item(4, '7.000')],
      [_item(5, '0.000', active: false), _item(6, '0.000')],
    ]);
    final repo = StockRepository(api);

    final first = await repo.items(outOfStock: true);
    expect(first.data.map((i) => i.id), [3]);
    expect(first.meta.currentPage, 2, reason: 'the next fetch continues after the pages already read');
    expect(first.meta.hasMore, isTrue);

    final next = await repo.items(page: first.meta.currentPage + 1, outOfStock: true);
    expect(next.data.map((i) => i.id), [6]);
    expect(next.meta.hasMore, isFalse);
    expect(api.requested, [1, 2, 3]);
  });
  test('out=1 lists only active items with nothing on hand, flagged out_of_stock', () {
    final b = FakeBackend();
    final auth = 'Bearer ${fakeLogin(b)}';
    final res = b.handle(method: 'GET', path: 'items', authorization: auth, query: {'out': '1', 'per_page': '50'});
    final items = [for (final j in (res.body as Map)['data'] as List) Item.fromJson(j as Map<String, dynamic>)];
    expect(items, isNotEmpty);
    for (final i in items) {
      expect(i.outOfStock, isTrue);
      expect(i.isActive, isTrue);
      expect(double.parse(i.onHand!), lessThanOrEqualTo(0));
    }

    final dashboard = b.handle(method: 'GET', path: 'dashboard', authorization: auth).body as Map;
    final card = (dashboard['cards'] as List).cast<Map>().firstWhere((c) => c['key'] == 'out_of_stock');
    expect(card['value'], items.length);
  });
}
