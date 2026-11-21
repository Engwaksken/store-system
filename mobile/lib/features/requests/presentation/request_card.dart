import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/collapsible.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../domain/request_models.dart';

/// Collapsible request card: number, status and purpose on one line; details open with the chevron.
class RequestCard extends ConsumerWidget {
  const RequestCard({super.key, required this.request, this.onReturn, this.actions = const [], this.initiallyExpanded = false});
  final RequestSummary request;

  /// Called after returning from the detail screen.
  final VoidCallback? onReturn;

  /// Buttons kept visible at the bottom of the card, collapsed or not.
  final List<Widget> actions;
  final bool initiallyExpanded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final r = request;
    final purpose = r.purpose.isEmpty ? '(no purpose given)' : r.purpose;
    return CollapsibleCard(
      initiallyExpanded: initiallyExpanded,
      onTap: () async {
        await context.push('/request/${r.id}');
        onReturn?.call();
      },
      header: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(r.requestNo, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
              ),
              const SizedBox(width: 8),
              Flexible(child: StatusBadge.status(label: r.statusLabel, statusTone: r.statusTone, dense: true)),
            ],
          ),
          const SizedBox(height: 4),
          Text(purpose, style: theme.textTheme.bodyMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
        ],
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (purpose.length > 40) ...[Text(purpose, style: theme.textTheme.bodyMedium), const SizedBox(height: 8)],
          Wrap(
            spacing: 14,
            runSpacing: 4,
            children: [
              if (r.project != null) _Meta(icon: Icons.apartment_outlined, text: r.project!.name, style: muted),
              if (r.requester != null) _Meta(icon: Icons.person_outline, text: r.requester!.name, style: muted),
              _Meta(icon: Icons.event_outlined, text: 'Needed ${f.date(r.neededOn)}', style: muted),
              _Meta(
                icon: Icons.format_list_bulleted,
                text: '${r.linesCount} ${r.linesCount == 1 ? 'item' : 'items'}',
                style: muted,
              ),
            ],
          ),
        ],
      ),
      footer: actions.isEmpty
          ? null
          : Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 10),
              child: Wrap(alignment: WrapAlignment.end, spacing: 8, runSpacing: 8, children: actions),
            ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.icon, required this.text, this.style});
  final IconData icon;
  final String text;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: style?.color),
          const SizedBox(width: 4),
          Flexible(child: Text(text, style: style, overflow: TextOverflow.ellipsis)),
        ],
      );
}
