part of 'fake_backend.dart';

/// In-memory store operations and purchasing for fake mode (same shapes as the server, simplified rules):
/// issuing with automatic restock requisitions, vouchers, receipts, adjustments, transfers, returns, PRs and POs.
class _FakeStoreOps {
  _FakeStoreOps(this.b);
  final FakeBackend b;

  final issues = <Map<String, dynamic>>[];
  final receipts = <Map<String, dynamic>>[];
  final transfers = <Map<String, dynamic>>[];
  final returns = <Map<String, dynamic>>[];
  final prs = <Map<String, dynamic>>[];
  final pos = <Map<String, dynamic>>[];
  int _seq = 1;
  final _keys = <String, Map<String, dynamic>>{};

  static const _forbidden = FakeResponse(403, {'message': 'This action is unauthorized.'});
  static const _notFound = FakeResponse(404, {'message': 'Not found.'});

  Map<String, dynamic> _store(int id) => b._stores.firstWhere((s) => s['id'] == id, orElse: () => b._stores.first);
  Map<String, dynamic> _item(int id) => b._items.firstWhere((i) => i['id'] == id);
  Map<String, dynamic> _itemRef(int id) {
    final i = _item(id);
    return {'id': i['id'], 'sku': i['sku'], 'name': i['name'], 'unit': i['unit']};
  }

  Map<String, dynamic> _person(int userId) => {'id': userId, 'name': b._users[userId]!['name']};
  bool _values(int userId) => b._can(userId, 'view_stock_value');
  Decimal _onHand(int itemId, int storeId) => b._balances[itemId]?[storeId] ?? Decimal.zero;
  void _move(int itemId, int storeId, Decimal delta) => (b._balances[itemId] ??= {})[storeId] = _onHand(itemId, storeId) + delta;
  Decimal _d(Object? v) => Decimal.tryParse('${v ?? ''}'.trim()) ?? Decimal.zero;
  String _no(String prefix) => '$prefix-${b._now.year}-${(_seq++).toString().padLeft(4, '0')}';

  Map<String, dynamic>? _find(List<Map<String, dynamic>> list, String id) => list.where((x) => '${x['id']}' == id).firstOrNull;

