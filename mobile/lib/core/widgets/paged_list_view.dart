import 'dart:async';

import 'package:flutter/material.dart';

import '../api/paginated.dart';
import 'collapsible.dart';
import 'common.dart';

/// Lets a parent ask a [PagedListView] to reload the current page.
class PagedListController extends ChangeNotifier {
  void refresh() => notifyListeners();
}

/// Page-by-page list over a paginated endpoint, with a pager bar, pull-to-refresh,
/// skeleton loading, empty and error states.
///
/// Changing [filterKey] goes back to page 1; stale responses are ignored.
/// With [expandAllToggle] the header offers "Expand all / Collapse all" for the
/// [CollapsibleCard]s that [itemBuilder] returns.
class PagedListView<T> extends StatefulWidget {
  const PagedListView({
    super.key,
    required this.fetch,
    required this.itemBuilder,
    this.filterKey,
    this.controller,
    this.emptyTitle = 'Nothing here yet',
    this.emptyMessage,
    this.emptyIcon = Icons.inbox_outlined,
    this.padding = const EdgeInsets.fromLTRB(16, 4, 16, 16),
    this.separator = 8,
    this.expandAllToggle = false,
  });

  final Future<Paginated<T>> Function(int page) fetch;
  final Widget Function(BuildContext context, T item) itemBuilder;
  final Object? filterKey;
  final PagedListController? controller;
  final String emptyTitle;
  final String? emptyMessage;
  final IconData emptyIcon;
  final EdgeInsets padding;
  final double separator;
  final bool expandAllToggle;

  @override
  State<PagedListView<T>> createState() => PagedListViewState<T>();
}

class PagedListViewState<T> extends State<PagedListView<T>> {
  final _scroll = ScrollController();
  final _items = <T>[];
  int _page = 1;
  int _lastPage = 1;
  int _perPage = 0;
  int _total = 0;
  bool _loading = false;
  Object? _error; // error with nothing on screen
  int _generation = 0;
  bool _allExpanded = false;
  int _expandEpoch = 0;

  @override
  void initState() {
    super.initState();
    widget.controller?.addListener(refresh);
    unawaited(_load(1));
  }

  @override
  void didUpdateWidget(covariant PagedListView<T> oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller?.removeListener(refresh);
      widget.controller?.addListener(refresh);
    }
    if (oldWidget.filterKey != widget.filterKey) unawaited(_load(1, keepItems: false));
  }

  @override
  void dispose() {
    widget.controller?.removeListener(refresh);
    _scroll.dispose();
    super.dispose();
  }

  /// Reloads the current page, keeping its items visible meanwhile.
  Future<void> refresh() => _load(_page);

  /// Shows [page] (clamped to the known range).
  Future<void> goTo(int page) => _load(page.clamp(1, _lastPage));

  Future<void> _load(int page, {bool keepItems = true}) async {
    final gen = ++_generation;
    setState(() {
      _loading = true;
      _error = null;
      if (!keepItems) {
        _items.clear();
        _page = 1;
        _lastPage = 1;
      }
    });
    try {
      var res = await widget.fetch(page);
      // The page we were on may be gone after a refresh (records removed): fall back to the last one.
      if (res.data.isEmpty && page > 1 && res.meta.lastPage < page) {
        res = await widget.fetch(res.meta.lastPage < 1 ? 1 : res.meta.lastPage);
      }
      if (!mounted || gen != _generation) return;
      final changedPage = res.meta.currentPage != _page;
      setState(() {
        _items
          ..clear()
          ..addAll(res.data);
        _page = res.meta.currentPage;
        _lastPage = res.meta.lastPage < 1 ? 1 : res.meta.lastPage;
        _perPage = res.meta.perPage;
        _total = res.meta.total;
        _loading = false;
      });
      if (changedPage && _scroll.hasClients) _scroll.jumpTo(0);
    } catch (e) {
      if (!mounted || gen != _generation) return;
      setState(() {
        _loading = false;
        if (_items.isEmpty) {
          _error = e;
        } else {
          showSnack(context, 'Could not load. Check your connection.', error: true);
        }
      });
    }
  }

  void _toggleAll() => setState(() {
    _allExpanded = !_allExpanded;
    _expandEpoch++;
  });

  @override
  Widget build(BuildContext context) {
    if (_items.isEmpty && _loading) return const SkeletonList();
    if (_items.isEmpty && _error != null) return ErrorView(error: _error!, onRetry: () => _load(_page));
    if (_items.isEmpty) {
      return RefreshIndicator(
        onRefresh: refresh,
        child: EmptyView(title: widget.emptyTitle, message: widget.emptyMessage, icon: widget.emptyIcon),
      );
    }

    final first = _perPage > 0 ? (_page - 1) * _perPage + 1 : 1;
    final last = first + _items.length - 1;
    final list = ExpandAllScope(
      expanded: _allExpanded,
      epoch: _expandEpoch,
      child: ListView.separated(
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: widget.padding,
        itemCount: _items.length,
        separatorBuilder: (_, _) => SizedBox(height: widget.separator),
        // A fresh state per page, so an opened card does not carry over to another record.
        itemBuilder: (context, i) => KeyedSubtree(
          key: ValueKey('p$_page-$i'),
          child: ResponsiveCenter(child: widget.itemBuilder(context, _items[i])),
        ),
      ),
    );

    return Column(
      children: [
        _ListHeader(
          range: _total > 0 ? '$first–$last of $_total' : '${_items.length} shown',
          loading: _loading,
          expanded: widget.expandAllToggle ? _allExpanded : null,
          onToggle: _toggleAll,
        ),
        Expanded(
          child: Semantics(
            label: '$_total results, page $_page of $_lastPage',
            child: AnimatedOpacity(
              opacity: _loading ? 0.55 : 1,
              duration: const Duration(milliseconds: 150),
              child: RefreshIndicator(onRefresh: refresh, child: list),
            ),
          ),
        ),
        if (_lastPage > 1) Pager(page: _page, lastPage: _lastPage, onPage: _loading ? null : goTo),
      ],
    );
  }
}

