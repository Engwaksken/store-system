import '../../../core/api/json.dart';
import '../../../core/format/formatters.dart';

/// How a report value is shown: a whole count, money or a quantity.
enum ValueFormat {
  count,
  money,
  qty;

  static ValueFormat parse(String? s) => switch (s) {
    'money' => ValueFormat.money,
    'qty' => ValueFormat.qty,
    _ => ValueFormat.count,
  };

  /// Full value for labels and tooltips.
  String format(Formatters f, Object? raw) => switch (this) {
    ValueFormat.count => raw is num ? f.count(raw) : f.qty(asDecimalString(raw)),
    ValueFormat.money => f.money(asDecimalString(raw)),
    ValueFormat.qty => f.qty(asDecimalString(raw)),
  };

  /// Short value for tight chart labels; money keeps its currency.
  String formatShort(Formatters f, double value) => switch (this) {
    ValueFormat.money =>
      value.abs() < 100000 ? f.money(value.toStringAsFixed(2)) : '${f.currency} ${f.compact(value)}'.trim(),
    _ => value.abs() < 100000 ? f.qty(value.toString()) : f.compact(value),
  };
}

/// One bar / slice. [raw] keeps the API value (int or decimal string) for exact display.
class ChartPoint {
  const ChartPoint({required this.label, required this.value, required this.raw, this.tone = ''});
  final String label;
  final double value;
  final Object? raw;

  /// `ok | bad | gold | navy | pending | approved | rejected | partial | muted | ""`.
  final String tone;

  factory ChartPoint.fromJson(Json json) {
    final raw = json['value'];
    final value = raw is num ? raw.toDouble() : double.tryParse('$raw') ?? 0;
    return ChartPoint(label: asString(json['label']), value: value, raw: raw, tone: asString(json['tone']));
  }
}

enum ChartType {
  donut,
  bar,
  column;

  static ChartType parse(String? s) => switch (s) {
    'donut' => ChartType.donut,
    'column' => ChartType.column,
    _ => ChartType.bar,
  };
}

class ReportChart {
  const ReportChart({
    required this.key,
    required this.title,
    required this.subtitle,
    required this.type,
    required this.format,
    required this.series,
  });

  final String key;
  final String title;
  final String subtitle;
  final ChartType type;
  final ValueFormat format;
  final List<ChartPoint> series;

  double get total => series.fold(0, (s, p) => s + p.value);
  bool get isEmpty => series.every((p) => p.value <= 0);

  factory ReportChart.fromJson(Json json) => ReportChart(
    key: asString(json['key']),
    title: asString(json['title']),
    subtitle: asString(json['subtitle']),
    type: ChartType.parse(asStringOrNull(json['type'])),
    format: ValueFormat.parse(asStringOrNull(json['format'])),
    series: asList(json['series'], ChartPoint.fromJson),
  );
}

class ReportKpi {
  const ReportKpi({
    required this.key,
    required this.label,
    required this.value,
    required this.format,
    required this.tone,
  });
  final String key;
  final String label;
  final Object? value;
  final ValueFormat format;
  final String tone;

  factory ReportKpi.fromJson(Json json) => ReportKpi(
    key: asString(json['key']),
    label: asString(json['label']),
    value: json['value'],
    format: ValueFormat.parse(asStringOrNull(json['format'])),
    tone: asString(json['tone']),
  );
}

class ReportSummary {
  const ReportSummary({required this.days, required this.periodLabel, required this.kpis, required this.charts});
  final int days;
  final String periodLabel;
  final List<ReportKpi> kpis;
  final List<ReportChart> charts;

  ReportChart? chart(String key) => charts.where((c) => c.key == key).firstOrNull;

  factory ReportSummary.fromJson(Json json) {
    final period = asMap(json['period']);
    return ReportSummary(
      days: asInt(period['days'], 30),
      periodLabel: asString(period['label']),
      kpis: asList(json['kpis'], ReportKpi.fromJson),
      charts: asList(json['charts'], ReportChart.fromJson),
    );
  }
}
