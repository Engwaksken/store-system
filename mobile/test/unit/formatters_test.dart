import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/format/formatters.dart';

void main() {
  group('money', () {
    test('UGX with 0 decimals', () {
      final f = Formatters(currency: 'UGX', currencyDecimals: 0);
      expect(f.money('125000.00'), 'UGX 125,000');
      expect(f.money('45000'), 'UGX 45,000');
      expect(f.money('999.50'), 'UGX 1,000'); // half-up
      expect(f.money('999.49'), 'UGX 999');
      expect(f.money('0.00'), 'UGX 0');
      expect(f.money('125000.00', withCurrency: false), '125,000');
    });

    test('2 decimals', () {
      final f = Formatters(currency: 'USD', currencyDecimals: 2);
      expect(f.money('1234.5'), 'USD 1,234.50');
      expect(f.money('0.005'), 'USD 0.01');
      expect(f.money('-15.25'), 'USD -15.25');
    });

    test('exact for values a double cannot hold', () {
      final f = Formatters(currency: 'UGX', currencyDecimals: 2);
      // 17 significant digits: a double would print ...901234567.9 or worse.
      expect(f.money('12345678901234567.89'), 'UGX 12,345,678,901,234,567.89');
      expect(f.money('0.10'), 'UGX 0.10');
    });

    test('null, empty and garbage', () {
      final f = Formatters(currency: 'UGX');
      expect(f.money(null), Formatters.dash);
      expect(f.money(''), Formatters.dash);
      expect(f.money('n/a'), 'UGX n/a');
    });
  });

  group('qty', () {
    final f = Formatters();
    test('trims trailing zeros and groups', () {
      expect(f.qty('20.000'), '20');
      expect(f.qty('12.500'), '12.5');
      expect(f.qty('0.125'), '0.125');
      expect(f.qty('1500.000'), '1,500');
      expect(f.qty('0.000'), '0');
      expect(f.qty('-0.000'), '0');
    });

    test('rounds beyond 3 decimals', () {
      expect(f.qty('1.23456'), '1.235');
    });

    test('null', () {
      expect(f.qty(null), Formatters.dash);
    });
  });

  group('dates', () {
    final f = Formatters();
    test('date', () {
      expect(f.date('2026-11-20'), '20 Nov 2026');
      expect(f.date(null), Formatters.dash);
      expect(f.date('garbage'), 'garbage');
    });

    test('dateTime converts to local time', () {
      final out = f.dateTime('2026-11-14T09:30:00+03:00');
      final local = DateTime.parse('2026-11-14T09:30:00+03:00').toLocal();
      expect(out, contains('Nov 2026'));
      expect(out, contains('${local.hour.toString().padLeft(2, '0')}:30'));
    });

    test('relative', () {
      final now = DateTime.parse('2026-11-14T12:00:00Z');
      expect(f.relative('2026-11-14T11:59:30Z', now: now), 'just now');
      expect(f.relative('2026-11-14T11:30:00Z', now: now), '30 min ago');
      expect(f.relative('2026-11-14T09:00:00Z', now: now), '3 h ago');
      expect(f.relative('2026-11-12T12:00:00Z', now: now), '2 d ago');
    });

    test('ymd', () {
      expect(Formatters.ymd(DateTime(2026, 3, 7)), '2026-03-07');
    });
  });
}
