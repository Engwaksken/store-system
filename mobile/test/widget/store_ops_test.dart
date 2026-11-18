import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/token_storage.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/theme/app_theme.dart';
import 'package:store_system/features/auth/data/auth_controller.dart';
import 'package:store_system/features/operations/presentation/issue_screens.dart';
import 'package:store_system/features/operations/presentation/purchasing_screens.dart';
import 'package:store_system/features/operations/presentation/stock_ops_screens.dart';

import '../helpers.dart';

Future<(ProviderContainer, FakeBackend, String)> _signedIn(WidgetTester tester, {String email = 'brian@kemmy.test'}) async {
  final backend = FakeBackend();
  final token = fakeLogin(backend, email: email);
  final container = ProviderContainer(
    retry: (_, _) => null,
    overrides: fakeOverrides(backend: backend, tokens: MemoryTokenStorage(token)),
  );
  await tester.runAsync(() => container.read(authControllerProvider.notifier).restore());
  expect(container.read(authControllerProvider), isA<AuthSignedIn>());
  return (container, backend, 'Bearer $token');
}

Map<String, dynamic> _call(FakeBackend b, String auth, String method, String path, {Map<String, dynamic>? body, Map<String, dynamic> query = const {}}) {
  final res = b.handle(method: method, path: path, body: body, query: query, authorization: auth);
  expect(res.status, lessThan(300), reason: '$method $path -> ${res.status} ${res.body}');
  return (res.body as Map).cast<String, dynamic>();
}

Future<void> _pump(WidgetTester tester, ProviderContainer container, Widget home) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(UncontrolledProviderScope(
    container: container,
    child: MaterialApp(theme: AppTheme.light(), home: home),
  ));
  await tester.pumpAndSettle();
}

