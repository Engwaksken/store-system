import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/paginated.dart';
import 'package:store_system/core/widgets/collapsible.dart';
import 'package:store_system/core/widgets/paged_list_view.dart';

/// 45 rows, 20 per page: pages 1–3.
Future<Paginated<int>> _fetch(int page) async {
  const total = 45, per = 20;
  final start = (page - 1) * per;
  final rows = [for (var i = start; i < total && i < start + per; i++) i + 1];
  return Paginated(
    data: rows,
    meta: PageMeta(currentPage: page, lastPage: 3, perPage: per, total: total),
  );
}

Widget _app() => MaterialApp(
  home: Scaffold(
    body: PagedListView<int>(
      fetch: _fetch,
      expandAllToggle: true,
      itemBuilder: (context, n) => CollapsibleCard(
        header: Text('Row $n'),
        body: Text('Details $n'),
      ),
    ),
  ),
);

void main() {
  testWidgets('pages through results with the pager', (tester) async {
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();

    expect(find.text('1–20 of 45'), findsOneWidget);
    expect(find.text('Row 1'), findsOneWidget);
    expect(find.byType(Pager), findsOneWidget);

    await tester.tap(find.byTooltip('Next page'));
    await tester.pumpAndSettle();
    expect(find.text('21–40 of 45'), findsOneWidget);
    expect(find.text('Row 21'), findsOneWidget);
    expect(find.text('Row 1'), findsNothing);

    await tester.tap(find.byTooltip('Last page (3)'));
    await tester.pumpAndSettle();
    expect(find.text('41–45 of 45'), findsOneWidget);
    expect(find.text('Row 45'), findsOneWidget);

    await tester.tap(find.text('1'));
    await tester.pumpAndSettle();
    expect(find.text('Row 1'), findsOneWidget);
  });

  testWidgets('cards open singly and with expand / collapse all', (tester) async {
    await tester.pumpWidget(_app());
    await tester.pumpAndSettle();

    expect(find.text('Details 1'), findsNothing);
    await tester.tap(find.byType(ExpandChevron).first);
    await tester.pumpAndSettle();
    expect(find.text('Details 1'), findsOneWidget);
    expect(find.text('Details 2'), findsNothing);

    await tester.tap(find.text('Expand all'));
    await tester.pumpAndSettle();
    expect(find.text('Details 2'), findsOneWidget);

    await tester.tap(find.text('Collapse all'));
    await tester.pumpAndSettle();
    expect(find.text('Details 1'), findsNothing);
    expect(find.text('Details 2'), findsNothing);
  });
}
