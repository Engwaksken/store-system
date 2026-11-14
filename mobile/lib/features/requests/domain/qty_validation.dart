import 'package:decimal/decimal.dart';

/// Client-side validation of a quantity typed into the approve editor.
/// The server re-validates; this just gives instant feedback.
///
/// Returns null when valid, otherwise a short message.
String? validateApprovedQty(String? input, String? ceiling, {int maxDecimals = 3}) {
  final text = (input ?? '').trim();
  if (text.isEmpty) return 'Enter a quantity';
  if (!RegExp(r'^\d+(\.\d+)?$').hasMatch(text)) {
    return text.startsWith('-') ? 'Cannot be negative' : 'Enter a valid number';
  }
  final value = Decimal.parse(text);
  final dot = text.indexOf('.');
  if (dot >= 0) {
    final frac = text.substring(dot + 1).replaceFirst(RegExp(r'0+$'), '');
    if (frac.length > maxDecimals) return 'At most $maxDecimals decimals';
  }
  final max = Decimal.tryParse((ceiling ?? '0').trim()) ?? Decimal.zero;
  if (value > max) return 'Max ${_plain(max)}';
  return null;
}

/// Validation for a requested quantity on a new request (> 0).
String? validateRequestedQty(String? input, {int maxDecimals = 3}) {
  final text = (input ?? '').trim();
  if (text.isEmpty) return 'Enter a quantity';
  if (!RegExp(r'^\d+(\.\d+)?$').hasMatch(text)) return 'Enter a valid number';
  final dot = text.indexOf('.');
  if (dot >= 0) {
    final frac = text.substring(dot + 1).replaceFirst(RegExp(r'0+$'), '');
    if (frac.length > maxDecimals) return 'At most $maxDecimals decimals';
  }
  if (Decimal.parse(text) <= Decimal.zero) return 'Must be more than 0';
  return null;
}

/// Validation for a money amount (> 0, at most [decimals] decimals).
String? validateAmount(String? input, {int decimals = 2}) {
  final text = (input ?? '').trim().replaceAll(',', '');
  if (text.isEmpty) return 'Enter the amount';
  if (!RegExp(r'^\d+(\.\d+)?$').hasMatch(text)) return 'Enter a valid amount';
  final dot = text.indexOf('.');
  if (dot >= 0) {
    final frac = text.substring(dot + 1).replaceFirst(RegExp(r'0+$'), '');
    if (frac.length > decimals) {
      return decimals == 0 ? 'Whole numbers only' : 'At most $decimals decimals';
    }
  }
  if (Decimal.parse(text) <= Decimal.zero) return 'Must be more than 0';
  return null;
}

/// Canonical string for sending a valid decimal input (`"018.50"` -> `"18.5"`).
String normalizeDecimal(String input) {
  final d = Decimal.tryParse(input.trim().replaceAll(',', ''));
  return d == null ? input.trim() : _plain(d);
}

/// True when two decimal strings are numerically equal.
bool decimalEquals(String? a, String? b) {
  final da = Decimal.tryParse((a ?? '').trim());
  final db = Decimal.tryParse((b ?? '').trim());
  if (da == null || db == null) return false;
  return da == db;
}

String _plain(Decimal d) => d.toString();
