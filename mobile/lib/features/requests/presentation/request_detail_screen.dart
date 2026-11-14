import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/format/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/requests_repository.dart';
import '../domain/request_models.dart';
import 'approve_editor_screen.dart';

class RequestDetailScreen extends ConsumerStatefulWidget {
  const RequestDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<RequestDetailScreen> createState() => _RequestDetailScreenState();
}

class _RequestDetailScreenState extends ConsumerState<RequestDetailScreen> {
  bool _busy = false;

  void _reload() => ref.invalidate(requestDetailProvider(widget.id));

  /// Shared error handling for actions. 409 = the request changed.
  Future<void> _handleActionError(Object e) async {
    if (!mounted) return;
    if (e is ApiException && e.isConflict) {
      await showConflictDialog(context, e.message);
      ref.read(dataRevisionProvider.notifier).bump(); // reloads this screen and the lists
      return;
    }
    showSnack(context, ApiException.messageOf(e), error: true);
  }

  Future<void> _approve(RequestDetail r) async {
    final result = await Navigator.of(context).push<ApproveResult>(
      MaterialPageRoute(builder: (_) => ApproveEditorScreen(request: r), fullscreenDialog: true),
    );
    if (!mounted || result == null) return;
    if (result == ApproveResult.conflict) {
      ref.read(dataRevisionProvider.notifier).bump();
    } else {
      showSnack(context, '${r.requestNo} approved');
    }
  }

  Future<void> _reject(RequestDetail r) async {
    final reason = await askReason(context, title: 'Reject ${r.requestNo}');
    if (reason == null || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(requestsRepositoryProvider)
          .decide(r.id, approve: false, reason: reason, version: r.version);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, '${r.requestNo} rejected');
    } catch (e) {
      await _handleActionError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(RequestDetail r) async {
    final ok = await confirmDialog(
      context,
      title: 'Cancel ${r.requestNo}?',
      message: 'The request will be withdrawn and cannot be approved any more.',
      confirmLabel: 'Cancel request',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(requestsRepositoryProvider).cancel(r.id);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, '${r.requestNo} cancelled');
    } catch (e) {
      await _handleActionError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(requestDetailProvider(widget.id));
    final r = async.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(r?.requestNo ?? 'Request'),
        actions: [IconButton(tooltip: 'Reload', onPressed: _reload, icon: const Icon(Icons.refresh))],
        bottom: (_busy || (async.isLoading && r != null))
            ? const PreferredSize(preferredSize: Size.fromHeight(3), child: LinearProgressIndicator(minHeight: 3))
            : null,
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 4),
        error: (e, _) => ErrorView(error: e, onRetry: _reload),
        data: (r) => RefreshIndicator(
          onRefresh: () => ref.refresh(requestDetailProvider(widget.id).future),
          child: _RequestBody(request: r),
        ),
      ),
      bottomNavigationBar: r == null || (!r.can.decide && !r.can.cancel)
          ? null
          : _ActionBar(
              request: r,
              busy: _busy,
              onApprove: () => _approve(r),
              onReject: () => _reject(r),
              onCancel: () => _cancel(r),
            ),
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.request,
    required this.busy,
    required this.onApprove,
    required this.onReject,
    required this.onCancel,
  });
  final RequestDetail request;
  final bool busy;
  final VoidCallback onApprove;
  final VoidCallback onReject;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      elevation: 8,
      color: scheme.surface,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: ResponsiveCenter(
            child: Row(
              children: [
                if (request.can.cancel)
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const Key('request-cancel'),
                      onPressed: busy ? null : onCancel,
                      icon: const Icon(Icons.block),
                      label: const Text('Cancel'),
                    ),
                  ),
                if (request.can.cancel && request.can.decide) const SizedBox(width: 10),
                if (request.can.decide) ...[
                  Expanded(
                    child: OutlinedButton.icon(
                      key: const Key('request-reject'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: scheme.error,
                        side: BorderSide(color: scheme.error),
                      ),
                      onPressed: busy ? null : onReject,
                      icon: const Icon(Icons.close),
                      label: const Text('Reject'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton.icon(
                      key: const Key('request-approve'),
                      onPressed: busy ? null : onApprove,
                      icon: const Icon(Icons.check),
                      label: const Text('Approve'),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RequestBody extends ConsumerWidget {
  const _RequestBody({required this.request});
  final RequestDetail request;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final r = request;
    final theme = Theme.of(context);
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: [
        ResponsiveCenter(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          r.requestNo,
                          style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700),
                        ),
                      ),
                      Flexible(child: StatusBadge.status(label: r.statusLabel, statusTone: r.statusTone)),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Version ${r.version}',
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SizedBox(height: 12),
                  Text(r.purpose.isEmpty ? '(no purpose given)' : r.purpose, style: theme.textTheme.bodyLarge),
                  const Divider(height: 28),
                  LayoutBuilder(builder: (context, c) {
                    final two = c.maxWidth > 460;
                    final fields = [
                      InfoField(label: 'Project', value: r.project?.label ?? Formatters.dash, icon: Icons.apartment_outlined),
                      InfoField(label: 'Requested by', value: r.requester?.name ?? Formatters.dash, icon: Icons.person_outline),
                      InfoField(
                        label: 'Project manager',
                        value: r.projectManager?.name ?? Formatters.dash,
                        icon: Icons.engineering_outlined,
                      ),
                      InfoField(label: 'Needed on', value: f.date(r.neededOn), icon: Icons.event_outlined),
                      InfoField(
                        label: 'Submitted',
                        value: r.submittedAt == null ? 'Not submitted' : f.dateTime(r.submittedAt),
                        icon: Icons.send_outlined,
                      ),
                    ];
                    return Wrap(
                      runSpacing: 14,
                      children: [
                        for (final w in fields) SizedBox(width: two ? (c.maxWidth - 8) / 2 : c.maxWidth, child: w),
                      ],
                    );
                  }),
                ],
              ),
            ),
          ),
        ),
        ResponsiveCenter(child: SectionTitle('Items (${r.lines.length})')),
        for (final line in r.lines)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: ResponsiveCenter(child: _LineCard(line: line, formatters: f)),
          ),
        const ResponsiveCenter(child: SectionTitle('Approval history')),
        ResponsiveCenter(
          child: r.approvals.isEmpty
              ? Card(
                  child: ListTile(
                    leading: const Icon(Icons.hourglass_empty),
                    title: const Text('No decisions yet'),
                    subtitle: Text(r.status == RequestStatuses.draft ? 'This request is still a draft.' : 'Waiting for the first approval.'),
                  ),
                )
              : Card(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Column(
                      children: [
                        for (var i = 0; i < r.approvals.length; i++)
                          _TimelineEntry(
                            entry: r.approvals[i],
                            formatters: f,
                            isLast: i == r.approvals.length - 1,
                          ),
                      ],
                    ),
                  ),
                ),
        ),
      ],
    );
  }
}