  FakeResponse? handle(String method, List<String> segs, int userId, Map<String, dynamic> query, Map<String, dynamic> json) {
    switch ((method, segs)) {
      case ('GET', ['store-ops', 'options']):
        return FakeResponse(200, {'operable_stores': b._stores, 'active_stores': b._stores});
      case ('GET', ['issues', 'queue']):
        if (!b._can(userId, 'issue_stock')) return _forbidden;
        final q = b._requests.where((r) => ['overall_approved', 'partially_issued'].contains(r['status'])).map(b._requestSummary).toList();
        return FakeResponse(200, b._paginate(q, query));
      case ('GET', ['issues']):
        final term = '${query['q'] ?? ''}'.toLowerCase();
        final list = issues.reversed
            .where((i) => term.isEmpty || '${i['issue_no']} ${i['recipient_name']}'.toLowerCase().contains(term))
            .where((i) => '${query['unacknowledged']}' != '1' || i['acknowledgment'] == null)
            .map((i) => _issueJson(i, userId, detail: false))
            .toList();
        return FakeResponse(200, b._paginate(list, query));
      case ('GET', ['issues', final id]):
        final i = _find(issues, id);
        return i == null ? _notFound : FakeResponse(200, {'data': _issueJson(i, userId)});
      case ('POST', ['issues', final id, 'acknowledge']):
        final i = _find(issues, id);
        if (i == null) return _notFound;
        if (i['acknowledgment'] != null) return const FakeResponse(422, {'message': 'Already acknowledged.'});
        i['acknowledgment'] = {
          'recipient_name': i['recipient_name'],
          'remarks': json['remarks'],
          'by': _person(userId),
          'at': FakeBackend._iso(DateTime.now()),
        };
        return FakeResponse(200, {'data': _issueJson(i, userId)});
      case ('POST', ['issues', final id, 'return']):
        final i = _find(issues, id);
        return i == null ? _notFound : _recordReturn(i, userId, json);
      case ('GET', ['requests', final id, 'issue']):
        if (!b._can(userId, 'issue_stock')) return _forbidden;
        return b._withRequest(id, (r) => FakeResponse(200, {'data': _issueForm(r, int.tryParse('${query['store_id'] ?? ''}') ?? 1)}));
      case ('POST', ['requests', final id, 'issue']):
        if (!b._can(userId, 'issue_stock')) return _forbidden;
        return b._withRequest(id, (r) => _issue(r, userId, json));
      case ('POST', ['requests', final id, 'restock']):
        if (!b._can(userId, 'issue_stock')) return _forbidden;
        return b._withRequest(id, (r) {
          final pr = _restock(r, userId, (json['store_id'] as int?) ?? 1);
          return FakeResponse(pr == null ? 200 : 201, {
            'data': pr == null ? null : {'id': pr['id'], 'pr_no': pr['pr_no']},
            'message': pr == null ? 'Nothing to restock.' : 'Restock requisition ${pr['pr_no']} sent to the Overall Manager for approval.',
          });
        });
      case ('GET', ['receipts']):
        return FakeResponse(200, b._paginate(receipts.reversed.map((r) => _valued(r, userId, summary: true)).toList(), query));
      case ('GET', ['receipts', final id]):
        final r = _find(receipts, id);
        return r == null ? _notFound : FakeResponse(200, {'data': _valued(r, userId)});
      case ('POST', ['receipts']):
        return _receive(userId, json);
      case ('POST', ['adjustments']):
        final itemId = json['item_id'] as int?;
        final storeId = (json['store_id'] as int?) ?? 1;
        final delta = _d(json['qty_delta']);
        if (itemId == null || delta == Decimal.zero) {
          throw _Validation({
            'qty_delta': ['Enter a quantity.'],
          });
        }
        if ('${json['reason'] ?? ''}'.trim().length < 5) {
          throw _Validation({
            'reason': ['The reason field must be at least 5 characters.'],
          });
        }
        if (_onHand(itemId, storeId) + delta < Decimal.zero) {
          return const FakeResponse(422, {'message': 'Stock cannot go below zero.'});
        }
        _move(itemId, storeId, delta);
        return FakeResponse(201, {
          'data': {'id': _seq, 'adjustment_no': _no('ADJ'), 'qty_delta': FakeBackend._fmtQty(delta), 'on_hand': FakeBackend._fmtQty(_onHand(itemId, storeId))},
        });
      case ('GET', ['transfers']):
        final st = query['status'];
        return FakeResponse(
          200,
          b._paginate(transfers.reversed.where((t) => st == null || t['status'] == st).map((t) => _transferJson(t, userId)).toList(), query),
        );
      case ('GET', ['transfers', final id]):
        final t = _find(transfers, id);
        return t == null ? _notFound : FakeResponse(200, {'data': _transferJson(t, userId)});
      case ('POST', ['transfers']):
        return _dispatch(userId, json);
      case ('POST', ['transfers', final id, 'receive']):
        final t = _find(transfers, id);
        if (t == null) return _notFound;
        if (t['status'] != 'in_transit') return const FakeResponse(422, {'message': 'This transfer is closed.'});
        final got = (json['received'] as Map?) ?? {};
        for (final l in (t['lines'] as List).cast<Map<String, dynamic>>()) {
          final q = _d(got['${l['id']}']);
          l['received_qty'] = FakeBackend._fmtQty(q);
          l['shortfall_reason'] = ((json['reason'] as Map?) ?? {})['${l['id']}'];
          _move(l['item']['id'] as int, t['to_store']['id'] as int, q);
        }
        t
          ..['status'] = 'received'
          ..['status_label'] = 'Received'
          ..['status_tone'] = 'approved'
          ..['receiver'] = _person(userId)
          ..['received_at'] = FakeBackend._iso(DateTime.now());
        return FakeResponse(200, {'data': _transferJson(t, userId)});
      case ('POST', ['transfers', final id, 'cancel']):
        final t = _find(transfers, id);
        if (t == null) return _notFound;
        for (final l in (t['lines'] as List).cast<Map<String, dynamic>>()) {
          _move(l['item']['id'] as int, t['from_store']['id'] as int, _d(l['qty']));
        }
        t
          ..['status'] = 'cancelled'
          ..['status_label'] = 'Cancelled'
          ..['status_tone'] = 'muted';
        return FakeResponse(200, {'data': _transferJson(t, userId)});
      case ('GET', ['returns']):
        return FakeResponse(200, b._paginate(returns.reversed.map((r) => _valued(r, userId, summary: true)).toList(), query));
      case ('GET', ['returns', final id]):
        final r = _find(returns, id);
        return r == null ? _notFound : FakeResponse(200, {'data': _valued(r, userId)});
      case ('GET', ['purchase-requisitions']):
        final st = query['status'];
        final restock = '${query['restock']}' == '1';
        return FakeResponse(
          200,
          b._paginate(
            prs.reversed.where((p) => (st == null || p['status'] == st) && (!restock || p['material_request'] != null)).map((p) => _prJson(p, userId)).toList(),
            query,
          ),
        );
      case ('GET', ['purchase-requisitions', final id]):
        final p = _find(prs, id);
        return p == null ? _notFound : FakeResponse(200, {'data': _prJson(p, userId)});
      case ('POST', ['purchase-requisitions', final id, 'decision']):
        final p = _find(prs, id);
        if (p == null) return _notFound;
        if (!b._can(userId, 'decide_purchasing') || p['status'] != 'submitted') return _forbidden;
        final approve = json['decision'] == 'approved';
        if (!approve && '${json['reason'] ?? ''}'.trim().isEmpty) {
          throw _Validation({
            'reason': ['The reason field is required.'],
          });
        }
        p
          ..['status'] = approve ? 'approved' : 'rejected'
          ..['status_label'] = approve ? 'Approved - get quotes / raise PO' : 'Rejected'
          ..['status_tone'] = approve ? 'partial' : 'rejected'
          ..['decider'] = _person(userId)
          ..['decided_at'] = FakeBackend._iso(DateTime.now())
          ..['decision_reason'] = json['reason'];
        return FakeResponse(200, {'data': _prJson(p, userId)});
      case ('GET', ['purchase-orders']):
        final st = query['status'];
        return FakeResponse(200, b._paginate(pos.reversed.where((p) => st == null || p['status'] == st).map((p) => _poJson(p, userId)).toList(), query));
      case ('GET', ['purchase-orders', final id]):
        final p = _find(pos, id);
        return p == null ? _notFound : FakeResponse(200, {'data': _poJson(p, userId)});
      case ('POST', ['purchase-orders', final id, 'decision']):
        final p = _find(pos, id);
        if (p == null) return _notFound;
        if (!b._can(userId, 'decide_purchasing') || p['status'] != 'pending_approval') return _forbidden;
        if (json['lock_version'] != p['lock_version']) {
          return const FakeResponse(409, {'message': 'This order changed since you opened it. Reload and check it again.'});
        }
        final approve = json['decision'] == 'approved';
        if (!approve && '${json['reason'] ?? ''}'.trim().isEmpty) {
          throw _Validation({
            'reason': ['The reason field is required.'],
          });
        }
        p
          ..['status'] = approve ? 'approved' : 'rejected'
          ..['status_label'] = approve ? 'Approved - awaiting delivery' : 'Rejected'
          ..['status_tone'] = approve ? 'approved' : 'rejected'
          ..['lock_version'] = (p['lock_version'] as int) + 1
          ..['decider'] = _person(userId)
          ..['decided_at'] = FakeBackend._iso(DateTime.now())
          ..['decision_reason'] = json['reason'];
        return FakeResponse(200, {'data': _poJson(p, userId)});
    }
    return null;
  }