void main() {
  test('fake backend: a short issue raises a restock requisition the manager can approve', () {
    final b = FakeBackend();
    final auth = 'Bearer ${fakeLogin(b)}';
    final queue = _call(b, auth, 'GET', 'issues/queue')['data'] as List;
    expect(queue, isNotEmpty);
    final reqId = (queue.first as Map)['id'] as int;
    final form = _call(b, auth, 'GET', 'requests/$reqId/issue')['data'] as Map;
    final line = (form['lines'] as List).first as Map;

    // Empty the store for this item, then issue nothing but ask for restock.
    final itemId = (line['item'] as Map)['id'] as int;
    final onHand = line['on_hand'] as String;
    if (double.parse(onHand) > 0) {
      _call(b, auth, 'POST', 'adjustments', body: {'store_id': 1, 'item_id': itemId, 'qty_delta': '-$onHand', 'reason': 'Test: empty the shelf'});
    }
    final after = _call(b, auth, 'GET', 'requests/$reqId/issue')['data'] as Map;
    expect(after['shortfall'], isNotEmpty);

    final restock = _call(b, auth, 'POST', 'requests/$reqId/restock', body: {'store_id': 1});
    final prId = (restock['data'] as Map)['id'] as int;
    // Asking again does not duplicate the item.
    expect(_call(b, auth, 'POST', 'requests/$reqId/restock', body: {'store_id': 1})['data'], isNull);

    final pr = _call(b, auth, 'GET', 'purchase-requisitions/$prId')['data'] as Map;
    expect(pr['is_restock'], isTrue);
    expect((pr['can'] as Map)['decide'], isTrue);
    final decided = _call(b, auth, 'POST', 'purchase-requisitions/$prId/decision', body: {'decision': 'approved'})['data'] as Map;
    expect(decided['status'], 'approved');
  });

  test('fake backend: receipt, transfer and return round trip', () {
    final b = FakeBackend();
    final auth = 'Bearer ${fakeLogin(b)}';
    final rec = _call(b, auth, 'POST', 'receipts', body: {
      'store_id': 1,
      'received_on': '2026-11-18',
      'supplier_name': 'Hima',
      'lines': [
        {'item_id': 2, 'qty': '10', 'unit_cost': '30000'},
      ],
    })['data'] as Map;
    expect(rec['total_value'], '300000.00');

    final t = _call(b, auth, 'POST', 'transfers', body: {
      'from_store_id': 1,
      'to_store_id': 2,
      'lines': [
        {'item_id': 2, 'qty': '4'},
      ],
    })['data'] as Map;
    final lineId = ((t['lines'] as List).first as Map)['id'];
    final received = _call(b, auth, 'POST', 'transfers/${t['id']}/receive', body: {
      'received': {'$lineId': '4'},
    })['data'] as Map;
    expect(received['status'], 'received');
  });

  testWidgets('storekeeper issues an approved request and lands on the voucher', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);
    final queue = _call(backend, auth, 'GET', 'issues/queue')['data'] as List;
    final reqId = (queue.first as Map)['id'] as int;
    // Make sure there is stock for every line so the form can issue.
    final form = _call(backend, auth, 'GET', 'requests/$reqId/issue')['data'] as Map;
    for (final l in (form['lines'] as List).cast<Map>()) {
      _call(backend, auth, 'POST', 'adjustments',
          body: {'store_id': 1, 'item_id': (l['item'] as Map)['id'], 'qty_delta': '1000', 'reason': 'Test stock top-up'});
    }

    await _pump(tester, container, IssueFormScreen(requestId: reqId));
    expect(find.text('Issue & create voucher'), findsOneWidget);
    await tester.tap(find.text('Issue & create voucher'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Issue'));
    await tester.pumpAndSettle();

    final vouchers = _call(backend, auth, 'GET', 'issues')['data'] as List;
    expect(vouchers, hasLength(1));
    expect((vouchers.first as Map)['acknowledged'], isFalse);
  });

  testWidgets('voucher detail lets the storekeeper acknowledge for the recipient', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);
    final reqId = ((_call(backend, auth, 'GET', 'issues/queue')['data'] as List).first as Map)['id'] as int;
    final form = _call(backend, auth, 'GET', 'requests/$reqId/issue')['data'] as Map;
    final line = (form['lines'] as List).first as Map;
    _call(backend, auth, 'POST', 'adjustments',
        body: {'store_id': 1, 'item_id': (line['item'] as Map)['id'], 'qty_delta': '1000', 'reason': 'Test stock top-up'});
    final issue = _call(backend, auth, 'POST', 'requests/$reqId/issue', body: {
      'store_id': 1,
      'qty': {'${line['id']}': '1'},
      'recipient_name': 'Foreman',
      'idempotency_key': 'k1',
    })['data'] as Map;

    await _pump(tester, container, IssueDetailScreen(id: issue['id'] as int));
    expect(find.text('Awaiting acknowledgment'), findsWidgets);
    await tester.tap(find.text('Acknowledge receipt'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Acknowledge'));
    await tester.pumpAndSettle();
    expect(find.text('Acknowledged'), findsWidgets);
  });

  testWidgets('manager approves a purchase order from its detail screen', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);

    await _pump(tester, container, const PoDetailScreen(id: 1));
    expect(find.text('Awaiting approval'), findsWidgets);
    await tester.tap(find.widgetWithText(FilledButton, 'Approve'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Approve').last);
    await tester.pumpAndSettle();

    final po = _call(backend, auth, 'GET', 'purchase-orders/1')['data'] as Map;
    expect(po['status'], 'approved');
  });

  testWidgets('store hub lists every section for the demo manager', (tester) async {
    final (container, _, _) = await _signedIn(tester);
    addTearDown(container.dispose);
    await _pump(tester, container, const StoreHubScreen());
    for (final t in ['Issue materials', 'Stock receipts', 'Transfers', 'Returns', 'Purchasing']) {
      expect(find.text(t), findsOneWidget);
    }
  });

  testWidgets('engineer only sees issue vouchers in the store hub and no receipts button', (tester) async {
    final (container, _, _) = await _signedIn(tester, email: 'engineer@kemmy.test');
    addTearDown(container.dispose);
    await _pump(tester, container, const StoreHubScreen());
    expect(find.text('Issue vouchers'), findsOneWidget);
    expect(find.text('Stock receipts'), findsNothing);
    await _pump(tester, container, const ReceiptsScreen());
    expect(find.text('Receive stock'), findsNothing);
  });
}
