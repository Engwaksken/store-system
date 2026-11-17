import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/feedback_repository.dart';
import '../domain/feedback_models.dart';

class FeedbackDetailScreen extends ConsumerWidget {
  const FeedbackDetailScreen({super.key, required this.id});
  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final async = ref.watch(feedbackDetailProvider(id));
    void reload() => ref.invalidate(feedbackDetailProvider(id));
    return Scaffold(
      appBar: AppBar(
        title: Text(async.value?.reference ?? 'Feedback'),
        actions: [IconButton(tooltip: 'Reload', onPressed: reload, icon: const Icon(Icons.refresh))],
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: reload),
        data: (item) => RefreshIndicator(
          onRefresh: () => ref.refresh(feedbackDetailProvider(id).future),
          child: _Body(item: item),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.item});
  final FeedbackItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        ResponsiveCenter(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(item.subject,
                            style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                      ),
                      const SizedBox(width: 8),
                      StatusBadge.status(label: item.statusLabel, statusTone: item.statusTone),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text('${item.reference} · ${item.categoryLabel}', style: theme.textTheme.bodySmall?.copyWith(color: muted)),
                  const SizedBox(height: 14),
                  SelectableText(item.message, style: theme.textTheme.bodyLarge),
                  const Divider(height: 28),
                  InfoField(label: 'From', value: item.user?.name ?? '-', icon: Icons.person_outline),
                  const SizedBox(height: 10),
                  InfoField(label: 'Sent', value: f.dateTime(item.createdAt), icon: Icons.schedule),
                  const SizedBox(height: 10),
                  InfoField(
                    label: 'Rating',
                    value: item.rating == null ? '-' : '${ratingStars(item.rating!)}  ${ratingLabels[item.rating]}',
                    icon: Icons.star_outline_rounded,
                  ),
                  const SizedBox(height: 10),
                  InfoField(
                    label: 'Sent from',
                    value: [
                      item.source == 'mobile' ? 'Mobile app' : 'Web app',
                      if (item.appVersion != null) 'v${item.appVersion}',
                      ?item.device,
                    ].join(' · '),
                    icon: item.source == 'mobile' ? Icons.smartphone : Icons.computer,
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        ResponsiveCenter(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Icon(Icons.reply, color: muted),
                    const SizedBox(width: 8),
                    Text('Reply', style: theme.textTheme.titleMedium),
                  ]),
                  const SizedBox(height: 10),
                  if (item.hasReply) ...[
                    SelectableText(item.response!, style: theme.textTheme.bodyLarge),
                    const SizedBox(height: 8),
                    Text(
                      '${item.responder?.name ?? 'An administrator'} · ${f.dateTime(item.respondedAt)}',
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  ] else
                    Text('No reply yet. You are notified when an administrator replies.',
                        style: theme.textTheme.bodyMedium?.copyWith(color: muted)),
                ],
              ),
            ),
          ),
        ),
        if (item.canRespond) ...[
          const SizedBox(height: 12),
          ResponsiveCenter(child: _RespondCard(key: ValueKey('${item.id}-${item.status}-${item.response}'), item: item)),
        ],
      ],
    );
  }
}

/// Admin: set the status and reply. The sender is notified.
class _RespondCard extends ConsumerStatefulWidget {
  const _RespondCard({super.key, required this.item});
  final FeedbackItem item;

  @override
  ConsumerState<_RespondCard> createState() => _RespondCardState();
}

class _RespondCardState extends ConsumerState<_RespondCard> {
  late String _status = widget.item.status;
  late final _response = TextEditingController(text: widget.item.response);
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    _response.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(feedbackRepositoryProvider).respond(widget.item.id, status: _status, response: _response.text);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Feedback updated');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e);
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final options = ref.watch(feedbackOptionsProvider).value ?? FeedbackOptions.fallback;
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Update', style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: options.statuses.any((s) => s.value == _status) ? _status : null,
              decoration: InputDecoration(labelText: 'Status', errorText: _error?.fieldError('status')),
              items: [for (final s in options.statuses) DropdownMenuItem(value: s.value, child: Text(s.label))],
              onChanged: _busy ? null : (v) => setState(() => _status = v ?? _status),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _response,
              enabled: !_busy,
              minLines: 3,
              maxLines: 8,
              maxLength: 5000,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(
                labelText: 'Reply to ${widget.item.user?.name ?? 'the sender'} (optional)',
                helperText: 'The sender is notified when you reply or change the status.',
                alignLabelWithHint: true,
                errorText: _error?.fieldError('response'),
              ),
            ),
            const SizedBox(height: 8),
            FilledButton.icon(
              onPressed: _busy ? null : _save,
              icon: _busy
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.save_outlined),
              label: const Text('Save'),
            ),
          ],
        ),
      ),
    );
  }
}
