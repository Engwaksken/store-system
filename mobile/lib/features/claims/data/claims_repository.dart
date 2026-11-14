import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/util/data_revision.dart';
import '../domain/claim_models.dart';

/// Receipt chosen for a new claim.
class ReceiptFile {
  const ReceiptFile({required this.path, required this.name, required this.size});
  final String path;
  final String name;
  final int size;

  static const maxBytes = 4 * 1024 * 1024;
  static const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

  String get extension => name.contains('.') ? name.split('.').last.toLowerCase() : '';

  /// Null when acceptable, else a message.
  String? validate() {
    if (!allowedExtensions.contains(extension)) return 'Receipt must be a PDF, JPG or PNG file.';
    if (size > maxBytes) return 'Receipt is larger than 4 MB. Choose a smaller file.';
    return null;
  }

  bool get isImage => const ['jpg', 'jpeg', 'png'].contains(extension);
}

class ClaimsRepository {
  ClaimsRepository(this._api);
  final ApiClient _api;

  Future<Paginated<Claim>> list({int page = 1, String? status, int? projectId, String? q}) async {
    final json = await _api.get('/claims', query: {
      'page': page,
      'status': status,
      'project_id': projectId,
      'q': q,
    });
    return Paginated.fromJson(json, Claim.fromJson);
  }

  Future<Claim> detail(int id) async {
    final json = await _api.get('/claims/$id');
    return Claim.fromJson(asMap(json['data']));
  }

  Future<ClaimOptions> options() async => ClaimOptions.fromJson(await _api.get('/claims/options'));

  Future<Claim> decide(int id, {required bool approve, String? reason, required String? seenAt}) async {
    final json = await _api.post('/claims/$id/decision', body: {
      'decision': approve ? 'approved' : 'rejected',
      if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
      'seen_at': seenAt,
    });
    return Claim.fromJson(asMap(json['data']));
  }

  Future<Claim> create({
    required int projectId,
    required int categoryId,
    int? costCodeId,
    required String incurredOn,
    required String amount,
    required String description,
    ReceiptFile? receipt,
    void Function(double progress)? onProgress,
    CancelToken? cancelToken,
  }) async {
    final form = FormData.fromMap({
      'project_id': '$projectId',
      'category_id': '$categoryId',
      if (costCodeId != null) 'cost_code_id': '$costCodeId',
      'incurred_on': incurredOn,
      'amount': amount,
      'description': description.trim(),
      if (receipt != null) 'receipt': await MultipartFile.fromFile(receipt.path, filename: receipt.name),
    });
    final json = await _api.postMultipart(
      '/claims',
      form,
      cancelToken: cancelToken,
      onSendProgress: (sent, total) {
        if (total > 0) onProgress?.call(sent / total);
      },
    );
    return Claim.fromJson(asMap(json['data']));
  }

  Future<DownloadedFile> receipt(String url) => _api.download(url);
}

final claimsRepositoryProvider = Provider<ClaimsRepository>((ref) => ClaimsRepository(ref.watch(apiClientProvider)));

final claimDetailProvider = FutureProvider.autoDispose.family<Claim, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(claimsRepositoryProvider).detail(id);
});

final claimOptionsProvider = FutureProvider.autoDispose<ClaimOptions>(
  (ref) => ref.watch(claimsRepositoryProvider).options(),
);