  // ---------------------------------------------------------------- issuing

  Decimal _outstanding(Map<String, dynamic> l) {
    final fin = l['final_approved_qty'];
    if (fin == null) return Decimal.zero;
    final o = _d(fin) - _d(l['issued_qty']);
    return o < Decimal.zero ? Decimal.zero : o;
  }

  List<Map<String, dynamic>> _shortfall(Map<String, dynamic> r, int storeId) => [
    for (final l in (r['lines'] as List).cast<Map<String, dynamic>>())
      if (_outstanding(l) > Decimal.zero && _onHand(l['item']['id'] as int, storeId) < _outstanding(l))
        () {
          final have = _onHand(l['item']['id'] as int, storeId);
          final short = _outstanding(l) - have;
          final min = _d(_item(l['item']['id'] as int)['min_level']);
          return {
            'line_id': l['id'],
            'item': {'id': l['item']['id'], 'name': l['item']['name'], 'unit': l['item']['unit']},
            'short_qty': FakeBackend._fmtQty(short),
            'on_hand': FakeBackend._fmtQty(have),
            'restock_qty': FakeBackend._fmtQty(min > short ? min : short),
          };
        }(),
  ];

  Map<String, dynamic> _issueForm(Map<String, dynamic> r, int storeId) => {
    'request': b._requestSummary(r),
    'stores': b._stores,
    'store_id': storeId,
    'recipient_name': r['requester']['name'],
    'lines': [
      for (final l in (r['lines'] as List).cast<Map<String, dynamic>>())
        {
          'id': l['id'],
          'item': l['item'],
          'final_approved_qty': l['final_approved_qty'],
          'issued_qty': l['issued_qty'],
          'outstanding': FakeBackend._fmtQty(_outstanding(l)),
          'on_hand': FakeBackend._fmtQty(_onHand(l['item']['id'] as int, storeId)),
          'max_issue': FakeBackend._fmtQty(
            _onHand(l['item']['id'] as int, storeId) < _outstanding(l) ? _onHand(l['item']['id'] as int, storeId) : _outstanding(l),
          ),
        },
    ],
    'shortfall': _shortfall(r, storeId),
    'open_restock': [
      for (final p in prs.where((p) => p['material_request']?['id'] == r['id'] && ['submitted', 'approved'].contains(p['status'])))
        {'id': p['id'], 'pr_no': p['pr_no']},
    ],
  };

