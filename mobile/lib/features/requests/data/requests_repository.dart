import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/api/refs.dart';
import '../../../core/util/data_revision.dart';
import '../domain/request_models.dart';

class RequestsRepository {
  RequestsRepository(this._api);
  final ApiClient _api;

  Future<Paginated<RequestSummary>> approvals({int page = 1, String? q}) async {
    final json = await _api.get('/approvals', query: {'page': page, 'q': q});
    return Paginated.fromJson(json, RequestSummary.fromJson);
  }

  Future<Paginated<RequestSummary>> list({int page = 1, String? status, int? projectId, String? q}) async {
    final json = await _api.get('/requests', query: {
      'page': page,
      'status': status,
      'project_id': projectId,
      'q': q,
    });
    return Paginated.fromJson(json, RequestSummary.fromJson);
  }

  Future<RequestDetail> detail(int id) async {
    final json = await _api.get('/requests/$id');
    return RequestDetail.fromJson(asMap(json['data']));
  }

  /// [qty] maps line id to approved quantity (decimal string).
  Future<RequestDetail> decide(
    int id, {
    required bool approve,
    required int version,
    String? reason,
    Map<int, String>? qty,
  }) async {
    final json = await _api.post('/requests/$id/decision', body: {
      'decision': approve ? 'approved' : 'rejected',
      if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
      if (approve && qty != null && qty.isNotEmpty) 'qty': {for (final e in qty.entries) '${e.key}': e.value},
      'version': version,
    });
    return RequestDetail.fromJson(asMap(json['data']));
  }

  Future<RequestDetail> cancel(int id) async {
    final json = await _api.post('/requests/$id/cancel');
    return RequestDetail.fromJson(asMap(json['data']));
  }

  Future<RequestDetail> create({
    required int projectId,
    required String purpose,
    required String neededOn,
    required List<NewRequestLine> lines,
    required bool submit,
  }) async {
    final json = await _api.post('/requests', body: {
      'project_id': projectId,
      'purpose': purpose.trim(),
      'needed_on': neededOn,
      'lines': [for (final l in lines) l.toJson()],
      'submit': submit,
    });
    return RequestDetail.fromJson(asMap(json['data']));
  }

  Future<List<CodedRef>> projectOptions() async {
    final json = await _api.get('/requests/options');
    return asList(json['projects'], CodedRef.fromJson);
  }
}

final requestsRepositoryProvider = Provider<RequestsRepository>(
  (ref) => RequestsRepository(ref.watch(apiClientProvider)),
);

final requestDetailProvider = FutureProvider.autoDispose.family<RequestDetail, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(requestsRepositoryProvider).detail(id);
});

final requestProjectOptionsProvider = FutureProvider.autoDispose<List<CodedRef>>(
  (ref) => ref.watch(requestsRepositoryProvider).projectOptions(),
);
