import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/format/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/charts.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/reports_repository.dart';
import '../domain/report_models.dart';

class ReportsScreen extends ConsumerWidget {
  const ReportsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final days = ref.watch(reportPeriodProvider);
    final summary = ref.watch(reportSummaryProvider(days));

    return Scaffold(
      appBar: AppBar(title: const Text('Reports')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: ResponsiveCenter(
              child: SizedBox(
                width: double.infinity,
                child: SegmentedButton<int>(
                  showSelectedIcon: false,
                  style: const ButtonStyle(visualDensity: VisualDensity.compact),
                  segments: [
                    for (final (d, label) in reportPeriods)
                      ButtonSegment(
                        value: d,
                        label: FittedBox(fit: BoxFit.scaleDown, child: Text(label, maxLines: 1)),
                      ),
                  ],
                  selected: {days},
                  onSelectionChanged: (s) => ref.read(reportPeriodProvider.notifier).set(s.first),
                ),
              ),
            ),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () => ref.refresh(reportSummaryProvider(days).future),
              child: summary.when(
                skipLoadingOnRefresh: true,
                loading: () => const SkeletonList(count: 4),
                error: (e, _) => e is ApiException && e.statusCode == 404
                    ? const MessageView(
                        icon: Icons.update,
                        title: 'Reports are not available yet',
                        message: 'Ask your administrator to update the server to the latest version.',
                      )
                    : ErrorView(error: e, onRetry: () => ref.invalidate(reportSummaryProvider(days))),
                data: (s) => _ReportsBody(summary: s),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ReportsBody extends ConsumerWidget {
  const _ReportsBody({required this.summary});
  final ReportSummary summary;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
      children: [
        if (summary.kpis.isNotEmpty)
          ResponsiveCenter(
            child: LayoutBuilder(
              builder: (context, c) {
                final columns = c.maxWidth >= 600 ? 4 : 2;
                const gap = 10.0;
                final w = (c.maxWidth - gap * (columns - 1)) / columns;
                return Wrap(
                  spacing: gap,
                  runSpacing: gap,
                  children: [
                    for (final k in summary.kpis)
                      SizedBox(
                        width: w,
                        child: KpiTile(kpi: k, formatters: f),
                      ),
                  ],
                );
              },
            ),
          ),
        for (final chart in summary.charts) ...[
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: ChartCard(chart: chart, formatters: f),
          ),
        ],
        const SizedBox(height: 16),
        ResponsiveCenter(
          child: Text(
            'Figures cover what you are allowed to see. Full tables, exports and finance reports are on the web app.',
            textAlign: TextAlign.center,
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
          ),
        ),
      ],
    );
  }
}

/// Headline number with a coloured accent: used on Reports and Home.
class KpiTile extends StatelessWidget {
  const KpiTile({super.key, required this.kpi, required this.formatters});
  final ReportKpi kpi;
  final Formatters formatters;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = ToneColors.of(toneFromCard(kpi.tone), theme.brightness);
    final value = kpi.format.format(formatters, kpi.value);
    return Semantics(
      label: '${kpi.label}: $value',
      excludeSemantics: true,
      child: Card(
        child: Container(
          padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: c.foreground, width: 3)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                kpi.label,
                style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 4),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  value,
                  maxLines: 1,
                  style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
