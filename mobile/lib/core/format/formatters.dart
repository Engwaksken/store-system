import 'package:decimal/decimal.dart';
import 'package:intl/intl.dart';

/// Display formatting for API values. Money and quantities arrive as decimal
/// strings and are formatted without ever passing through a `double`:
/// rounding happens on [Decimal], grouping on the (exact) integer part via intl.
class Formatters {
  Formatters({this.currency = '', this.currencyDecimals = 0, String locale = 'en_US'})
      : _int = NumberFormat.decimalPattern(locale),
        _date = DateFormat('d MMM yyyy', locale),
        _dateTime = DateFormat('d MMM yyyy, HH:mm', locale),
        _decimalSep = NumberFormat.decimalPattern(locale).symbols.DECIMAL_SEP;

  final String currency;
  final int currencyDecimals;
  final NumberFormat _int;
  final DateFormat _date;
  final DateFormat _dateTime;
  final String _decimalSep;

  static const String dash = '—';

  /// `"125000.00"` -> `"UGX 125,000"` (with `currency_decimals` = 0).
  String money(String? raw, {bool withCurrency = true}) {
    if (raw == null || raw.trim().isEmpty) return dash;
    final formatted = decimal(raw, fixedDecimals: currencyDecimals);
    if (!withCurrency || currency.isEmpty) return formatted;
    return '$currency $formatted';
  }

  /// `"12.500"` -> `"12.5"`, `"20.000"` -> `"20"`, `"1500.000"` -> `"1,500"`.
  String qty(String? raw) {
    if (raw == null || raw.trim().isEmpty) return dash;
    return decimal(raw, maxDecimals: 3);
  }

  /// Formats a decimal string. With [fixedDecimals], always shows exactly that
  /// many digits (half-up rounding). Otherwise rounds to [maxDecimals] and
  /// drops trailing zeros. Returns the raw input when it is not a number.
  String decimal(String raw, {int? fixedDecimals, int maxDecimals = 3}) {
    final d = Decimal.tryParse(raw.trim());
    if (d == null) return raw;
    final String plain;
    if (fixedDecimals != null) {
      plain = d.round(scale: fixedDecimals).toStringAsFixed(fixedDecimals);
    } else {
      plain = _trimZeros(d.round(scale: maxDecimals).toStringAsFixed(maxDecimals));
    }
    return _group(plain);
  }

  String _group(String plain) {
    var s = plain;
    var negative = false;
    if (s.startsWith('-')) {
      negative = true;
      s = s.substring(1);
    }
    final dot = s.indexOf('.');
    final intPart = dot < 0 ? s : s.substring(0, dot);
    final frac = dot < 0 ? '' : s.substring(dot + 1);
    final asInt = int.tryParse(intPart);
    final groupedInt = asInt != null ? _int.format(asInt) : intPart;
    final isZero = asInt == 0 && frac.replaceAll('0', '').isEmpty;
    final sign = negative && !isZero ? '-' : '';
    return frac.isEmpty ? '$sign$groupedInt' : '$sign$groupedInt$_decimalSep$frac';
  }

  static String _trimZeros(String s) {
    if (!s.contains('.')) return s;
    var out = s.replaceFirst(RegExp(r'0+$'), '');
    if (out.endsWith('.')) out = out.substring(0, out.length - 1);
    return out;
  }

  /// Integer counts (dashboard values etc.).
  String count(num value) => _int.format(value);

  /// `"2026-11-20"` -> `"20 Nov 2026"`.
  String date(String? ymd) {
    if (ymd == null || ymd.isEmpty) return dash;
    final d = DateTime.tryParse(ymd);
    return d == null ? ymd : _date.format(d);
  }

  /// ISO 8601 with offset -> local `"14 Nov 2026, 09:30"`.
  String dateTime(String? iso) {
    if (iso == null || iso.isEmpty) return dash;
    final d = DateTime.tryParse(iso);
    return d == null ? iso : _dateTime.format(d.toLocal());
  }

  /// `"just now"`, `"5 min ago"`, `"3 h ago"`, `"2 d ago"`, then a date.
  String relative(String? iso, {DateTime? now}) {
    if (iso == null || iso.isEmpty) return dash;
    final d = DateTime.tryParse(iso);
    if (d == null) return iso;
    final diff = (now ?? DateTime.now()).difference(d);
    if (diff.inSeconds < 60) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes} min ago';
    if (diff.inHours < 24) return '${diff.inHours} h ago';
    if (diff.inDays < 7) return '${diff.inDays} d ago';
    return _date.format(d.toLocal());
  }

  static String ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}
