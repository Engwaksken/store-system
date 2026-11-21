import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../features/reports/domain/report_models.dart';
import '../format/formatters.dart';
import 'collapsible.dart';

/// Chart colours (validated data-viz palette). Status tones keep their meaning and are always
/// shown with a text label; untoned series use the categorical order, never cycled.
class ChartColors {
  static const _categoricalLight = [
    Color(0xFF2A78D6),
    Color(0xFFEB6834),
    Color(0xFF1BAF7A),
    Color(0xFFEDA100),
    Color(0xFFE87BA4),
    Color(0xFF008300),
    Color(0xFF6250D6),
    Color(0xFFE34948),
  ];
  static const _categoricalDark = [
    Color(0xFF3987E5),
    Color(0xFFD95926),
    Color(0xFF199E70),
    Color(0xFFC98500),
    Color(0xFFD55181),
    Color(0xFF0CA30C),
    Color(0xFF9085E9),
    Color(0xFFE66767),
  ];

  static const good = Color(0xFF0CA30C);
  static const warning = Color(0xFFFAB219);
  static const critical = Color(0xFFD03B3B);

  /// Single-series marks (one hue; the chart title names the series).
  static Color single(Brightness b) => b == Brightness.dark ? _categoricalDark[0] : _categoricalLight[0];

  static Color categorical(int i, Brightness b) {
    final list = b == Brightness.dark ? _categoricalDark : _categoricalLight;
    return list[i.clamp(0, list.length - 1)];
  }

  static Color muted(Brightness b) => b == Brightness.dark ? const Color(0xFF6B7280) : const Color(0xFF94A3B8);

  /// Colour for a point: its tone when it has one, else categorical slot [index].
  static Color of(ChartPoint p, int index, Brightness b, {bool singleSeries = false}) => switch (p.tone) {
    'ok' || 'approved' => good,
    'gold' || 'pending' => warning,
    'bad' || 'rejected' => critical,
    'partial' || 'navy' => single(b),
    'muted' => muted(b),
    _ => singleSeries ? single(b) : categorical(index, b),
  };
}

/// Ring chart with the total in the middle and a legend (label, value, share) beside it.
class DonutChart extends StatelessWidget {
  const DonutChart({
    super.key,
    required this.chart,
    required this.formatters,
    this.size = 132,
    this.centerLabel = 'Total',
  });
  final ReportChart chart;
  final Formatters formatters;
  final double size;
  final String centerLabel;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final b = theme.brightness;
    final total = chart.total;
    final colors = [for (var i = 0; i < chart.series.length; i++) ChartColors.of(chart.series[i], i, b)];
    final totalText = chart.format == ValueFormat.count
        ? formatters.count(total.round())
        : chart.format.formatShort(formatters, total);

    final ring = Semantics(
      label:
          '${chart.title}: ${[for (final p in chart.series) '${p.label} ${chart.format.format(formatters, p.raw)}'].join(', ')}',
      excludeSemantics: true,
      child: SizedBox.square(
        dimension: size,
        child: CustomPaint(
          painter: _DonutPainter(
            values: [for (final p in chart.series) p.value],
            colors: colors,
            gap: theme.colorScheme.surface,
            empty: theme.colorScheme.surfaceContainerHighest,
          ),
          child: Center(
            child: Padding(
              padding: EdgeInsets.all(size * 0.2),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(totalText, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                  ),
                  Text(
                    centerLabel,
                    style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );

    final legend = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < chart.series.length; i++)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                Container(
                  width: 10,
                  height: 10,
                  decoration: BoxDecoration(color: colors[i], borderRadius: BorderRadius.circular(3)),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    chart.series[i].label,
                    style: theme.textTheme.bodySmall,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  chart.format == ValueFormat.count
                      ? chart.format.format(formatters, chart.series[i].raw)
                      : chart.format.formatShort(formatters, chart.series[i].value),
                  style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700),
                ),
                SizedBox(
                  width: 40,
                  child: Text(
                    total <= 0 ? '' : '${(chart.series[i].value / total * 100).round()}%',
                    textAlign: TextAlign.end,
                    style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ),
              ],
            ),
          ),
      ],
    );

    return LayoutBuilder(
      builder: (context, c) => c.maxWidth < 300
          ? Column(children: [ring, const SizedBox(height: 12), legend])
          : Row(
              children: [
                ring,
                const SizedBox(width: 20),
                Expanded(child: legend),
              ],
            ),
    );
  }
}

