import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/providers.dart';
import '../../../core/util/data_revision.dart';
import '../../auth/data/auth_controller.dart';
import '../domain/report_models.dart';

/// Periods offered on the Reports screen, in days.
const reportPeriods = <(int, String)>[(7, '7 days'), (30, '30 days'), (90, '90 days'), (365, '12 months')];

/// `GET /reports?days=N`: chart-ready summaries filtered to what the user may see.
final reportSummaryProvider = FutureProvider.autoDispose.family<ReportSummary, int>((ref, days) async {
  ref.watch(currentUserProvider.select((u) => u?.id));
  ref.watch(dataRevisionProvider);
  final json = await ref.watch(apiClientProvider).get('/reports', query: {'days': days});
  return ReportSummary.fromJson(json);
});

class ReportPeriodNotifier extends Notifier<int> {
  @override
  int build() => 30;

  void set(int days) => state = days;
}

/// The period picked on the Reports screen.
final reportPeriodProvider = NotifierProvider<ReportPeriodNotifier, int>(ReportPeriodNotifier.new);
