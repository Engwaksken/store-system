import 'json.dart';

/// `{ "id", "code", "name" }` — projects, stores, cost codes.
class CodedRef {
  const CodedRef({required this.id, required this.code, required this.name});
  final int id;
  final String code;
  final String name;

  String get label => code.isEmpty ? name : '$code · $name';

  factory CodedRef.fromJson(Json json) => CodedRef(
        id: asInt(json['id']),
        code: asString(json['code']),
        name: asString(json['name']),
      );

  static CodedRef? maybe(Object? v) {
    final m = asMapOrNull(v);
    return m == null ? null : CodedRef.fromJson(m);
  }

  Json toJson() => {'id': id, 'code': code, 'name': name};

  @override
  bool operator ==(Object other) => other is CodedRef && other.id == id;
  @override
  int get hashCode => id.hashCode;
}

/// `{ "id", "name" }` — people, claim categories.
class NamedRef {
  const NamedRef({required this.id, required this.name});
  final int id;
  final String name;

  factory NamedRef.fromJson(Json json) => NamedRef(
        id: asInt(json['id']),
        name: asString(json['name']),
      );

  static NamedRef? maybe(Object? v) {
    final m = asMapOrNull(v);
    return m == null ? null : NamedRef.fromJson(m);
  }

  Json toJson() => {'id': id, 'name': name};

  @override
  bool operator ==(Object other) => other is NamedRef && other.id == id;
  @override
  int get hashCode => id.hashCode;
}
