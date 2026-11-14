import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/api/refs.dart';
import '../../../core/format/formatters.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_controller.dart';
import '../../requests/domain/qty_validation.dart';
import '../data/claims_repository.dart';

enum _ReceiptSource { camera, gallery, file }

class NewClaimScreen extends ConsumerStatefulWidget {
  const NewClaimScreen({super.key});

  @override
  ConsumerState<NewClaimScreen> createState() => _NewClaimScreenState();
}

class _NewClaimScreenState extends ConsumerState<NewClaimScreen> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _description = TextEditingController();
  CodedRef? _project;
  NamedRef? _category;
  CodedRef? _costCode;
  DateTime _incurredOn = DateUtils.dateOnly(DateTime.now());
  ReceiptFile? _receipt;
  String? _receiptError;
  bool _busy = false;
  double? _progress;
  ApiException? _error;
  CancelToken? _cancel;

  @override
  void dispose() {
    _cancel?.cancel();
    _amount.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final today = DateUtils.dateOnly(DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: _incurredOn,
      firstDate: today.subtract(const Duration(days: 365)),
      lastDate: today,
      helpText: 'Date the expense was incurred',
    );
    if (picked != null) setState(() => _incurredOn = picked);
  }

  Future<void> _chooseReceipt() async {
    final source = await showModalBottomSheet<_ReceiptSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Take a photo'),
              onTap: () => Navigator.pop(context, _ReceiptSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Choose from gallery'),
              onTap: () => Navigator.pop(context, _ReceiptSource.gallery),
            ),
            ListTile(
              leading: const Icon(Icons.picture_as_pdf_outlined),
              title: const Text('Choose a file (PDF, JPG, PNG)'),
              onTap: () => Navigator.pop(context, _ReceiptSource.file),
            ),
          ],
        ),
      ),
    );
    if (source == null) return;
    try {
      ReceiptFile? picked;
      if (source == _ReceiptSource.file) {
        final file = await FilePicker.pickFile(
          type: FileType.custom,
          allowedExtensions: ReceiptFile.allowedExtensions,
        );
        if (file == null || file.path == null) return;
        picked = ReceiptFile(path: file.path!, name: file.name, size: file.lengthSync() ?? await file.length() ?? 0);
      } else {
        final image = await ImagePicker().pickImage(
          source: source == _ReceiptSource.camera ? ImageSource.camera : ImageSource.gallery,
          imageQuality: 80,
          maxWidth: 2400,
          maxHeight: 2400,
        );
        if (image == null) return;
        var name = image.name;
        if (!name.contains('.')) name = '$name.jpg';
        picked = ReceiptFile(path: image.path, name: name, size: await image.length());
      }
      setState(() {
        _receipt = picked;
        _receiptError = picked!.validate();
      });
    } on PlatformException catch (e) {
      if (mounted) {
        showSnack(
          context,
          e.code.contains('denied') ? 'Permission denied. Allow access in your phone settings.' : 'Could not open the picker.',
          error: true,
        );
      }
    }
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() => _error = null);
    final valid = _form.currentState!.validate();
    if (!valid || _receiptError != null) {
      showSnack(context, 'Please fix the highlighted fields.', error: true);
      return;
    }
    setState(() {
      _busy = true;
      _progress = _receipt == null ? null : 0;
      _cancel = CancelToken();
    });
    try {
      final claim = await ref.read(claimsRepositoryProvider).create(
            projectId: _project!.id,
            categoryId: _category!.id,
            costCodeId: _costCode?.id,
            incurredOn: Formatters.ymd(_incurredOn),
            amount: normalizeDecimal(_amount.text),
            description: _description.text,
            receipt: _receipt,
            cancelToken: _cancel,
            onProgress: (p) {
              if (mounted) setState(() => _progress = p);
            },
          );
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, '${claim.claimNo} submitted');
      context.pushReplacement('/claims/${claim.id}');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        if (e.fieldError('receipt') != null) _receiptError = e.fieldError('receipt');
      });
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final options = ref.watch(claimOptionsProvider);
    final user = ref.watch(currentUserProvider);
    final f = ref.watch(formattersProvider);
    final decimals = user?.currencyDecimals ?? 0;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('New expense claim')),
      body: options.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(claimOptionsProvider)),
        data: (o) => Form(
          key: _form,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              ResponsiveCenter(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    DropdownButtonFormField<CodedRef>(
                      initialValue: _project,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: 'Project',
                        prefixIcon: const Icon(Icons.apartment_outlined),
                        errorText: _error?.fieldError('project_id'),
                      ),
                      items: [
                        for (final p in o.projects)
                          DropdownMenuItem(value: p, child: Text(p.label, overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: _busy ? null : (v) => setState(() => _project = v),
                      validator: (v) => v == null ? 'Choose a project' : null,
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<NamedRef>(
                      initialValue: _category,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: 'Category',
                        prefixIcon: const Icon(Icons.category_outlined),
                        errorText: _error?.fieldError('category_id'),
                      ),
                      items: [for (final c in o.categories) DropdownMenuItem(value: c, child: Text(c.name))],
                      onChanged: _busy ? null : (v) => setState(() => _category = v),
                      validator: (v) => v == null ? 'Choose a category' : null,
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<CodedRef?>(
                      initialValue: _costCode,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: 'Cost code (optional)',
                        prefixIcon: const Icon(Icons.tag),
                        errorText: _error?.fieldError('cost_code_id'),
                      ),
                      items: [
                        const DropdownMenuItem<CodedRef?>(value: null, child: Text('None')),
                        for (final c in o.costCodes)
                          DropdownMenuItem<CodedRef?>(value: c, child: Text(c.label, overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: _busy ? null : (v) => setState(() => _costCode = v),
                    ),
                    const SizedBox(height: 14),
                    InkWell(
                      onTap: _busy ? null : _pickDate,
                      borderRadius: BorderRadius.circular(10),
                      child: InputDecorator(
                        decoration: InputDecoration(
                          labelText: 'Date incurred',
                          prefixIcon: const Icon(Icons.event_outlined),
                          suffixIcon: const Icon(Icons.arrow_drop_down),
                          errorText: _error?.fieldError('incurred_on'),
                        ),
                        child: Text(f.date(Formatters.ymd(_incurredOn))),
                      ),
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _amount,
                      enabled: !_busy,
                      keyboardType: TextInputType.numberWithOptions(decimal: decimals > 0),
                      inputFormatters: [
                        FilteringTextInputFormatter.allow(decimals > 0 ? RegExp(r'[0-9.,]') : RegExp(r'[0-9,]')),
                      ],
                      decoration: InputDecoration(
                        labelText: 'Amount',
                        prefixText: user?.currency.isNotEmpty ?? false ? '${user!.currency} ' : null,
                        prefixIcon: const Icon(Icons.payments_outlined),
                        errorText: _error?.fieldError('amount'),
                      ),
                      validator: (v) => validateAmount(v, decimals: decimals),
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _description,
                      enabled: !_busy,
                      minLines: 2,
                      maxLines: 5,
                      maxLength: 1000,
                      textCapitalization: TextCapitalization.sentences,
                      decoration: InputDecoration(
                        labelText: 'Description',
                        alignLabelWithHint: true,
                        errorText: _error?.fieldError('description'),
                      ),
                      validator: (v) => (v ?? '').trim().isEmpty ? 'Describe the expense' : null,
                    ),
                    const SectionTitle('Receipt'),
                    Card(
                      child: _receipt == null
                          ? ListTile(
                              leading: const Icon(Icons.add_a_photo_outlined),
                              title: const Text('Attach receipt (optional)'),
                              subtitle: const Text('Camera, gallery or PDF, up to 4 MB'),
                              onTap: _busy ? null : _chooseReceipt,
                            )
                          : ListTile(
                              leading: Icon(_receipt!.isImage ? Icons.image_outlined : Icons.picture_as_pdf_outlined),
                              title: Text(_receipt!.name, overflow: TextOverflow.ellipsis),
                              subtitle: Text(
                                _receiptError ?? _formatSize(_receipt!.size),
                                style: _receiptError == null ? null : TextStyle(color: theme.colorScheme.error),
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  IconButton(
                                    tooltip: 'Replace receipt',
                                    onPressed: _busy ? null : _chooseReceipt,
                                    icon: const Icon(Icons.swap_horiz),
                                  ),
                                  IconButton(
                                    tooltip: 'Remove receipt',
                                    onPressed: _busy
                                        ? null
                                        : () => setState(() {
                                              _receipt = null;
                                              _receiptError = null;
                                            }),
                                    icon: const Icon(Icons.delete_outline),
                                  ),
                                ],
                              ),
                            ),
                    ),
                    if (_busy && _progress != null) ...[
                      const SizedBox(height: 16),
                      Semantics(
                        label: 'Uploading ${(_progress! * 100).round()} percent',
                        child: LinearProgressIndicator(value: _progress, minHeight: 6),
                      ),
                      const SizedBox(height: 6),
                      Text('Uploading… ${(_progress! * 100).round()}%', style: theme.textTheme.bodySmall),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: options.hasValue
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: ResponsiveCenter(
                  child: FilledButton.icon(
                    onPressed: _busy ? null : _submit,
                    icon: _busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.send),
                    label: const Text('Submit claim'),
                  ),
                ),
              ),
            )
          : null,
    );
  }

  static String _formatSize(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}