  FakeResponse _issue(Map<String, dynamic> r, int userId, Map<String, dynamic> json) {
    final key = '${json['idempotency_key'] ?? ''}';
    if (key.isNotEmpty && _keys.containsKey(key)) {
      return FakeResponse(200, {'data': _issueJson(_keys[key]!, userId), 'restock': null, 'replayed': true});
    }
    if (!['overall_approved', 'partially_issued'].contains(r['status'])) {
      return const FakeResponse(422, {'message': 'This request cannot be issued now.'});
    }
    final storeId = (json['store_id'] as int?) ?? 1;
    final qty = (json['qty'] as Map?) ?? {};
    final lines = <Map<String, dynamic>>[];
    var total = Decimal.zero;
    for (final l in (r['lines'] as List).cast<Map<String, dynamic>>()) {
      var q = _d(qty['${l['id']}']);
      if (q <= Decimal.zero) continue;
      final itemId = l['item']['id'] as int;
      if (q > _outstanding(l)) return FakeResponse(422, {'message': '${l['item']['name']}: only ${_outstanding(l)} approved quantity remains.'});
      // More than the store holds: issue what is in stock; the rest stays outstanding.
      if (q > _onHand(itemId, storeId)) q = _onHand(itemId, storeId);
      if (q <= Decimal.zero) continue;
      final cost = _d(_item(itemId)['avg_cost']);
      total += q * cost;
      lines.add({
        'id': _seq++,
        'item': l['item'],
        'qty': FakeBackend._fmtQty(q),
        'returned_qty': '0.000',
        'unit_cost': cost.toStringAsFixed(2),
        'line_value': (q * cost).toStringAsFixed(2),
        '_line': l,
      });
    }
    if (lines.isEmpty) {
      throw _Validation({
        'qty': ['Enter a quantity for at least one item.'],
      });
    }
    for (final x in lines) {
      final l = x.remove('_line') as Map<String, dynamic>;
      _move(l['item']['id'] as int, storeId, -_d(x['qty']));
      l['issued_qty'] = FakeBackend._fmtQty(_d(l['issued_qty']) + _d(x['qty']));
      x['approved_qty'] = FakeBackend._fmtQty(_d(l['final_approved_qty']));
      x['balance_qty'] = FakeBackend._fmtQty(_outstanding(l));
    }
    final fully = (r['lines'] as List).cast<Map<String, dynamic>>().every((l) => _outstanding(l) <= Decimal.zero);
    b._setStatus(r, fully ? 'fully_issued' : 'partially_issued');
    final issue = <String, dynamic>{
      'id': issues.length + 1,
      'issue_no': _no('IV'),
      'request': {'id': r['id'], 'request_no': r['request_no']},
      'requester_id': r['requester']['id'],
      'project': r['project'],
      'store': _store(storeId),
      'issued_at': FakeBackend._iso(DateTime.now()),
      'issuer': _person(userId),
      'recipient_name': '${json['recipient_name'] ?? ''}'.trim(),
      'notes': json['notes'],
      'total_value': total.toStringAsFixed(2),
      'lines': lines,
      'acknowledgment': null,
    };
    issues.add(issue);
    if (key.isNotEmpty) _keys[key] = issue;
    final pr = fully ? null : _restock(r, userId, storeId);
    return FakeResponse(201, {
      'data': _issueJson(issue, userId),
      'restock': pr == null ? null : {'id': pr['id'], 'pr_no': pr['pr_no']},
      'replayed': false,
    });
  }