class _ListHeader extends StatelessWidget {
  const _ListHeader({required this.range, required this.loading, required this.expanded, required this.onToggle});
  final String range;
  final bool loading;

  /// Null hides the expand/collapse-all button.
  final bool? expanded;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final open = expanded;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 8, 0),
          child: ResponsiveCenter(
            child: SizedBox(
              height: 36,
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      range,
                      style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                    ),
                  ),
                  if (open != null)
                    TextButton.icon(
                      style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
                      onPressed: onToggle,
                      icon: Icon(open ? Icons.unfold_less : Icons.unfold_more, size: 18),
                      label: Text(open ? 'Collapse all' : 'Expand all'),
                    ),
                ],
              ),
            ),
          ),
        ),
        SizedBox(height: 2, child: loading ? const LinearProgressIndicator(minHeight: 2) : null),
      ],
    );
  }
}

/// Bottom bar: first / previous, a window of page numbers, next / last.
class Pager extends StatelessWidget {
  const Pager({super.key, required this.page, required this.lastPage, required this.onPage});
  final int page;
  final int lastPage;

  /// Null while a page is loading (buttons disabled).
  final ValueChanged<int>? onPage;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final enabled = onPage != null;
    void go(int p) => onPage?.call(p);
    return Material(
      elevation: 3,
      color: theme.colorScheme.surface,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
          child: LayoutBuilder(
            builder: (context, c) {
              final window = c.maxWidth >= 480 ? 5 : 3;
              var start = page - window ~/ 2;
              if (start + window - 1 > lastPage) start = lastPage - window + 1;
              if (start < 1) start = 1;
              final end = (start + window - 1).clamp(1, lastPage);
              return Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  IconButton(
                    tooltip: 'First page',
                    onPressed: enabled && page > 1 ? () => go(1) : null,
                    icon: const Icon(Icons.first_page),
                  ),
                  IconButton(
                    tooltip: 'Previous page',
                    onPressed: enabled && page > 1 ? () => go(page - 1) : null,
                    icon: const Icon(Icons.chevron_left),
                  ),
                  for (var p = start; p <= end; p++)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 2),
                      child: SizedBox(
                        width: 40,
                        height: 36,
                        child: p == page
                            ? FilledButton(
                                style: FilledButton.styleFrom(
                                  padding: EdgeInsets.zero,
                                  disabledBackgroundColor: theme.colorScheme.primary,
                                  disabledForegroundColor: theme.colorScheme.onPrimary,
                                ),
                                onPressed: null,
                                child: Text('$p', semanticsLabel: 'Page $p, current'),
                              )
                            : TextButton(
                                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                                onPressed: enabled ? () => go(p) : null,
                                child: Text('$p', semanticsLabel: 'Page $p'),
                              ),
                      ),
                    ),
                  IconButton(
                    tooltip: 'Next page',
                    onPressed: enabled && page < lastPage ? () => go(page + 1) : null,
                    icon: const Icon(Icons.chevron_right),
                  ),
                  IconButton(
                    tooltip: 'Last page ($lastPage)',
                    onPressed: enabled && page < lastPage ? () => go(lastPage) : null,
                    icon: const Icon(Icons.last_page),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Search box that reports changes after the user pauses typing.
class SearchField extends StatefulWidget {
  const SearchField({super.key, required this.onChanged, this.hint = 'Search', this.initial = ''});
  final ValueChanged<String> onChanged;
  final String hint;
  final String initial;

  @override
  State<SearchField> createState() => _SearchFieldState();
}

class _SearchFieldState extends State<SearchField> {
  late final _text = TextEditingController(text: widget.initial);
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _text.dispose();
    super.dispose();
  }

  void _changed(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () => widget.onChanged(v.trim()));
    setState(() {});
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
        child: TextField(
          controller: _text,
          onChanged: _changed,
          textInputAction: TextInputAction.search,
          onSubmitted: (v) {
            _debounce?.cancel();
            widget.onChanged(v.trim());
          },
          decoration: InputDecoration(
            hintText: widget.hint,
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            suffixIcon: _text.text.isEmpty
                ? null
                : IconButton(
                    tooltip: 'Clear search',
                    icon: const Icon(Icons.clear),
                    onPressed: () {
                      _text.clear();
                      _debounce?.cancel();
                      widget.onChanged('');
                      setState(() {});
                    },
                  ),
          ),
        ),
      );
}
