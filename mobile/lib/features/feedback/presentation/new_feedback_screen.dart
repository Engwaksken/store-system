import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/config/branding.dart';
import '../../../core/util/data_revision.dart';
import '../../../core/widgets/common.dart';
import '../../auth/data/auth_repository.dart';
import '../../../core/config/app_info.dart';
import '../data/feedback_repository.dart';
import '../domain/feedback_models.dart';

class NewFeedbackScreen extends ConsumerStatefulWidget {
  const NewFeedbackScreen({super.key});

  @override
  ConsumerState<NewFeedbackScreen> createState() => _NewFeedbackScreenState();
}

class _NewFeedbackScreenState extends ConsumerState<NewFeedbackScreen> {
  final _form = GlobalKey<FormState>();
  final _subject = TextEditingController();
  final _message = TextEditingController();
  String _category = 'suggestion';
  int? _rating;
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      // Version and device help the admins reproduce problems; both are optional.
      String? version;
      String? device;
      try {
        final info = await ref.read(packageInfoProvider.future);
        version = '${info.version}+${info.buildNumber}';
      } catch (_) {}
      try {
        device = await ref.read(deviceNameProvider.future);
      } catch (_) {}
      final item = await ref.read(feedbackRepositoryProvider).send(
            category: _category,
            subject: _subject.text,
            message: _message.text,
            rating: _rating,
            appVersion: version,
            device: device,
          );
      if (!mounted) return;
      ref.read(dataRevisionProvider.notifier).bump();
      showSnack(context, 'Thank you. Your feedback was sent.');
      context.pushReplacement('/feedback/${item.id}');
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
    final appName = ref.watch(appNameProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Send feedback')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ResponsiveCenter(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Report a problem, ask a question or suggest an improvement to $appName. '
                    'The administrators are notified and you are told when they reply.',
                    style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                  const SectionTitle('Category'),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final c in options.categories)
                        ChoiceChip(
                          label: Text(c.label),
                          selected: _category == c.value,
                          onSelected: _busy ? null : (_) => setState(() => _category = c.value),
                        ),
                    ],
                  ),
                  if (_error?.fieldError('category') != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(_error!.fieldError('category')!, style: TextStyle(color: theme.colorScheme.error)),
                    ),
                  const SectionTitle('How is the app working for you? (optional)'),
                  _StarRating(
                    value: _rating,
                    onChanged: _busy ? null : (v) => setState(() => _rating = v == _rating ? null : v),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _subject,
                    enabled: !_busy,
                    maxLength: 150,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: InputDecoration(
                      labelText: 'Subject',
                      hintText: 'e.g. Approvals screen is slow',
                      errorText: _error?.fieldError('subject'),
                    ),
                    validator: (v) => (v ?? '').trim().isEmpty ? 'Enter a short subject' : null,
                  ),
                  const SizedBox(height: 8),
                  TextFormField(
                    controller: _message,
                    enabled: !_busy,
                    minLines: 4,
                    maxLines: 10,
                    maxLength: 5000,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: InputDecoration(
                      labelText: 'Details',
                      hintText: 'What happened, where, and what you expected.',
                      alignLabelWithHint: true,
                      errorText: _error?.fieldError('message'),
                    ),
                    validator: (v) => (v ?? '').trim().isEmpty ? 'Describe your feedback' : null,
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: _busy ? null : _submit,
                    icon: _busy
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.send_outlined),
                    label: const Text('Send feedback'),
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

class _StarRating extends StatelessWidget {
  const _StarRating({required this.value, required this.onChanged});
  final int? value;
  final ValueChanged<int>? onChanged;

  @override
  Widget build(BuildContext context) {
    final v = value ?? 0;
    return Row(
      children: [
        for (var i = 1; i <= 5; i++)
          IconButton(
            tooltip: '$i star${i == 1 ? '' : 's'}: ${ratingLabels[i]}',
            onPressed: onChanged == null ? null : () => onChanged!(i),
            icon: Icon(i <= v ? Icons.star_rounded : Icons.star_outline_rounded,
                size: 32, color: i <= v ? Colors.amber.shade700 : null),
          ),
        const SizedBox(width: 8),
        if (value != null) Flexible(child: Text(ratingLabels[value] ?? '')),
      ],
    );
  }
}
