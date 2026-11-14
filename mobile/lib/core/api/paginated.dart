import 'json.dart';

class PageMeta {
  const PageMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  bool get hasMore => currentPage < lastPage;

  factory PageMeta.fromJson(Json json) => PageMeta(
        currentPage: asInt(json['current_page'], 1),
        lastPage: asInt(json['last_page'], 1),
        perPage: asInt(json['per_page'], 20),
        total: asInt(json['total']),
      );
}

/// A page of a paginated list endpoint: `{ "data": [...], "meta": {...} }`.
class Paginated<T> {
  const Paginated({required this.data, required this.meta});

  final List<T> data;
  final PageMeta meta;

  factory Paginated.fromJson(Json json, T Function(Json) parse) {
    final items = asList(json['data'], parse);
    final metaJson = asMapOrNull(json['meta']);
    return Paginated(
      data: items,
      meta: metaJson != null
          ? PageMeta.fromJson(metaJson)
          : PageMeta(currentPage: 1, lastPage: 1, perPage: items.length, total: items.length),
    );
  }
}