class _DonutPainter extends CustomPainter {
  _DonutPainter({required this.values, required this.colors, required this.gap, required this.empty});
  final List<double> values;
  final List<Color> colors;
  final Color gap;
  final Color empty;

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.shortestSide * 0.16;
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: (size.shortestSide - stroke) / 2);
    final total = values.fold<double>(0, (s, v) => s + math.max(0, v));
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    if (total <= 0) {
      canvas.drawArc(rect, 0, math.pi * 2, false, paint..color = empty);
      return;
    }
    final shown = values.where((v) => v > 0).length;
    // A 2px surface gap between segments, expressed as an angle on the ring's centre line.
    final gapAngle = shown > 1 ? 2 / (rect.width / 2) : 0.0;
    var start = -math.pi / 2;
    for (var i = 0; i < values.length; i++) {
      if (values[i] <= 0) continue;
      final sweep = values[i] / total * math.pi * 2;
      canvas.drawArc(rect, start + gapAngle / 2, math.max(0.001, sweep - gapAngle), false, paint..color = colors[i]);
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(_DonutPainter old) => old.values != values || old.colors != colors || old.gap != gap;
}

/// Horizontal bars: label and value above each bar, bars scaled to the largest value.
class BarList extends StatelessWidget {
  const BarList({super.key, required this.chart, required this.formatters, this.maxBars});
  final ReportChart chart;
  final Formatters formatters;
  final int? maxBars;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final b = theme.brightness;
    final points = maxBars == null ? chart.series : chart.series.take(maxBars!).toList();
    final max = points.fold<double>(0, (m, p) => math.max(m, p.value));
    final single = points.every((p) => p.tone.isEmpty);
    return Column(
      children: [
        for (var i = 0; i < points.length; i++)
          Semantics(
            label: '${points[i].label}: ${chart.format.format(formatters, points[i].raw)}',
            excludeSemantics: true,
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          points[i].label,
                          style: theme.textTheme.bodySmall,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(
                        chart.format.format(formatters, points[i].raw),
                        style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  LayoutBuilder(
                    builder: (context, c) => Stack(
                      children: [
                        Container(
                          height: 8,
                          decoration: BoxDecoration(
                            color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.7),
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                        Container(
                          height: 8,
                          width: max <= 0 ? 0 : math.max(4, c.maxWidth * points[i].value / max),
                          decoration: BoxDecoration(
                            color: ChartColors.of(points[i], i, b, singleSeries: single),
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

/// Vertical columns over time. Tap a column for its exact value; the largest one is labelled.
class ColumnChart extends StatefulWidget {
  const ColumnChart({super.key, required this.chart, required this.formatters, this.height = 150});
  final ReportChart chart;
  final Formatters formatters;
  final double height;

  @override
  State<ColumnChart> createState() => _ColumnChartState();
}

class _ColumnChartState extends State<ColumnChart> {
  int? _selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final chart = widget.chart;
    final f = widget.formatters;
    final points = chart.series;
    final max = points.fold<double>(0, (m, p) => math.max(m, p.value));
    final peak = max <= 0 ? -1 : points.indexWhere((p) => p.value == max);
    final highlight = _selected ?? peak;
    final color = ChartColors.single(theme.brightness);
    final labelEvery = (points.length / 7).ceil().clamp(1, 99);

    return Column(
      children: [
        SizedBox(
          height: widget.height,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (var i = 0; i < points.length; i++)
                Expanded(
                  child: Semantics(
                    label: '${points[i].label}: ${chart.format.format(f, points[i].raw)}',
                    button: true,
                    excludeSemantics: true,
                    child: GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      onTap: () => setState(() => _selected = _selected == i ? null : i),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 1),
                        child: LayoutBuilder(
                          builder: (context, c) {
                            final labelH = 18.0;
                            final barMax = c.maxHeight - labelH;
                            final h = max <= 0
                                ? 0.0
                                : math.max(points[i].value > 0 ? 3.0 : 0.0, barMax * points[i].value / max);
                            return Column(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: [
                                SizedBox(
                                  height: labelH,
                                  child: i == highlight
                                      ? FittedBox(
                                          fit: BoxFit.scaleDown,
                                          child: Text(
                                            chart.format.formatShort(f, points[i].value),
                                            style: theme.textTheme.labelSmall?.copyWith(fontWeight: FontWeight.w700),
                                          ),
                                        )
                                      : null,
                                ),
                                Container(
                                  height: h,
                                  constraints: const BoxConstraints(maxWidth: 28),
                                  decoration: BoxDecoration(
                                    color: _selected == null || _selected == i ? color : color.withValues(alpha: 0.4),
                                    borderRadius: const BorderRadius.vertical(top: Radius.circular(4)),
                                  ),
                                ),
                              ],
                            );
                          },
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
        Divider(height: 1, color: theme.colorScheme.outlineVariant),
        const SizedBox(height: 4),
        Row(
          children: [
            for (var i = 0; i < points.length; i++)
              Expanded(
                child: Text(
                  i % labelEvery == 0 ? points[i].label : '',
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.clip,
                  style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant, fontSize: 10),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

/// The right chart widget for [chart.type], or a quiet message when it has no data.
class ReportChartView extends StatelessWidget {
  const ReportChartView({super.key, required this.chart, required this.formatters, this.maxBars});
  final ReportChart chart;
  final Formatters formatters;
  final int? maxBars;

  @override
  Widget build(BuildContext context) {
    if (chart.series.isEmpty || chart.isEmpty) {
      final theme = Theme.of(context);
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 20),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.bar_chart, color: theme.colorScheme.outline),
            const SizedBox(width: 8),
            Text(
              'No data for this period',
              style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          ],
        ),
      );
    }
    return switch (chart.type) {
      ChartType.donut => DonutChart(chart: chart, formatters: formatters),
      ChartType.bar => BarList(chart: chart, formatters: formatters, maxBars: maxBars),
      ChartType.column => ColumnChart(chart: chart, formatters: formatters),
    };
  }
}

/// Card with a chart's title, subtitle and body.
class ChartCard extends StatelessWidget {
  const ChartCard({
    super.key,
    required this.chart,
    required this.formatters,
    this.maxBars,
    this.trailing,
    this.collapsible = false,
    this.initiallyExpanded = true,
    this.storageKey,
  });
  final ReportChart chart;
  final Formatters formatters;
  final int? maxBars;
  final Widget? trailing;

  /// Lets the chart fold away behind its title; closed, a row of figures stays visible.
  final bool collapsible;
  final bool initiallyExpanded;
  final String? storageKey;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final title = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text(chart.title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
        ),
        if (chart.subtitle.isNotEmpty)
          Text(
            chart.subtitle,
            style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
      ],
    );
    final view = ReportChartView(chart: chart, formatters: formatters, maxBars: maxBars);

    if (collapsible) {
      return CollapsibleCard(
        initiallyExpanded: initiallyExpanded,
        storageKey: storageKey,
        padding: const EdgeInsets.fromLTRB(16, 8, 4, 8),
        bodyPadding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
        header: Row(children: [Expanded(child: title), ?trailing]),
        body: view,
        summary: ChartDigest(chart: chart, formatters: formatters),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [Expanded(child: title), ?trailing]),
            const SizedBox(height: 14),
            view,
          ],
        ),
      ),
    );
  }
}

/// One-glance figures for a folded chart: a coloured dot, label and value per series point.
class ChartDigest extends StatelessWidget {
  const ChartDigest({super.key, required this.chart, required this.formatters, this.max = 4});
  final ReportChart chart;
  final Formatters formatters;
  final int max;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final b = theme.brightness;
    // Status charts keep their order; others show their largest points first.
    final toned = chart.series.any((p) => p.tone.isNotEmpty);
    final indexed = [
      for (var i = 0; i < chart.series.length; i++)
        if (chart.series[i].value > 0) (i, chart.series[i]),
    ];
    if (!toned) indexed.sort((x, y) => y.$2.value.compareTo(x.$2.value));
    if (indexed.isEmpty) {
      return Text('No data for this period', style: theme.textTheme.bodySmall);
    }
    final shown = indexed.take(max).toList();
    final rest = indexed.length - shown.length;
    final style = theme.textTheme.bodySmall;
    return Wrap(
      spacing: 14,
      runSpacing: 6,
      children: [
        for (final (i, p) in shown)
          Text.rich(
            TextSpan(
              children: [
                WidgetSpan(
                  alignment: PlaceholderAlignment.middle,
                  child: Container(
                    width: 10,
                    height: 10,
                    margin: const EdgeInsets.only(right: 6),
                    decoration: BoxDecoration(
                      color: ChartColors.of(p, i, b, singleSeries: !toned && chart.type != ChartType.donut),
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
                TextSpan(text: '${p.label} ', style: style?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                TextSpan(
                  text: chart.format.format(formatters, p.raw),
                  style: style?.copyWith(fontWeight: FontWeight.w700),
                ),
              ],
            ),
          ),
        if (rest > 0) Text('+$rest more', style: style?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
      ],
    );
  }
}
