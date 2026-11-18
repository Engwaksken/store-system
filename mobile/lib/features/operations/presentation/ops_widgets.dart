import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/common.dart';

/// Compact list card shared by the store-operation lists: number + badge, one or two lines, trailing figure.
class DocCard extends StatelessWidget {
  const DocCard({super.key, required this.title, this.badge, this.badgeTone, this.subtitle, this.meta, this.trailing, this.leadingIcon, required this.onTap});

  final String title;
  final String? badge;
  final String? badgeTone;
  final String? subtitle;
  final String? meta;
  final String? trailing;
  final IconData? leadingIcon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    return Card(
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  if (leadingIcon != null) ...[Icon(leadingIcon, size: 18, color: theme.colorScheme.primary), const SizedBox(width: 6)],
                  Expanded(
                    child: Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                  ),
                  if (badge != null)
                    Flexible(
                      child: StatusBadge.status(label: badge!, statusTone: badgeTone, dense: true),
                    ),
                ],
              ),
              if (subtitle != null && subtitle!.isNotEmpty) ...[const SizedBox(height: 6), Text(subtitle!, maxLines: 2, overflow: TextOverflow.ellipsis)],
              if ((meta != null && meta!.isNotEmpty) || trailing != null) ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: Text(meta ?? '', style: muted, overflow: TextOverflow.ellipsis),
                    ),
                    if (trailing != null) Text(trailing!, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Header card on detail screens: title, status badge, then label/value rows.
class DetailHeader extends StatelessWidget {
  const DetailHeader({super.key, required this.title, this.badge, this.badgeTone, this.headline, required this.fields});

  final String title;
  final String? badge;
  final String? badgeTone;

  /// Large figure under the title (e.g. a total), optional.
  final String? headline;
  final List<(String, String, IconData)> fields;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(title, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                ),
                if (badge != null)
                  Flexible(
                    child: StatusBadge.status(label: badge!, statusTone: badgeTone),
                  ),
              ],
            ),
            if (headline != null) ...[const SizedBox(height: 10), Text(headline!, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800))],
            const Divider(height: 28),
            for (var i = 0; i < fields.length; i++) ...[
              if (i > 0) const SizedBox(height: 12),
              InfoField(label: fields[i].$1, value: fields[i].$2, icon: fields[i].$3),
            ],
          ],
        ),
      ),
    );
  }
}

/// One item line on a detail screen: name, sku/unit, and right-aligned figures.
class LineTile extends StatelessWidget {
  const LineTile({super.key, required this.name, this.subtitle, required this.figures, this.note});
  final String name;
  final String? subtitle;

  /// (label, value) pairs shown on the right, e.g. ('Qty', '10 bag').
  final List<(String, String)> figures;
  final String? note;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name, style: theme.textTheme.bodyLarge?.copyWith(fontWeight: FontWeight.w600)),
                if (subtitle != null) Text(subtitle!, style: muted),
                if (note != null && note!.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(note!, style: muted),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final (label, value) in figures)
                Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(text: '$label ', style: muted),
                      TextSpan(
                        text: value,
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Card of [LineTile]s separated by dividers.
class LinesCard extends StatelessWidget {
  const LinesCard({super.key, required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Card(
    child: Column(
      children: [
        for (var i = 0; i < children.length; i++) ...[if (i > 0) const Divider(height: 1), children[i]],
      ],
    ),
  );
}

/// Sticky bottom bar with one or two actions (approve / reject, receive / cancel...).
class BottomActions extends StatelessWidget {
  const BottomActions({super.key, required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Material(
    elevation: 8,
    color: Theme.of(context).colorScheme.surface,
    child: SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
        child: ResponsiveCenter(
          child: Row(
            children: [
              for (var i = 0; i < children.length; i++) ...[if (i > 0) const SizedBox(width: 10), Expanded(child: children[i])],
            ],
          ),
        ),
      ),
    ),
  );
}

/// Outlined red button for Reject / Cancel.
class DangerOutlinedButton extends StatelessWidget {
  const DangerOutlinedButton({super.key, required this.label, required this.icon, required this.onPressed});
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return OutlinedButton.icon(
      style: OutlinedButton.styleFrom(
        foregroundColor: scheme.error,
        side: BorderSide(color: scheme.error),
      ),
      onPressed: onPressed,
      icon: Icon(icon),
      label: Text(label),
    );
  }
}

/// Progress strip under an app bar while a write is running.
PreferredSizeWidget? busyBar(bool busy) => busy ? const PreferredSize(preferredSize: Size.fromHeight(3), child: LinearProgressIndicator(minHeight: 3)) : null;

/// Validates a typed quantity: empty is allowed when [allowEmpty]; else a number in (0, max] (max optional).
String? validateQty(String? raw, {String? max, bool allowEmpty = false, bool allowZero = false, bool allowNegative = false}) {
  final v = (raw ?? '').trim();
  if (v.isEmpty) return allowEmpty ? null : 'Required';
  final d = Decimal.tryParse(v);
  if (d == null) return 'Enter a number';
  if (!allowNegative && d < Decimal.zero) return 'Cannot be negative';
  if (!allowZero && d == Decimal.zero) return allowNegative ? 'Cannot be zero' : 'Must be more than 0';
  if (d.scale > 3) return 'Up to 3 decimals';
  if (max != null) {
    final m = Decimal.tryParse(max);
    if (m != null && d > m) return 'At most $max';
  }
  return null;
}

/// Small key/value hint panel (warnings such as "not enough stock").
class NoticeCard extends StatelessWidget {
  const NoticeCard({super.key, required this.icon, required this.title, this.lines = const [], this.warning = true});
  final IconData icon;
  final String title;
  final List<String> lines;
  final bool warning;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = ToneColors.of(warning ? Tone.pending : Tone.navy, theme.brightness);
    return Card(
      color: c.background,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: c.foreground),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(color: c.foreground, fontWeight: FontWeight.w700),
                  ),
                  for (final l in lines)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(l, style: TextStyle(color: c.foreground)),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
