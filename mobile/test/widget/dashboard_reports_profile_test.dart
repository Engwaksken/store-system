import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:store_system/app.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/widgets/charts.dart';
import 'package:store_system/core/widgets/collapsible.dart';
import 'package:store_system/features/stock/presentation/item_widgets.dart';

import '../helpers.dart';

Future<void> _signIn(WidgetTester tester, {String email = 'brian@kemmy.test'}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(ProviderScope(
    retry: (_, _) => null,
    overrides: fakeOverrides(backend: FakeBackend()),
    child: const StoreSystemApp(),
  ));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const Key('login-email')), email);
  await tester.enterText(find.byKey(const Key('login-password')), FakeBackend.password);
  await tester.ensureVisible(find.byKey(const Key('login-submit')));
  await tester.tap(find.byKey(const Key('login-submit')));
  await tester.pumpAndSettle();
}

void _go(WidgetTester tester, String location) =>
    GoRouter.of(tester.element(find.byType(Scaffold).first)).push(location);

void main() {
  testWidgets('home shows out-of-stock items and overview charts', (tester) async {
    await _signIn(tester);

    expect(find.text('Out of stock'), findsWidgets);
    expect(find.byType(ItemTile), findsWidgets);
    await tester.scrollUntilVisible(find.text('Overview'), 300, scrollable: find.byType(Scrollable).first);
    // Charts start folded to a row of figures; the chevron opens them.
    expect(find.text('Stock status'), findsOneWidget);
    expect(find.byType(ChartDigest), findsNWidgets(2));
    expect(find.byType(DonutChart), findsNothing);
    final stockCard = find.ancestor(of: find.text('Stock status'), matching: find.byType(CollapsibleCard));
    await tester.tap(find.descendant(of: stockCard, matching: find.byType(ExpandChevron)));
    await tester.pumpAndSettle();
    expect(find.byType(DonutChart), findsOneWidget);
    expect(find.byType(ChartDigest), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('reports screen renders every chart and switches period', (tester) async {
    await _signIn(tester);
    _go(tester, '/reports');
    await tester.pumpAndSettle();

    expect(find.text('Reports'), findsWidgets);
    expect(find.text('Stock status'), findsOneWidget);
    await tester.tap(find.text('12 months'));
    await tester.pumpAndSettle();
    expect(find.byType(ColumnChart), findsNothing);
    await tester.tap(find.text('Expand all'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.byType(ColumnChart), 300, scrollable: find.byType(Scrollable).last);
    expect(find.byType(ColumnChart), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('user edits their own name from the profile', (tester) async {
    await _signIn(tester);
    _go(tester, '/profile');
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Edit profile'));
    await tester.pumpAndSettle();

    final name = find.widgetWithText(TextFormField, 'Full name');
    await tester.enterText(name, 'Brian Okello Jr');
    // Changing the e-mail reveals the current-password field.
    expect(find.widgetWithText(TextFormField, 'Current password'), findsNothing);
    await tester.enterText(find.widgetWithText(TextFormField, 'E-mail'), 'brian.jr@example.com');
    await tester.pump();
    expect(find.widgetWithText(TextFormField, 'Current password'), findsOneWidget);
    await tester.enterText(find.widgetWithText(TextFormField, 'E-mail'), 'brian@kemmy.test');
    await tester.pump();

    await tester.ensureVisible(find.text('Save changes'));
    await tester.tap(find.text('Save changes'));
    await tester.pumpAndSettle();
    expect(find.text('Profile updated'), findsOneWidget);
    expect(find.text('Brian Okello Jr'), findsWidgets);
  });

  testWidgets('item detail shows stock level and store balances', (tester) async {
    await _signIn(tester);
    _go(tester, '/item/1');
    await tester.pumpAndSettle();

    expect(find.text('On hand, all stores'), findsOneWidget);
    expect(find.text('Balance per store'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
