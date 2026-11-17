import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/features/stock/domain/stock_models.dart';

import '../helpers.dart';

void main() {
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
