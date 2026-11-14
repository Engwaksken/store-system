import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/formatters.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/claims_repository.dart';
import '../domain/claim_models.dart';

class ClaimDetailScreen extends ConsumerStatefulWidget {
  const ClaimDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<ClaimDetailScreen> createState() => _ClaimDetailScreenState();
}

class _ClaimDetailScreenState extends ConsumerState<ClaimDetailScreen> {
  bool _busy = false;
  bool _downloading = false;

  void _reload() => ref.invalidate(claimDetailProvider(widget.id));

  Future<void> _decide(Claim c, {required bool approve}) async {
    String? reason;
    if (approve) {
      final ok = await confirmDialog(
        context,
        title: 'Approve ${c.claimNo}?',
        message: '${ref.read(formattersProvider).money(c.amount)} for ${c.claimant?.name ?? 'the claimant'}.',
        confirmLabel: 'Approve',
      );
      if (!ok) return;
    } else {
      reason = await askReason(context, title: 'Reject ${c.claimNo}');
      if (reason == null) return;
    }
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(claimsRepositoryProvider).decide(c.id, approve: approve, reason: reason, seenAt: c.updatedAt);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, '${c.claimNo} ${approve ? 'approved' : 'rejected'}');
    } catch (e) {
      if (!mounted) return;
      if (e is ApiException && e.isConflict) {
        await showConflictDialog(context, e.message);
        ref.read(dataRevisionProvider.notifier).bump();
      } else {
        showSnack(context, ApiException.messageOf(e), error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openReceipt(Claim c) async {
    final url = c.receiptUrl ?? '/claims/${c.id}/receipt';
    setState(() => _downloading = true);
    try {
      final file = await ref.read(claimsRepositoryProvider).receipt(url);
      if (!mounted) return;
      if (file.isImage) {
        await Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => _ImageViewer(title: 'Receipt ${c.claimNo}', file: file),
        ));
      } else {
        final dir = await getTemporaryDirectory();
        final ext = file.isPdf ? 'pdf' : (file.fileName?.split('.').last ?? 'bin');
        final path = '${dir.path}${Platform.pathSeparator}receipt-${c.id}.$ext';
        await File(path).writeAsBytes(file.bytes, flush: true);
        final result = await OpenFilex.open(path, type: file.contentType.split(';').first);
        if (result.type != ResultType.done && mounted) {
          showSnack(context, 'No app found to open this receipt (${result.message}).', error: true);
        }
      }
    } catch (e) {
      if (mounted) showSnack(context, ApiException.messageOf(e), error: true);
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(claimDetailProvider(widget.id));
    final c = async.value;
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.claimNo ?? 'Claim'),
        actions: [IconButton(tooltip: 'Reload', onPressed: _reload, icon: const Icon(Icons.refresh))],
        bottom: _busy || _downloading
            ? const PreferredSize(preferredSize: Size.fromHeight(3), child: LinearProgressIndicator(minHeight: 3))
            : null,
      ),
      body: async.when(
        skipLoadingOnRefresh: true,
        skipLoadingOnReload: true,
        loading: () => const SkeletonList(count: 2),
        error: (e, _) => ErrorView(error: e, onRetry: _reload),
        data: (c) => RefreshIndicator(
          onRefresh: () => ref.refresh(claimDetailProvider(widget.id).future),
          child: _ClaimBody(
            claim: c,
            downloading: _downloading,
            onOpenReceipt: () => _openReceipt(c),
          ),
        ),
      ),
      bottomNavigationBar: c == null || !c.can.decide
          ? null
          : Material(
              elevation: 8,
              color: scheme.surface,
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
                  child: ResponsiveCenter(
                    child: Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor: scheme.error,
                              side: BorderSide(color: scheme.error),
                            ),
                            onPressed: _busy ? null : () => _decide(c, approve: false),
                            icon: const Icon(Icons.close),
                            label: const Text('Reject'),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _busy ? null : () => _decide(c, approve: true),
                            icon: const Icon(Icons.check),
                            label: const Text('Approve'),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
    );
  }
}

class _ClaimBody extends ConsumerWidget {
  const _ClaimBody({required this.claim, required this.downloading, required this.onOpenReceipt});
  final Claim claim;
  final bool downloading;
  final VoidCallback onOpenReceipt;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    final c = claim;
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
                    children: [
                      Expanded(
                        child: Text(c.claimNo, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                      ),
                      Flexible(child: StatusBadge.status(label: c.statusLabel, statusTone: c.statusTone)),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Semantics(
                    label: 'Amount ${f.money(c.amount)}',
                    excludeSemantics: true,
                    child: Text(
                      f.money(c.amount),
                      style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(c.description, style: theme.textTheme.bodyLarge),
                  const Divider(height: 28),
                  InfoField(label: 'Project', value: c.project?.label ?? Formatters.dash, icon: Icons.apartment_outlined),
                  const SizedBox(height: 12),
                  InfoField(label: 'Claimant', value: c.claimant?.name ?? Formatters.dash, icon: Icons.person_outline),
                  const SizedBox(height: 12),
                  InfoField(label: 'Category', value: c.category?.name ?? Formatters.dash, icon: Icons.category_outlined),
                  const SizedBox(height: 12),
                  InfoField(label: 'Cost code', value: c.costCode?.label ?? 'None', icon: Icons.tag),
                  const SizedBox(height: 12),
                  InfoField(label: 'Date incurred', value: f.date(c.incurredOn), icon: Icons.event_outlined),
                  const SizedBox(height: 12),
                  InfoField(label: 'Last updated', value: f.dateTime(c.updatedAt), icon: Icons.update),
                ],
              ),
            ),
          ),
        ),
        if ((c.decisionReason ?? '').isNotEmpty) ...[
          const ResponsiveCenter(child: SectionTitle('Decision note')),
          ResponsiveCenter(
            child: Card(
              child: ListTile(leading: const Icon(Icons.comment_outlined), title: Text(c.decisionReason!)),
            ),
          ),
        ],
        const ResponsiveCenter(child: SectionTitle('Receipt')),
        ResponsiveCenter(
          child: Card(
            child: c.hasReceipt
                ? ListTile(
                    leading: const Icon(Icons.receipt_long_outlined),
                    title: const Text('View receipt'),
                    subtitle: const Text('Opens an image preview, or a PDF in another app'),
                    trailing: downloading
                        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.open_in_new),
                    onTap: downloading ? null : onOpenReceipt,
                  )
                : const ListTile(
                    leading: Icon(Icons.do_not_disturb_alt_outlined),
                    title: Text('No receipt attached'),
                  ),
          ),
        ),
      ],
    );
  }
}

class _ImageViewer extends StatelessWidget {
  const _ImageViewer({required this.title, required this.file});
  final String title;
  final DownloadedFile file;

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(title: Text(title), backgroundColor: Colors.black, foregroundColor: Colors.white),
        body: InteractiveViewer(
          maxScale: 6,
          child: Center(
            child: Image.memory(
              file.bytes,
              semanticLabel: title,
              filterQuality: FilterQuality.medium,
              errorBuilder: (_, _, _) => const Text('Could not display this image', style: TextStyle(color: Colors.white)),
            ),
          ),
        ),
      );
}
