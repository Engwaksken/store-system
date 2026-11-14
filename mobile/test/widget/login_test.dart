import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/app.dart';
import 'package:store_system/core/fake/fake_backend.dart';

import '../helpers.dart';

Future<void> _pumpApp(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(ProviderScope(
    retry: (_, _) => null,
    overrides: fakeOverrides(backend: FakeBackend()),
    child: const KemmyStoreApp(),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('no token -> login screen; empty submit shows validation errors', (tester) async {
    await _pumpApp(tester);

    expect(find.text('Sign in'), findsWidgets);
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pump();

    expect(find.text('Enter your e-mail'), findsOneWidget);
    expect(find.text('Enter your password'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('login-email')), 'not-an-email');
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pump();
    expect(find.text('Enter a valid e-mail address'), findsOneWidget);
  });

  testWidgets('wrong password shows the server 422 message on the e-mail field', (tester) async {
    await _pumpApp(tester);

    await tester.enterText(find.byKey(const Key('login-email')), 'brian@kemmy.test');
    await tester.enterText(find.byKey(const Key('login-password')), 'wrong-pass');
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('These credentials do not match our records.'), findsOneWidget);
  });

  testWidgets('valid login lands on the home dashboard with role-based tabs', (tester) async {
    await _pumpApp(tester);

    await tester.enterText(find.byKey(const Key('login-email')), 'brian@kemmy.test');
    await tester.enterText(find.byKey(const Key('login-password')), FakeBackend.password);
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('Hello, Brian'), findsOneWidget);
    expect(find.text('Waiting for my approval'), findsOneWidget);
    // PM sees the Approvals tab.
    expect(find.widgetWithText(NavigationDestination, 'Approvals'), findsOneWidget);
  });

  testWidgets('engineer has no Approvals tab', (tester) async {
    await _pumpApp(tester);

    await tester.enterText(find.byKey(const Key('login-email')), 'engineer@kemmy.test');
    await tester.enterText(find.byKey(const Key('login-password')), FakeBackend.password);
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('Hello, Jane'), findsOneWidget);
    expect(find.widgetWithText(NavigationDestination, 'Approvals'), findsNothing);
    expect(find.widgetWithText(NavigationDestination, 'Requests'), findsOneWidget);
  });

  testWidgets('must_change_password routes to the change-password screen', (tester) async {
    await _pumpApp(tester);

    await tester.enterText(find.byKey(const Key('login-email')), 'new@kemmy.test');
    await tester.enterText(find.byKey(const Key('login-password')), FakeBackend.password);
    await tester.ensureVisible(find.byKey(const Key('login-submit')));
    await tester.tap(find.byKey(const Key('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('Set a new password'), findsOneWidget);
  });
}
