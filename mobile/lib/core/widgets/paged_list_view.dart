import 'dart:async';

import 'package:flutter/material.dart';

import '../api/paginated.dart';
import 'common.dart';

/// Lets a parent ask a [PagedListView] to reload from page 1.
class PagedListController extends ChangeNotifier {
  void refresh() => notifyListeners();
}

/// Infinite-scroll list over a paginated endpoint, with pull-to-refresh,
/// skeleton loading, empty and error states.
///
/// Changing [filterKey] reloads from page 1; stale responses are ignored.
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
    this.padding = const EdgeInsets.fromLTRB(16, 8, 16, 96),
    this.separator = 10,
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

  @override
  State<PagedListView<T>> createState() => PagedListViewState<T>();
}

class PagedListViewState<T> extends State<PagedListView<T>> {
  final _scroll = ScrollController();
  final _items = <T>[];
  int _page = 0;
  int _total = 0;
  bool _hasMore = true;
  bool _loading = false;
  Object? _error; // first page error
  Object? _moreError; // next page error
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    widget.controller?.addListener(refresh);
    _loadFirst();
  }

  @override
  void didUpdateWidget(covariant PagedListView<T> oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller?.removeListener(refresh);
      widget.controller?.addListener(refresh);
    }
    if (oldWidget.filterKey != widget.filterKey) _loadFirst();
  }

  @override
  void dispose() {
    widget.controller?.removeListener(refresh);
    _scroll.dispose();
    super.dispose();
  }

  /// Reloads from the first page, keeping current items visible meanwhile.
  Future<void> refresh() => _loadFirst(keepItems: true);

  Future<void> _loadFirst({bool keepItems = false}) async {
    final gen = ++_generation;
    setState(() {
      _loading = true;
      _error = null;
      _moreError = null;
      if (!keepItems) {
        _items.clear();
        _page = 0;
        _hasMore = true;
      }
    });
    try {
      final res = await widget.fetch(1);
      if (!mounted || gen != _generation) return;
      setState(() {
        _items
          ..clear()
          ..addAll(res.data);
        _page = 1;
        _total = res.meta.total;
        _hasMore = res.meta.hasMore;
        _loading = false;
      });
    } catch (e) {
      if (!mounted || gen != _generation) return;
      setState(() {
        _loading = false;
        if (_items.isEmpty) {
          _error = e;
        } else {
          showSnack(context, 'Could not refresh. Check your connection.', error: true);
        }
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore || _page == 0) return;
    final gen = _generation;
    setState(() {
      _loading = true;
      _moreError = null;
    });
    try {
      final res = await widget.fetch(_page + 1);
      if (!mounted || gen != _generation) return;
      setState(() {
        _items.addAll(res.data);
        _page = res.meta.currentPage;
        _total = res.meta.total;
        _hasMore = res.meta.hasMore && res.data.isNotEmpty;
        _loading = false;
      });
    } catch (e) {
      if (!mounted || gen != _generation) return;
      setState(() {
        _loading = false;
        _moreError = e;
      });
    }
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final pos = _scroll.position;
    if (pos.pixels >= pos.maxScrollExtent - 400 && _moreError == null) unawaited(_loadMore());
  }

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (_items.isEmpty && _loading) {
      body = const SkeletonList();
    } else if (_items.isEmpty && _error != null) {
      body = ErrorView(error: _error!, onRetry: _loadFirst);
    } else if (_items.isEmpty) {
      body = EmptyView(title: widget.emptyTitle, message: widget.emptyMessage, icon: widget.emptyIcon);
    } else {
      final extra = (_hasMore || _moreError != null) ? 1 : 0;
      body = Semantics(
        label: '$_total results',
        child: ListView.separated(
          controller: _scroll,
          physics: const AlwaysScrollableScrollPhysics(),
          padding: widget.padding,
          itemCount: _items.length + extra,
          separatorBuilder: (_, _) => SizedBox(height: widget.separator),
          itemBuilder: (context, i) {
            if (i < _items.length) {
              return ResponsiveCenter(child: widget.itemBuilder(context, _items[i]));
            }
            if (_moreError != null) {
              return Center(
                child: TextButton.icon(
                  onPressed: _loadMore,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Could not load more. Tap to retry'),
                ),
              );
            }
            // Trigger loading when the footer becomes visible (short lists).
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted) unawaited(_loadMore());
            });
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          },
        ),
      );
    }
    return RefreshIndicator(onRefresh: refresh, child: body);
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
