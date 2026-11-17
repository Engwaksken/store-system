import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/util/data_revision.dart';
import '../domain/feedback_models.dart';

/// Feedback (`/feedback`): everyone sends and follows their own; admins see all and reply.
class FeedbackRepository {
  FeedbackRepository(this._api);
  final ApiClient _api;

  Future<Paginated<FeedbackItem>> list({int page = 1, String? status, String? scope, String? q}) async {
    final json = await _api.get('/feedback', query: {'page': page, 'status': status, 'scope': scope, 'q': q});
    return Paginated.fromJson(json, FeedbackItem.fromJson);
  }

  Future<FeedbackItem> detail(int id) async => FeedbackItem.fromJson(asMap((await _api.get('/feedback/$id'))['data']));

  Future<FeedbackOptions> options() async => FeedbackOptions.fromJson(await _api.get('/feedback/options'));

  Future<FeedbackItem> send({
    required String category,
    required String subject,
    required String message,
    int? rating,
    String? appVersion,
    String? device,
  }) async {
    final json = await _api.post('/feedback', body: {
      'category': category,
      'subject': subject.trim(),
      'message': message.trim(),
      'rating': rating,
      'app_version': appVersion,
      'device': device,
    });
    return FeedbackItem.fromJson(asMap(json['data']));
  }

  Future<FeedbackItem> respond(int id, {required String status, String? response}) async {
    final json = await _api.post('/feedback/$id/respond', body: {
      'status': status,
      'response': (response ?? '').trim().isEmpty ? null : response!.trim(),
    });
    return FeedbackItem.fromJson(asMap(json['data']));
  }
}

final feedbackRepositoryProvider = Provider<FeedbackRepository>((ref) => FeedbackRepository(ref.watch(apiClientProvider)));

final feedbackDetailProvider = FutureProvider.autoDispose.family<FeedbackItem, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(feedbackRepositoryProvider).detail(id);
});

/// Falls back to the built-in list so the form still works if the call fails.
final feedbackOptionsProvider = FutureProvider.autoDispose<FeedbackOptions>((ref) async {
  try {
    return await ref.watch(feedbackRepositoryProvider).options();
  } catch (_) {
    return FeedbackOptions.fallback;
  }
});
