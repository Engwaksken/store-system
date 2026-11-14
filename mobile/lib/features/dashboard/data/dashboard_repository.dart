import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/providers.dart';
import '../../auth/data/auth_controller.dart';
import '../../../core/util/data_revision.dart';
import '../domain/dashboard_models.dart';

/// `GET /dashboard`. Reloads whenever data changes (decisions, reads...).
final dashboardProvider = FutureProvider<Dashboard>((ref) async {
  ref.watch(currentUserProvider.select((u) => u?.id));
  ref.watch(dataRevisionProvider);
  final json = await ref.watch(apiClientProvider).get('/dashboard');
  return Dashboard.fromJson(json);
});

/// Unread notification count for badges. Keeps the last known value while
/// the dashboard reloads or fails, so the badge does not flicker.
final unreadCountProvider = Provider<int>((ref) {
  final d = ref.watch(dashboardProvider);
  return d.value?.unreadNotifications ?? 0;
});