  Map<String, dynamic>? _restock(Map<String, dynamic> r, int userId, int storeId) {
    final asked = {
      for (final p in prs.where((p) => p['material_request']?['id'] == r['id'] && ['draft', 'submitted', 'approved'].contains(p['status'])))
        for (final l in p['lines'] as List) l['item']['id'],
    };
    final lines = [
      for (final s in _shortfall(r, storeId))
        if (!asked.contains(s['item']['id']))
          {
            'id': _seq++,
            'item': _itemRef(s['item']['id'] as int),
            'qty': s['restock_qty'],
            'est_unit_cost': _item(s['item']['id'] as int)['avg_cost'],
            'notes': 'Not in stock for ${r['request_no']}: ${s['short_qty']}',
          },
    ];
    if (lines.isEmpty) return null;
    final store = _store(storeId);
    final pr = <String, dynamic>{
      'id': prs.length + 1,
      'pr_no': _no('PR'),
      'status': 'submitted',
      'status_label': 'Awaiting approval',
      'status_tone': 'pending',
      'project': null,
      'store': store,
      'purpose': 'Restock ${store['code']}: not enough stock to issue ${r['request_no']} in full.',
      'requester': _person(userId),
      'needed_on': r['needed_on'],
      'estimated_total': lines.fold(Decimal.zero, (a, l) => a + _d(l['qty']) * _d(l['est_unit_cost'])).toStringAsFixed(2),
      'material_request': {'id': r['id'], 'request_no': r['request_no']},
      'submitted_at': FakeBackend._iso(DateTime.now()),
      'created_at': FakeBackend._iso(DateTime.now()),
      'lines': lines,
    };
    prs.add(pr);
    return pr;
  }

  Map<String, dynamic> _issueJson(Map<String, dynamic> i, int userId, {bool detail = true}) {
    final values = _values(userId);
    final out = <String, dynamic>{
      for (final k in const ['id', 'issue_no', 'request', 'project', 'store', 'issued_at', 'issuer', 'recipient_name']) k: i[k],
      'acknowledged': i['acknowledgment'] != null,
      'lines_count': (i['lines'] as List).length,
      if (values) 'total_value': i['total_value'],
    };
    if (!detail) return out;
    return {
      ...out,
      'notes': i['notes'],
      'lines': [
        for (final l in (i['lines'] as List).cast<Map<String, dynamic>>())
          {
            for (final k in const ['id', 'item', 'qty', 'returned_qty', 'approved_qty', 'balance_qty']) k: l[k],
            if (values) 'unit_cost': l['unit_cost'],
            if (values) 'line_value': l['line_value'],
          },
      ],
      'acknowledgment': i['acknowledgment'],
      'returns': [
        for (final r in returns.where((r) => r['issue']['id'] == i['id'])) {'id': r['id'], 'return_no': r['return_no'], 'returned_at': r['returned_at']},
      ],
      'can': {
        'acknowledge': i['acknowledgment'] == null && (i['requester_id'] == userId || b._can(userId, 'issue_stock')),
        'return': b._can(userId, 'manage_stock') && (i['lines'] as List).any((l) => _d(l['qty']) > _d(l['returned_qty'])),
      },
    };
  }