class _LineCard extends StatelessWidget {
  const _LineCard({required this.line, required this.formatters});
  final RequestLine line;
  final Formatters formatters;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final f = formatters;
    final unit = line.item.unit;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(line.item.name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            Text(
              '${line.item.sku} · per $unit',
              style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _Qty(label: 'Requested', value: f.qty(line.requestedQty), unit: unit),
                _Qty(label: 'PM approved', value: f.qty(line.pmApprovedQty), unit: unit),
                _Qty(label: 'Final', value: f.qty(line.finalApprovedQty), unit: unit),
                _Qty(label: 'Issued', value: f.qty(line.issuedQty), unit: unit),
                _Qty(label: 'On hand', value: f.qty(line.onHand), unit: unit, tone: Tone.navy),
              ],
            ),
            if ((line.notes ?? '').isNotEmpty) ...[
              const SizedBox(height: 10),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.notes, size: 16, color: theme.colorScheme.onSurfaceVariant),
                  const SizedBox(width: 6),
                  Expanded(child: Text(line.notes!, style: theme.textTheme.bodyMedium)),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Qty extends StatelessWidget {
  const _Qty({required this.label, required this.value, required this.unit, this.tone = Tone.muted});
  final String label;
  final String value;
  final String unit;
  final Tone tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = ToneColors.of(tone, theme.brightness);
    final hasValue = value != Formatters.dash;
    return Semantics(
      label: '$label: ${hasValue ? '$value $unit' : 'none'}',
      excludeSemantics: true,
      child: Container(
        constraints: const BoxConstraints(minWidth: 88),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(color: c.background, borderRadius: BorderRadius.circular(8)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: theme.textTheme.labelSmall?.copyWith(color: c.foreground)),
            Text(
              value,
              style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, color: c.foreground),
            ),
          ],
        ),
      ),
    );
  }
}

class _TimelineEntry extends StatelessWidget {
  const _TimelineEntry({required this.entry, required this.formatters, required this.isLast});
  final ApprovalEntry entry;
  final Formatters formatters;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final approved = entry.decision == 'approved';
    final rejected = entry.decision == 'rejected';
    final c = ToneColors.of(
      approved ? Tone.approved : (rejected ? Tone.rejected : Tone.muted),
      theme.brightness,
    );
    final decisionText = approved ? 'Approved' : (rejected ? 'Rejected' : entry.decision);
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 52,
            child: Column(
              children: [
                const SizedBox(height: 10),
                CircleAvatar(
                  radius: 14,
                  backgroundColor: c.background,
                  child: Icon(approved ? Icons.check : (rejected ? Icons.close : Icons.circle_outlined),
                      size: 16, color: c.foreground),
                ),
                if (!isLast)
                  Expanded(child: Container(width: 2, color: theme.colorScheme.outlineVariant)),
              ],
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(0, 8, 16, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${entry.stepLabel}: $decisionText',
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    [
                      entry.approver?.name ?? 'Unknown',
                      if (entry.wasSubstitute) 'as substitute',
                      'v${entry.version}',
                      formatters.dateTime(entry.decidedAt),
                    ].join(' · '),
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  if ((entry.reason ?? '').isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text('"${entry.reason}"', style: theme.textTheme.bodyMedium?.copyWith(fontStyle: FontStyle.italic)),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
