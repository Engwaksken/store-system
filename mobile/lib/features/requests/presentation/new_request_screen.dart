import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/refs.dart';
import '../../../core/format/formatters.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../../stock/presentation/item_picker.dart';
import '../data/requests_repository.dart';
import '../domain/qty_validation.dart';
import '../domain/request_models.dart';

class _LineDraft {
  _LineDraft() : key = UniqueKey();
  final Key key;
  ItemRef? item;
  String? onHand;
  final qty = TextEditingController();
  final notes = TextEditingController();

  void dispose() {
    qty.dispose();
    notes.dispose();
  }
}

class NewRequestScreen extends ConsumerStatefulWidget {
  const NewRequestScreen({super.key});

  @override
  ConsumerState<NewRequestScreen> createState() => _NewRequestScreenState();
}

class _NewRequestScreenState extends ConsumerState<NewRequestScreen> {
  final _form = GlobalKey<FormState>();
  final _purpose = TextEditingController();
  CodedRef? _project;
  DateTime? _neededOn;
  final _lines = <_LineDraft>[_LineDraft()];
  bool _busy = false;
  ApiException? _error;
  bool _itemsMissing = false;
  bool _discarding = false;

  @override
  void dispose() {
    _purpose.dispose();
    for (final l in _lines) {
      l.dispose();
    }
    super.dispose();
  }