  // ---------------------------------------------------------------- receipts, transfers, returns

  /// Receipts and returns share a shape: header fields + valued lines (values only for value viewers).
  Map<String, dynamic> _valued(Map<String, dynamic> d, int userId, {bool summary = false}) {
    final values = _values(userId);
    final head = {
      for (final e in d.entries)
        if (e.key != 'lines' && e.key != 'total_value') e.key: e.value,
      'lines_count': (d['lines'] as List).length,
      if (values) 'total_value': d['total_value'],
    };
    if (summary) return head;
    return {
      ...head,
      'lines': [
        for (final l in (d['lines'] as List).cast<Map<String, dynamic>>())
          {
            'id': l['id'],
            'item': l['item'],
            'qty': l['qty'],
            if (values) 'unit_cost': l['unit_cost'],
            if (values) d.containsKey('return_no') ? 'line_value' : 'line_total': l['total'],
          },
      ],
    };
  }

  FakeResponse _receive(int userId, Map<String, dynamic> json) {
    if (!b._can(userId, 'manage_stock')) return _forbidden;
    final key = '${json['idempotency_key'] ?? ''}';
    if (key.isNotEmpty && _keys.containsKey(key)) return FakeResponse(200, {'data': _valued(_keys[key]!, userId), 'replayed': true});
    if ('${json['supplier_name'] ?? ''}'.trim().isEmpty) {
      throw _Validation({
        'supplier_name': ['The supplier name field is required.'],
      });
    }
    final raw = (json['lines'] as List?) ?? const [];
    if (raw.isEmpty) {
      throw _Validation({
        'lines': ['Add at least one item.'],
      });
    }
    final storeId = (json['store_id'] as int?) ?? 1;
    var total = Decimal.zero;
    final lines = <Map<String, dynamic>>[];
    for (final l in raw.cast<Map>()) {
      final itemId = l['item_id'] as int;
      final q = _d(l['qty']);
      final c = _d(l['unit_cost']);
      _move(itemId, storeId, q);
      total += q * c;
      lines.add({
        'id': _seq++,
        'item': _itemRef(itemId),
        'qty': FakeBackend._fmtQty(q),
        'unit_cost': c.toStringAsFixed(2),
        'total': (q * c).toStringAsFixed(2),
      });
    }
    final r = <String, dynamic>{
      'id': receipts.length + 1,
      'receipt_no': _no('GRN'),
      'type': 'purchase',
      'store': _store(storeId),
      'received_on': json['received_on'],
      'supplier_name': json['supplier_name'],
      'reference': json['reference'],
      'purchase_order': null,
      'receiver': _person(userId),
      'notes': json['notes'],
      'total_value': total.toStringAsFixed(2),
      'lines': lines,
    };
    receipts.add(r);
    if (key.isNotEmpty) _keys[key] = r;
    return FakeResponse(201, {'data': _valued(r, userId), 'replayed': false});
  }

