/// Tolerant JSON readers. The API contract is strict, but a mobile client must
/// not crash on a number sent as a string (or vice versa).
library;

typedef Json = Map<String, dynamic>;

int asInt(Object? v, [int fallback = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v) ?? fallback;
  return fallback;
}

int? asIntOrNull(Object? v) {
  if (v == null) return null;
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) return int.tryParse(v);
  return null;
}

String asString(Object? v, [String fallback = '']) {
  if (v == null) return fallback;
  return v is String ? v : '$v';
}

String? asStringOrNull(Object? v) {
  if (v == null) return null;
  final s = v is String ? v : '$v';
  return s;
}

bool asBool(Object? v, [bool fallback = false]) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  if (v is String) return v == 'true' || v == '1';
  return fallback;
}

/// Decimal amounts / quantities stay strings end to end. A numeric value is
/// converted with `toString()` so no float arithmetic ever happens in the app.
String? asDecimalString(Object? v) {
  if (v == null) return null;
  if (v is String) return v.trim().isEmpty ? null : v.trim();
  if (v is num) return v.toString();
  return null;
}

DateTime? asDateTimeOrNull(Object? v) {
  if (v is! String || v.isEmpty) return null;
  return DateTime.tryParse(v);
}

Json asMap(Object? v) => v is Map ? v.cast<String, dynamic>() : <String, dynamic>{};

Json? asMapOrNull(Object? v) => v is Map ? v.cast<String, dynamic>() : null;

List<T> asList<T>(Object? v, T Function(Json json) parse) {
  if (v is! List) return const [];
  return [for (final e in v) if (e is Map) parse(e.cast<String, dynamic>())];
}
