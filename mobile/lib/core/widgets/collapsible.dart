import 'package:flutter/material.dart';

/// Lets a list open or close every [CollapsibleCard] below it at once.
///
/// [epoch] changes on every "expand all" / "collapse all" press, so a card that the
/// user toggled by hand is overridden again by the next press.
class ExpandAllScope extends InheritedWidget {
  const ExpandAllScope({super.key, required this.expanded, required this.epoch, required super.child});

  final bool expanded;
  final int epoch;

  static ExpandAllScope? maybeOf(BuildContext context) => context.dependOnInheritedWidgetOfExactType<ExpandAllScope>();

  @override
  bool updateShouldNotify(ExpandAllScope old) => old.epoch != epoch || old.expanded != expanded;
}

/// Shared open/closed state for [CollapsibleCard] and [CollapsibleSection].
///
/// With a [storageKey] the state survives rebuilds and refreshes through [PageStorage].
mixin _CollapsibleState<W extends StatefulWidget> on State<W> {
  bool expanded = false;
  int? _seenEpoch;
  bool _initialised = false;

  bool get initiallyExpanded;
  String? get storageKey;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final scope = ExpandAllScope.maybeOf(context);
    if (!_initialised) {
      _initialised = true;
      _seenEpoch = scope?.epoch;
      final stored = storageKey == null ? null : PageStorage.maybeOf(context)?.readState(context, identifier: storageKey);
      expanded = stored is bool ? stored : (scope != null && scope.epoch > 0 ? scope.expanded : initiallyExpanded);
      return;
    }
    if (scope != null && scope.epoch != _seenEpoch) {
      _seenEpoch = scope.epoch;
      expanded = scope.expanded;
    }
  }

  void toggle() {
    setState(() => expanded = !expanded);
    if (storageKey != null) PageStorage.maybeOf(context)?.writeState(context, expanded, identifier: storageKey);
  }
}

/// Round chevron button that turns when the content opens.
class ExpandChevron extends StatelessWidget {
  const ExpandChevron({super.key, required this.expanded, required this.onPressed, this.what = 'details'});
  final bool expanded;
  final VoidCallback onPressed;
  final String what;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: expanded ? 'Hide $what' : 'Show $what',
    visualDensity: VisualDensity.compact,
    onPressed: onPressed,
    icon: AnimatedRotation(
      turns: expanded ? 0.5 : 0,
      duration: const Duration(milliseconds: 200),
      child: const Icon(Icons.expand_more),
    ),
  );
}

/// List card with a always-visible [header] and a [body] that opens with the chevron.
///
/// Tapping the header runs [onTap] (usually: open the record); without [onTap] it toggles.
/// [footer] stays visible below the header, for actions that must not hide.
class CollapsibleCard extends StatefulWidget {
  const CollapsibleCard({
    super.key,
    required this.header,
    this.body,
    this.footer,
    this.summary,
    this.onTap,
    this.initiallyExpanded = false,
    this.storageKey,
    this.padding = const EdgeInsets.fromLTRB(14, 10, 4, 10),
    this.bodyPadding = const EdgeInsets.fromLTRB(14, 0, 14, 12),
  });

  final Widget header;
  final Widget? body;
  final Widget? footer;

  /// Shown in place of [body] while closed (e.g. a one-line digest).
  final Widget? summary;
  final VoidCallback? onTap;
  final bool initiallyExpanded;
  final String? storageKey;
  final EdgeInsets padding;
  final EdgeInsets bodyPadding;

  @override
  State<CollapsibleCard> createState() => _CollapsibleCardState();
}

class _CollapsibleCardState extends State<CollapsibleCard> with _CollapsibleState<CollapsibleCard> {
  @override
  bool get initiallyExpanded => widget.initiallyExpanded;
  @override
  String? get storageKey => widget.storageKey;

  @override
  Widget build(BuildContext context) {
    final hasBody = widget.body != null;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            onTap: widget.onTap ?? (hasBody ? toggle : null),
            child: Padding(
              padding: hasBody ? widget.padding : widget.padding.copyWith(right: 14),
              child: Row(
                children: [
                  Expanded(child: widget.header),
                  if (hasBody) ExpandChevron(expanded: expanded, onPressed: toggle),
                ],
              ),
            ),
          ),
          if (hasBody)
            AnimatedSize(
              duration: const Duration(milliseconds: 200),
              curve: Curves.easeOutCubic,
              alignment: Alignment.topCenter,
              child: expanded
                  ? InkWell(
                      onTap: widget.onTap,
                      child: Padding(padding: widget.bodyPadding, child: widget.body),
                    )
                  : widget.summary == null
                      ? const SizedBox(width: double.infinity)
                      : Padding(padding: widget.bodyPadding, child: widget.summary),
            ),
          if (widget.footer != null) widget.footer!,
        ],
      ),
    );
  }
}

/// Section heading that shows or hides its [child]; [summary] stays visible when closed.
class CollapsibleSection extends StatefulWidget {
  const CollapsibleSection({
    super.key,
    required this.title,
    required this.child,
    this.trailing,
    this.summary,
    this.initiallyExpanded = true,
    this.storageKey,
  });

  final String title;
  final Widget child;
  final Widget? trailing;
  final Widget? summary;
  final bool initiallyExpanded;
  final String? storageKey;

  @override
  State<CollapsibleSection> createState() => _CollapsibleSectionState();
}

class _CollapsibleSectionState extends State<CollapsibleSection> with _CollapsibleState<CollapsibleSection> {
  @override
  bool get initiallyExpanded => widget.initiallyExpanded;
  @override
  String? get storageKey => widget.storageKey;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 12, bottom: 4),
          child: Row(
            children: [
              Expanded(
                child: InkWell(
                  borderRadius: BorderRadius.circular(8),
                  onTap: toggle,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
                    child: Semantics(
                      header: true,
                      expanded: expanded,
                      child: Text(
                        widget.title,
                        style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, letterSpacing: 0.2),
                      ),
                    ),
                  ),
                ),
              ),
              ?widget.trailing,
              ExpandChevron(expanded: expanded, onPressed: toggle, what: widget.title.toLowerCase()),
            ],
          ),
        ),
        AnimatedSize(
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOutCubic,
          alignment: Alignment.topCenter,
          child: expanded ? widget.child : (widget.summary ?? const SizedBox(width: double.infinity)),
        ),
      ],
    );
  }
}