  FakeResponse _dispatch(int userId, Map<String, dynamic> json) {
    if (!b._can(userId, 'create_transfer')) return _forbidden;
    final from = json['from_store_id'] as int?;
    final to = json['to_store_id'] as int?;
    if (from == null || to == null || from == to) {
      throw _Validation({
        'to_store_id': ['Choose two different stores.'],
      });
    }
    final raw = (json['lines'] as List?) ?? const [];
    if (raw.isEmpty) {
      throw _Validation({
        'lines': ['Add at least one item.'],
      });
    }
    for (final l in raw.cast<Map>()) {
      if (_d(l['qty']) > _onHand(l['item_id'] as int, from)) {
        return FakeResponse(422, {'message': '${_item(l['item_id'] as int)['name']}: only ${_onHand(l['item_id'] as int, from)} in ${_store(from)['code']}.'});
      }
    }
    final lines = [
      for (final l in raw.cast<Map>())
        {'id': _seq++, 'item': _itemRef(l['item_id'] as int), 'qty': FakeBackend._fmtQty(_d(l['qty'])), 'received_qty': null, 'shortfall_reason': null},
    ];
    for (final l in lines) {
      _move((l['item'] as Map)['id'] as int, from, -_d(l['qty']));
    }
    final t = <String, dynamic>{
      'id': transfers.length + 1,
      'transfer_no': _no('TR'),
      'status': 'in_transit',
      'status_label': 'In transit',
      'status_tone': 'pending',
      'from_store': _store(from),
      'to_store': _store(to),
      'dispatched_at': FakeBackend._iso(DateTime.now()),
      'dispatcher': _person(userId),
      'dispatched_by': userId,
      'received_at': null,
      'receiver': null,
      'notes': json['notes'],
      'receive_notes': null,
      'lines': lines,
    };
    transfers.add(t);
    return FakeResponse(201, {'data': _transferJson(t, userId), 'replayed': false});
  }

  Map<String, dynamic> _transferJson(Map<String, dynamic> t, int userId) => {
    for (final e in t.entries)
      if (e.key != 'dispatched_by') e.key: e.value,
    'lines_count': (t['lines'] as List).length,
    'can': {
      // Fake mode has one demo storekeeper, so the dispatcher may also receive here.
      'receive': t['status'] == 'in_transit' && b._can(userId, 'manage_stock'),
      'cancel': t['status'] == 'in_transit' && t['dispatched_by'] == userId,
    },
  };

  FakeResponse _recordReturn(Map<String, dynamic> i, int userId, Map<String, dynamic> json) {
    if (!b._can(userId, 'manage_stock')) return _forbidden;
    if ('${json['reason'] ?? ''}'.trim().length < 5) {
      throw _Validation({
        'reason': ['The reason field must be at least 5 characters.'],
      });
    }
    final qty = (json['qty'] as Map?) ?? {};
    final storeId = (json['store_id'] as int?) ?? 1;
    final lines = <Map<String, dynamic>>[];
    var total = Decimal.zero;
    for (final l in (i['lines'] as List).cast<Map<String, dynamic>>()) {
      final q = _d(qty['${l['id']}']);
      if (q <= Decimal.zero) continue;
      if (q > _d(l['qty']) - _d(l['returned_qty'])) return FakeResponse(422, {'message': '${l['item']['name']}: more than was issued.'});
      l['returned_qty'] = FakeBackend._fmtQty(_d(l['returned_qty']) + q);
      _move(l['item']['id'] as int, storeId, q);
      final v = q * _d(l['unit_cost']);
      total += v;
      lines.add({'id': _seq++, 'item': l['item'], 'qty': FakeBackend._fmtQty(q), 'unit_cost': l['unit_cost'], 'total': v.toStringAsFixed(2)});
    }
    if (lines.isEmpty) {
      throw _Validation({
        'qty': ['Enter a quantity for at least one item.'],
      });
    }
    final r = <String, dynamic>{
      'id': returns.length + 1,
      'return_no': _no('RT'),
      'returned_at': FakeBackend._iso(DateTime.now()),
      'issue': {'id': i['id'], 'issue_no': i['issue_no']},
      'project': i['project'],
      'store': _store(storeId),
      'returned_by_name': json['returned_by_name'],
      'reason': json['reason'],
      'receiver': _person(userId),
      'total_value': total.toStringAsFixed(2),
      'lines': lines,
    };
    returns.add(r);
    return FakeResponse(201, {'data': _valued(r, userId), 'replayed': false});
  }

  // ---------------------------------------------------------------- purchasing

