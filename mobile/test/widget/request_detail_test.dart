import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/token_storage.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/theme/app_theme.dart';
import 'package:store_system/features/auth/data/auth_controller.dart';
import 'package:store_system/features/requests/presentation/request_detail_screen.dart';

import '../helpers.dart';

/// Signs in as [email] against a fresh fake backend and returns a container
/// whose auth state is already restored.
Future<(ProviderContainer, FakeBackend, String)> _signedIn(WidgetTester tester, {String email = 'brian@kemmy.test'}) async {
  final backend = FakeBackend();
  final token = fakeLogin(backend, email: email);
  final container = ProviderContainer(
    retry: (_, _) => null,
    overrides: fakeOverrides(backend: backend, tokens: MemoryTokenStorage(token)),
  );
  // Real async: nothing pumps the fake clock before the first pumpWidget.
  await tester.runAsync(() => container.read(authControllerProvider.notifier).restore());
  expect(container.read(authControllerProvider), isA<AuthSignedIn>());
  return (container, backend, 'Bearer $token');
}

int _firstId(FakeBackend b, String auth, String path, [Map<String, dynamic> query = const {}]) {
  final res = b.handle(method: 'GET', path: path, query: query, authorization: auth);
  return (((res.body as Map)['data'] as List).first as Map)['id'] as int;
}

Future<void> _pumpDetail(WidgetTester tester, ProviderContainer container, int id) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(UncontrolledProviderScope(
    container: container,
    child: MaterialApp(theme: AppTheme.light(), home: RequestDetailScreen(id: id)),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('shows Approve/Reject when can.decide is true', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);
    final id = _firstId(backend, auth, 'approvals');

    await _pumpDetail(tester, container, id);

    expect(find.byKey(const Key('request-approve')), findsOneWidget);
    expect(find.byKey(const Key('request-reject')), findsOneWidget);
    expect(find.textContaining('Items ('), findsOneWidget);
    expect(find.text('Version 1').evaluate().length + find.text('Version 2').evaluate().length, 1);
  });

  testWidgets('hides Approve when can.decide is false', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);
    final id = _firstId(backend, auth, 'requests', {'status': 'fully_issued'});

    await _pumpDetail(tester, container, id);

    expect(find.text('Issued'), findsWidgets); // status badge
    expect(find.byKey(const Key('request-approve')), findsNothing);
    expect(find.byKey(const Key('request-reject')), findsNothing);
  });

  testWidgets('engineer never sees Approve on a pending request', (tester) async {
    final (container, backend, auth) = await _signedIn(tester, email: 'engineer@kemmy.test');
    addTearDown(container.dispose);
    final id = _firstId(backend, auth, 'requests', {'status': 'submitted'});

    await _pumpDetail(tester, container, id);

    expect(find.byKey(const Key('request-approve')), findsNothing);
    // Own request still with the PM: may cancel.
    expect(find.byKey(const Key('request-cancel')), findsOneWidget);
  });

  testWidgets('approve editor validates against the ceiling, then approves', (tester) async {
    final (container, backend, auth) = await _signedIn(tester);
    addTearDown(container.dispose);
    final id = _firstId(backend, auth, 'approvals');

    await _pumpDetail(tester, container, id);
    await tester.tap(find.byKey(const Key('request-approve')));
    await tester.pumpAndSettle();

    // Editor opened with each line prefilled at its ceiling.
    expect(find.byKey(const Key('approve-confirm')), findsOneWidget);
    final field = find.byType(TextFormField).first;
    await tester.enterText(field, '999999');
    await tester.tap(find.byKey(const Key('approve-confirm')));
    await tester.pumpAndSettle();
    expect(find.text('Max 999999'), findsNothing);
    expect(find.byKey(const Key('approve-confirm')), findsOneWidget); // still on the editor

    await tester.enterText(field, '1');
    await tester.tap(find.byKey(const Key('approve-confirm')));
    await tester.pumpAndSettle();

    // Back on the detail, which reloaded: now with the PM's approval recorded.
    // (The demo PM may also give the final approval, so Approve can reappear.)
    expect(find.byKey(const Key('approve-confirm')), findsNothing);
    expect(find.textContaining(RegExp(r'^MR-.* approved$')), findsOneWidget); // snackbar
    final status = (backend.handle(method: 'GET', path: 'requests/$id', authorization: auth).body as Map)['data'] as Map;
    expect(status['status'], 'pm_approved');
  });
}
