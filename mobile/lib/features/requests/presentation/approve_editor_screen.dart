import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../data/requests_repository.dart';
import '../domain/qty_validation.dart';
import '../domain/request_models.dart';

enum ApproveResult { approved, conflict }

/// Lets the approver reduce each line's quantity (0..ceiling) before approving.
class ApproveEditorScreen extends ConsumerStatefulWidget {
  const ApproveEditorScreen({super.key, required this.request});
  final RequestDetail request;

  @override
  ConsumerState<ApproveEditorScreen> createState() => _ApproveEditorScreenState();
}

class _ApproveEditorScreenState extends ConsumerState<ApproveEditorScreen> {
  final _form = GlobalKey<FormState>();
  late final Map<int, TextEditingController> _controllers = {
    for (final l in widget.request.lines)
      l.id: TextEditingController(text: normalizeDecimal(widget.request.ceilingFor(l))),
  };
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    final r = widget.request;
    final qty = <int, String>{
      for (final l in r.lines) l.id: normalizeDecimal(_controllers[l.id]!.text),
    };
    if (qty.values.every((v) => decimalEquals(v, '0'))) {
      showSnack(context, 'All quantities are 0. Reject the request instead.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(requestsRepositoryProvider).decide(r.id, approve: true, version: r.version, qty: qty);
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      Navigator.of(context).pop(ApproveResult.approved);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.isConflict) {
        await showConflictDialog(context, e.message);
        if (mounted) Navigator.of(context).pop(ApproveResult.conflict);
        return;
      }
      setState(() => _error = e);
      if (!e.isValidation || e.fieldErrorsWithPrefix('qty.').isEmpty) {
        showSnack(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.request;
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);
    final stepText = switch (r.can.decisionStep) {
      'pm' => 'Project manager approval',
      'overall' => 'Final approval',
      _ => 'Approval',
    };
    return Scaffold(
      appBar: AppBar(title: Text('Approve ${r.requestNo}')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
          children: [
            ResponsiveCenter(
              child: Text(
                '$stepText. Each quantity starts at the most you may approve; reduce it if needed '
                '(0 approves none of that item).',
                style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ),
            const SizedBox(height: 12),
            for (final line in r.lines)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: ResponsiveCenter(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(line.item.name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                          Text(
                            'Requested ${f.qty(line.requestedQty)} · On hand ${f.qty(line.onHand)} ${line.item.unit}',
                            style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                          ),
                          const SizedBox(height: 10),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: TextFormField(
                                  key: Key('qty-${line.id}'),
                                  controller: _controllers[line.id],
                                  enabled: !_busy,
                                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                                  autovalidateMode: AutovalidateMode.onUserInteraction,
                                  decoration: InputDecoration(
                                    labelText: 'Approved qty (${line.item.unit})',
                                    helperText: 'Max ${f.qty(r.ceilingFor(line))}',
                                    errorText: _error?.fieldError('qty.${line.id}'),
                                  ),
                                  validator: (v) => validateApprovedQty(v, r.ceilingFor(line)),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Column(
                                  children: [
                                    TextButton(
                                      onPressed: _busy
                                          ? null
                                          : () => _controllers[line.id]!.text = normalizeDecimal(r.ceilingFor(line)),
                                      child: const Text('Max'),
                                    ),
                                    TextButton(
                                      onPressed: _busy ? null : () => _controllers[line.id]!.text = '0',
                                      child: const Text('None'),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            if (_error?.fieldError('qty') != null)
              ResponsiveCenter(
                child: Text(_error!.fieldError('qty')!, style: TextStyle(color: theme.colorScheme.error)),
              ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: ResponsiveCenter(
            child: FilledButton.icon(
              key: const Key('approve-confirm'),
              onPressed: _busy ? null : _submit,
              icon: _busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.check),
              label: const Text('Approve request'),
            ),
          ),
        ),
      ),
    );
  }
}