  bool get _dirty =>
      _project != null || _purpose.text.isNotEmpty || _lines.any((l) => l.item != null || l.qty.text.isNotEmpty);

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final picked = await showDatePicker(
      context: context,
      initialDate: _neededOn ?? today.add(const Duration(days: 3)),
      firstDate: today,
      lastDate: today.add(const Duration(days: 365)),
      helpText: 'Date needed on site',
    );
    if (picked != null) setState(() => _neededOn = picked);
  }

  Future<void> _pickItem(_LineDraft line) async {
    final item = await pickItem(context);
    if (item == null) return;
    setState(() {
      line.item = ItemRef(id: item.id, sku: item.sku, name: item.name, unit: item.unit);
      line.onHand = item.onHand;
      _itemsMissing = false;
    });
  }

  Future<void> _save({required bool submit}) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _error = null;
      _itemsMissing = _lines.any((l) => l.item == null);
    });
    final valid = _form.currentState!.validate();
    if (!valid || _itemsMissing) {
      showSnack(context, 'Please fix the highlighted fields.', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final created = await ref.read(requestsRepositoryProvider).create(
            projectId: _project!.id,
            purpose: _purpose.text,
            neededOn: Formatters.ymd(_neededOn!),
            submit: submit,
            lines: [
              for (final l in _lines)
                NewRequestLine(item: l.item, qty: normalizeDecimal(l.qty.text), notes: l.notes.text),
            ],
          );
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, submit ? '${created.requestNo} sent to the project manager' : '${created.requestNo} saved as draft');
      context.pushReplacement('/request/${created.id}');
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
    final projects = ref.watch(requestProjectOptionsProvider);
    final f = ref.watch(formattersProvider);
    final theme = Theme.of(context);

    return PopScope(
      canPop: !_dirty || _busy || _discarding,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final leave = await confirmDialog(
          context,
          title: 'Discard this request?',
          message: 'Your changes will be lost.',
          confirmLabel: 'Discard',
          destructive: true,
        );
        if (leave && context.mounted) {
          setState(() => _discarding = true);
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) Navigator.of(context).pop();
          });
        }
      },
      child: Scaffold(
        appBar: AppBar(title: const Text('New material request')),
        body: projects.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(requestProjectOptionsProvider)),
          data: (projectList) => Form(
            key: _form,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
              children: [
                ResponsiveCenter(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (projectList.isEmpty)
                        const Card(
                          child: ListTile(
                            leading: Icon(Icons.info_outline),
                            title: Text('No projects available'),
                            subtitle: Text('You are not assigned to any project you can request for.'),
                          ),
                        ),
                      DropdownButtonFormField<CodedRef>(
                        initialValue: _project,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText: 'Project',
                          prefixIcon: const Icon(Icons.apartment_outlined),
                          errorText: _error?.fieldError('project_id'),
                        ),
                        items: [
                          for (final p in projectList)
                            DropdownMenuItem(value: p, child: Text(p.label, overflow: TextOverflow.ellipsis)),
                        ],
                        onChanged: _busy ? null : (p) => setState(() => _project = p),
                        validator: (p) => p == null ? 'Choose a project' : null,
                      ),
                      const SizedBox(height: 14),
                      TextFormField(
                        controller: _purpose,
                        enabled: !_busy,
                        textCapitalization: TextCapitalization.sentences,
                        maxLength: 255,
                        decoration: InputDecoration(
                          labelText: 'Purpose',
                          hintText: 'e.g. Slab casting, 2nd floor',
                          prefixIcon: const Icon(Icons.short_text),
                          errorText: _error?.fieldError('purpose'),
                        ),
                        validator: (v) => (v ?? '').trim().isEmpty ? 'Enter the purpose' : null,
                      ),
                      const SizedBox(height: 4),
                      FormField<DateTime>(
                        validator: (_) => _neededOn == null ? 'Choose the date needed' : null,
                        builder: (field) => InkWell(
                          onTap: _busy ? null : _pickDate,
                          borderRadius: BorderRadius.circular(10),
                          child: InputDecorator(
                            decoration: InputDecoration(
                              labelText: 'Needed on',
                              prefixIcon: const Icon(Icons.event_outlined),
                              suffixIcon: const Icon(Icons.arrow_drop_down),
                              errorText: field.errorText ?? _error?.fieldError('needed_on'),
                            ),
                            child: Text(_neededOn == null ? 'Choose a date' : f.date(Formatters.ymd(_neededOn!))),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                ResponsiveCenter(
                  child: SectionTitle(
                    'Items',
                    trailing: TextButton.icon(
                      onPressed: _busy ? null : () => setState(() => _lines.add(_LineDraft())),
                      icon: const Icon(Icons.add),
                      label: const Text('Add item'),
                    ),
                  ),
                ),
                if (_error?.fieldError('lines') != null)
                  ResponsiveCenter(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text(_error!.fieldError('lines')!, style: TextStyle(color: theme.colorScheme.error)),
                    ),
                  ),
                for (var i = 0; i < _lines.length; i++)
                  Padding(
                    key: _lines[i].key,
                    padding: const EdgeInsets.only(bottom: 10),
                    child: ResponsiveCenter(child: _lineCard(i, f, theme)),
                  ),
              ],
            ),
          ),
        ),
        bottomNavigationBar: projects.hasValue
            ? SafeArea(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                  child: ResponsiveCenter(
                    child: Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: _busy ? null : () => _save(submit: false),
                            child: const Text('Save draft'),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: _busy ? null : () => _save(submit: true),
                            icon: _busy
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                  )
                                : const Icon(Icons.send),
                            label: const Text('Submit to PM'),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              )
            : null,
      ),
    );
  }

  Widget _lineCard(int i, Formatters f, ThemeData theme) {
    final line = _lines[i];
    final itemError = _error?.fieldError('lines.$i.item_id') ??
        (_itemsMissing && line.item == null ? 'Choose an item' : null);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 8, 8, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Text('Item ${i + 1}', style: theme.textTheme.labelLarge),
                const Spacer(),
                if (_lines.length > 1)
                  IconButton(
                    tooltip: 'Remove item ${i + 1}',
                    onPressed: _busy
                        ? null
                        : () => setState(() {
                              _lines.removeAt(i).dispose();
                            }),
                    icon: const Icon(Icons.delete_outline),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: InkWell(
                onTap: _busy ? null : () => _pickItem(line),
                borderRadius: BorderRadius.circular(10),
                child: InputDecorator(
                  decoration: InputDecoration(
                    labelText: 'Item',
                    prefixIcon: const Icon(Icons.inventory_2_outlined),
                    suffixIcon: const Icon(Icons.search),
                    errorText: itemError,
                  ),
                  child: Text(
                    line.item == null ? 'Search items' : '${line.item!.name} (${line.item!.sku})',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    flex: 2,
                    child: TextFormField(
                      controller: line.qty,
                      enabled: !_busy,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                      decoration: InputDecoration(
                        labelText: 'Quantity',
                        suffixText: line.item?.unit,
                        helperText: line.item == null ? null : 'On hand: ${f.qty(line.onHand)}',
                        errorText: _error?.fieldError('lines.$i.requested_qty'),
                      ),
                      validator: validateRequestedQty,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 3,
                    child: TextFormField(
                      controller: line.notes,
                      enabled: !_busy,
                      textCapitalization: TextCapitalization.sentences,
                      decoration: InputDecoration(
                        labelText: 'Notes (optional)',
                        errorText: _error?.fieldError('lines.$i.notes'),
                      ),
                    ),
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
