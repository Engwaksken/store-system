import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../domain/notification_models.dart';

class NotificationsRepository {
  NotificationsRepository(this._api);
  final ApiClient _api;

  Future<Paginated<AppNotification>> list({int page = 1, bool unreadOnly = false}) async {
    final json = await _api.get('/notifications', query: {'page': page, if (unreadOnly) 'unread': 1});
    return Paginated.fromJson(json, AppNotification.fromJson);
  }

  Future<void> markRead(String id) => _api.post('/notifications/$id/read');

  Future<void> markAllRead() => _api.post('/notifications/read-all');
}

final notificationsRepositoryProvider = Provider<NotificationsRepository>(
  (ref) => NotificationsRepository(ref.watch(apiClientProvider)),
);