  Map<String, dynamic> _prJson(Map<String, dynamic> p, int userId) => {
    ...p,
    'is_restock': p['material_request'] != null,
    'lines_count': (p['lines'] as List).length,
    'purchase_orders': const [],
    'can': {'decide': p['status'] == 'submitted' && b._can(userId, 'decide_purchasing'), 'submit': false},
  };

  Map<String, dynamic> _poJson(Map<String, dynamic> p, int userId) => {
    ...p,
    'lines_count': (p['lines'] as List).length,
    'can': {'decide': p['status'] == 'pending_approval' && b._can(userId, 'decide_purchasing')},
  };

  /// Same keys as the server's issue cards on `GET /dashboard`.
  List<Map<String, dynamic>> dashboardCards(int userId) {
    final mine = issues.where((i) => b._can(userId, 'issue_stock') || i['requester_id'] == userId).toList();
    final month = DateTime(b._now.year, b._now.month);
    final thisMonth = mine.where((i) => DateTime.parse(i['issued_at'] as String).isAfter(month)).toList();
    final unack = mine.where((i) => i['acknowledgment'] == null).length;
    return [
      {'key': 'issued_vouchers_month', 'label': 'Issue vouchers this month', 'value': thisMonth.length, 'tone': 'navy'},
      {'key': 'issued_items_month', 'label': 'Items issued this month', 'value': thisMonth.fold<int>(0, (a, i) => a + (i['lines'] as List).length), 'tone': ''},
      {'key': 'issues_unacknowledged', 'label': 'Vouchers not acknowledged', 'value': unack, 'tone': unack > 0 ? 'gold' : 'ok'},
    ];
  }

  /// Restock progress shown to the requester on the request.
  List<Map<String, dynamic>> tracking(Map<String, dynamic> r) => [
        for (final p in prs.where((p) => p['material_request']?['id'] == r['id']))
          {
            'id': p['id'],
            'pr_no': p['pr_no'],
            'status': p['status'],
            'status_label': p['status_label'],
            'status_tone': p['status_tone'],
            'stage': switch (p['status']) {
              'submitted' => 'Waiting for the Overall Manager to approve the restock.',
              'approved' => 'Restock approved; procurement is getting quotes and ordering.',
              'rejected' => 'Restock was not approved${p['decision_reason'] != null ? ': ${p['decision_reason']}' : '.'}',
              _ => 'Restock cancelled.',
            },
            'orders': const [],
            'updated_at': p['decided_at'] ?? p['submitted_at'],
          },
      ];

  void seed() {
    final cement = _item(1);
    final steel = _item(5);
    pos.add({
      'id': 1,
      'po_no': 'PO-${b._now.year}-0001',
      'status': 'pending_approval',
      'status_label': 'Awaiting approval',
      'status_tone': 'pending',
      'supplier': {'id': 1, 'name': 'Hima Cement Ltd'},
      'project': b._projects.first,
      'store': b._stores.first,
      'order_date': FakeBackend._iso(b._now).substring(0, 10),
      'expected_on': null,
      'total': (Decimal.fromInt(100) * _d(cement['avg_cost']) + Decimal.fromInt(40) * _d(steel['avg_cost'])).toStringAsFixed(2),
      'creator': {'id': 8, 'name': 'Peter Ssemwogerere'},
      'lock_version': 0,
      'terms': 'Delivery to Main Store within 7 days.',
      'notes': null,
      'requisition': null,
      'decider': null,
      'decided_at': null,
      'decision_reason': null,
      'owner_threshold': '20000000.00',
      'lines': [
        {
          'id': 1,
          'item': _itemRef(1),
          'qty': '100.000',
          'received_qty': '0.000',
          'unit_price': cement['avg_cost'],
          'line_total': (Decimal.fromInt(100) * _d(cement['avg_cost'])).toStringAsFixed(2),
        },
        {
          'id': 2,
          'item': _itemRef(5),
          'qty': '40.000',
          'received_qty': '0.000',
          'unit_price': steel['avg_cost'],
          'line_total': (Decimal.fromInt(40) * _d(steel['avg_cost'])).toStringAsFixed(2),
        },
      ],
    });
  }
}
