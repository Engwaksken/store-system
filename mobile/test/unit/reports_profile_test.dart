import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/format/formatters.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/features/reports/domain/report_models.dart';

import '../helpers.dart';

void main() {
  group('report models', () {
    test('parse charts, KPIs and values given as numbers or decimal strings', () {
      final s = ReportSummary.fromJson({
        'period': {'days': 90, 'label': 'Last 90 days'},
        'kpis': [
          {'key': 'stock_value', 'label': 'Stock value', 'value': '1250000.50', 'format': 'money', 'tone': 'navy'},
        ],
        'charts': [
          {
            'key': 'stock_status',
            'title': 'Stock status',
            'type': 'donut',
            'format': 'count',
            'series': [
              {'label': 'In stock', 'value': 7, 'tone': 'ok'},
              {'label': 'Out of stock', 'value': 3, 'tone': 'bad'},
            ],
          },
          {
            'key': 'x',
            'type': 'something-new',
            'series': [
              {'label': 'A', 'value': '0.000'},
            ],
          },
        ],
      });

      expect(s.days, 90);
      expect(s.chart('stock_status')!.type, ChartType.donut);
      expect(s.chart('stock_status')!.total, 10);
      expect(s.chart('x')!.type, ChartType.bar, reason: 'unknown types fall back to bars');
      expect(s.chart('x')!.isEmpty, isTrue);

      final f = Formatters(currency: 'UGX');
      expect(s.kpis.single.format.format(f, s.kpis.single.value), 'UGX 1,250,001');
      expect(ValueFormat.money.formatShort(f, 2500000), 'UGX 2.5M');
      expect(ValueFormat.count.formatShort(f, 42), '42');
    });
  });

  group('fake backend', () {
    test('reports: managers see issue charts and stock value, engineers do not', () {
      final backend = FakeBackend();
      final pm = backend.handle(
        method: 'GET',
        path: 'reports',
        query: {'days': 30},
        authorization: 'Bearer ${fakeLogin(backend)}',
      );
      final pmSummary = ReportSummary.fromJson((pm.body as Map).cast());
      expect(pmSummary.chart('stock_status'), isNotNull);
      expect(pmSummary.chart('top_issued_items'), isNotNull);
      expect(pmSummary.chart('requests_over_time')!.series.length, inInclusiveRange(4, 6));

      final eng = backend.handle(
        method: 'GET',
        path: 'reports',
        query: {'days': 7},
        authorization: 'Bearer ${fakeLogin(backend, email: 'jane.engineer@kemmy.test')}',
      );
      final engSummary = ReportSummary.fromJson((eng.body as Map).cast());
      expect(engSummary.chart('top_issued_items'), isNull);
      expect(engSummary.kpis.where((k) => k.key == 'stock_value'), isEmpty);
      expect(engSummary.chart('requests_over_time')!.series.length, 7);
    });

    test('profile update: name and phone, e-mail change needs the password', () {
      final backend = FakeBackend();
      final auth = 'Bearer ${fakeLogin(backend)}';
      Map put(Map<String, dynamic> body) =>
          backend.handle(method: 'PUT', path: 'me', body: body, authorization: auth).body as Map;

      final ok = put({'name': 'Brian O.', 'email': 'brian@kemmy.test', 'phone': ''});
      expect(ok['user']['name'], 'Brian O.');
      expect(ok['user']['phone'], isNull);

      expect(put({'name': 'Brian O.', 'email': 'brian.o@example.com'})['errors'], contains('current_password'));
      expect(put({'name': '', 'email': 'brian@kemmy.test'})['errors'], contains('name'));
      final moved = put({'name': 'Brian O.', 'email': 'Brian.O@Example.com', 'current_password': FakeBackend.password});
      expect(moved['user']['email'], 'brian.o@example.com');
    });
  });
}
