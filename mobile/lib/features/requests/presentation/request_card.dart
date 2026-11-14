import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../domain/request_models.dart';

class RequestCard extends ConsumerWidget {
  const RequestCard({super.key, required this.request, this.onReturn});
  final RequestSummary request;

  /// Called after returning from the detail screen.
  final VoidCallback? onReturn;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant);
    final r = request;
    return Card(
      child: InkWell(
        onTap: () async {
          await context.push('/request/${r.id}');
          onReturn?.call();
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      r.requestNo,
                      style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Flexible(child: StatusBadge.status(label: r.statusLabel, statusTone: r.statusTone, dense: true)),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                r.purpose.isEmpty ? '(no purpose given)' : r.purpose,
                style: theme.textTheme.bodyLarge,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 8),
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
        ),
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
