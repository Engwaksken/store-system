import 'dart:convert';
import 'dart:math';

import 'package:decimal/decimal.dart';

/// Response produced by [FakeBackend.handle].
class FakeResponse {
  const FakeResponse(this.status, [this.body, this.contentType = 'application/json', this.bytes]);
  final int status;
  final Object? body;
  final String contentType;
  final List<int>? bytes;
}

/// A file uploaded through the fake multipart endpoint.
class FakeUpload {
  const FakeUpload({required this.filename, required this.length});
  final String filename;
  final int length;
}

/// In-memory implementation of the API contract (docs/API.md), used with
/// `--dart-define=USE_FAKE_API=true` and by widget tests.
///
/// Sign in with any e-mail and the password [password]:
/// * an address containing `engineer` -> Site Engineer (no approvals, no stock value);
/// * containing `new` -> must change password first;
/// * containing `deactivated` -> 403 deactivated;
/// * anything else -> Project Manager with every permission.
class FakeBackend {
  FakeBackend({DateTime? now, this.baseUrl = 'https://store.kemmytech.com/api/v1'})
      : _now = now ?? DateTime.now() {
    _seed();
  }

  static const password = 'password1';

  /// Used to build absolute `receipt_url`s, like the real server does.
  final String baseUrl;

  final DateTime _now;
  final _tokens = <String, int>{}; // token -> user id
  final _users = <int, Map<String, dynamic>>{};
  final _passwords = <int, String>{};
  final _projects = <Map<String, dynamic>>[];
  final _stores = <Map<String, dynamic>>[];
  final _items = <Map<String, dynamic>>[];

  /// item id -> store id -> qty (Decimal).
  final _balances = <int, Map<int, Decimal>>{};
  final _requests = <Map<String, dynamic>>[];
  final _claims = <Map<String, dynamic>>[];
  final _notifications = <Map<String, dynamic>>[];
  final _categories = <Map<String, dynamic>>[];
  final _costCodes = <Map<String, dynamic>>[];
  int _nextRequestId = 1;
  int _nextLineId = 1;
  int _nextClaimId = 1;
  int _tokenSeq = 1;

  // ---------------------------------------------------------------- routing

  FakeResponse handle({
    required String method,
    required String path,
    Map<String, dynamic> query = const {},
    Object? body,
    Map<String, String> fields = const {},
    Map<String, FakeUpload> files = const {},
    String? authorization,
  }) {
    final p = path.replaceFirst(RegExp(r'^/+'), '').replaceFirst(RegExp(r'/+$'), '');
    final segs = p.split('/');
    final json = body is Map ? body.cast<String, dynamic>() : <String, dynamic>{};

    if (method == 'POST' && p == 'auth/login') return _login(json);

    final userId = _authUserId(authorization);
    if (userId == null) return const FakeResponse(401, {'message': 'Unauthenticated.'});
    final user = _users[userId]!;
    if (user['is_active'] == false) {
      return const FakeResponse(403, {'message': 'Your account has been deactivated.', 'code': 'deactivated'});
    }

    final passwordOpen = (method == 'GET' && p == 'me') ||
        (method == 'POST' && (p == 'auth/change-password' || p == 'auth/logout'));
    if (user['must_change_password'] == true && !passwordOpen) {
      return const FakeResponse(403, {
        'message': 'You must change your password before continuing.',
        'code': 'password_change_required',
      });
    }

    try {
      switch ((method, segs)) {
        case ('GET', ['me']):
          return FakeResponse(200, {'user': _userJson(userId)});
        case ('POST', ['auth', 'logout']):
          _tokens.remove(_bearer(authorization));
          return const FakeResponse(204);
        case ('POST', ['auth', 'change-password']):
          return _changePassword(userId, json);
        case ('GET', ['dashboard']):
          return FakeResponse(200, _dashboard(userId));
        case ('GET', ['approvals']):
          return _approvals(userId, query);
        case ('GET', ['requests']):
          return _requestList(userId, query);
        case ('GET', ['requests', 'options']):
          return FakeResponse(200, {'projects': _projects});
        case ('POST', ['requests']):
          return _createRequest(userId, json);
        case ('GET', ['requests', final id]):
          return _withRequest(id, (r) => FakeResponse(200, {'data': _requestDetail(r, userId)}));
        case ('POST', ['requests', final id, 'decision']):
          return _withRequest(id, (r) => _decideRequest(r, userId, json));
        case ('POST', ['requests', final id, 'cancel']):
          return _withRequest(id, (r) => _cancelRequest(r, userId));
        case ('GET', ['items']):
          return _itemList(userId, query);
        case ('GET', ['items', final id]):
          return _itemDetail(userId, id);
        case ('GET', ['stores']):
          return FakeResponse(200, {'data': _stores});
        case ('GET', ['claims']):
          return _claimList(userId, query);
        case ('GET', ['claims', 'options']):
          return FakeResponse(200, {'projects': _projects, 'categories': _categories, 'cost_codes': _costCodes});
        case ('POST', ['claims']):
          return _createClaim(userId, fields, files);
        case ('GET', ['claims', final id]):
          return _withClaim(id, userId, (c) => FakeResponse(200, {'data': _claimJson(c, userId)}));
        case ('GET', ['claims', final id, 'receipt']):
          return _withClaim(id, userId, _receipt);
        case ('POST', ['claims', final id, 'decision']):
          return _withClaim(id, userId, (c) => _decideClaim(c, userId, json));
        case ('GET', ['notifications']):
          return _notificationList(userId, query);
        case ('POST', ['notifications', 'read-all']):
          for (final n in _notifications.where((n) => n['user_id'] == userId)) {
            n['read'] = true;
          }
          return const FakeResponse(204);
        case ('POST', ['notifications', final id, 'read']):
          final n = _notifications.where((n) => n['id'] == id && n['user_id'] == userId).firstOrNull;
          if (n == null) return const FakeResponse(404, {'message': 'Not found.'});
          n['read'] = true;
          return const FakeResponse(204);
      }
    } on _Validation catch (v) {
      return FakeResponse(422, {'message': v.errors.values.first.first, 'errors': v.errors});
    }
    return const FakeResponse(404, {'message': 'Not found.'});
  }

  String? _bearer(String? authorization) {
    if (authorization == null || !authorization.startsWith('Bearer ')) return null;
    return authorization.substring(7);
  }

  int? _authUserId(String? authorization) => _tokens[_bearer(authorization)];

  // ---------------------------------------------------------------- auth

  FakeResponse _login(Map<String, dynamic> json) {
    final email = '${json['email'] ?? ''}'.trim().toLowerCase();
    final pass = '${json['password'] ?? ''}';
    final errors = <String, List<String>>{};
    if (email.isEmpty) errors['email'] = ['The email field is required.'];
    if (pass.isEmpty) errors['password'] = ['The password field is required.'];
    if ('${json['device_name'] ?? ''}'.isEmpty) errors['device_name'] = ['The device name field is required.'];
    if (errors.isNotEmpty) return FakeResponse(422, {'message': errors.values.first.first, 'errors': errors});

    final int userId;
    if (email.contains('engineer')) {
      userId = 5;
    } else if (email.contains('new')) {
      userId = 9;
    } else if (email.contains('deactivated')) {
      return const FakeResponse(403, {'message': 'Your account has been deactivated.', 'code': 'deactivated'});
    } else {
      userId = 3;
    }
    if (_passwords[userId] != pass) {
      return const FakeResponse(422, {
        'message': 'These credentials do not match our records.',
        'errors': {
          'email': ['These credentials do not match our records.'],
        },
      });
    }
    final token = '${_tokenSeq++}|fake${Random().nextInt(1 << 30)}';
    _tokens[token] = userId;
    return FakeResponse(200, {'token': token, 'user': _userJson(userId)});
  }

  FakeResponse _changePassword(int userId, Map<String, dynamic> json) {
    final current = '${json['current_password'] ?? ''}';
    final next = '${json['password'] ?? ''}';
    final confirm = '${json['password_confirmation'] ?? ''}';
    final errors = <String, List<String>>{};
    if (current != _passwords[userId]) {
      errors['current_password'] = ['The password is incorrect.'];
    }
    if (next.length < 8) {
      errors['password'] = ['The password field must be at least 8 characters.'];
    } else if (!RegExp(r'[A-Za-z]').hasMatch(next) || !RegExp(r'\d').hasMatch(next)) {
      errors['password'] = ['The password field must contain at least one letter and one number.'];
    } else if (next == current) {
      errors['password'] = ['The new password must be different from the current one.'];
    } else if (next != confirm) {
      errors['password'] = ['The password field confirmation does not match.'];
    }
    if (errors.isNotEmpty) throw _Validation(errors);
    _passwords[userId] = next;
    _users[userId]!['must_change_password'] = false;
    return FakeResponse(200, {'user': _userJson(userId)});
  }

  Map<String, dynamic> _userJson(int id) {
    final u = Map<String, dynamic>.from(_users[id]!);
    u.remove('is_active');
    return u;
  }

  bool _can(int userId, String key) => (_users[userId]!['can'] as Map)[key] == true;

  // ---------------------------------------------------------------- dashboard

  Map<String, dynamic> _dashboard(int userId) {
    final awaiting = _requests.where((r) => _decisionStep(r, userId) != null).toList()
      ..sort((a, b) => (a['submitted_at'] as String? ?? '').compareTo(b['submitted_at'] as String? ?? ''));
    final mine = _requests
        .where((r) => r['requester']['id'] == userId &&
            !['cancelled', 'fully_issued', 'pm_rejected', 'overall_rejected'].contains(r['status']))
        .length;
    final low = _items.where((i) => _isLow(i, null)).length;
    final cards = <Map<String, dynamic>>[
      if (_can(userId, 'approve_requests'))
        {'key': 'awaiting_me', 'label': 'Waiting for my approval', 'value': awaiting.length, 'tone': 'gold'},
      {'key': 'my_open_requests', 'label': 'My open requests', 'value': mine, 'tone': 'navy'},
      {'key': 'low_stock', 'label': 'Low-stock items', 'value': low, 'tone': low > 0 ? 'bad' : 'ok'},
      if (_can(userId, 'decide_claims'))
        {
          'key': 'claims_awaiting',
          'label': 'Claims awaiting approval',
          'value': _claims.where((c) => c['status'] == 'submitted').length,
          'tone': 'gold',
        },
      if (_can(userId, 'view_claims'))
        {
          'key': 'claims_unpaid',
          'label': 'Approved claims unpaid',
          'value': _claims.where((c) => c['status'] == 'approved').length,
          'tone': '',
        },
    ];
    return {
      'cards': cards,
      'awaiting_me': awaiting.take(5).map(_requestSummary).toList(),
      'unread_notifications': _notifications.where((n) => n['user_id'] == userId && n['read'] != true).length,
    };
  }

  // ---------------------------------------------------------------- requests

  FakeResponse _withRequest(String id, FakeResponse Function(Map<String, dynamic>) f) {
    final r = _requests.where((r) => '${r['id']}' == id).firstOrNull;
    if (r == null) return const FakeResponse(404, {'message': 'Not found.'});
    return f(r);
  }

  /// `pm` / `overall` when [userId] must decide [r] now, else null.
  String? _decisionStep(Map<String, dynamic> r, int userId) {
    if (!_can(userId, 'approve_requests')) return null;
    if (r['requester']['id'] == userId) return null; // separation of duties
    return switch (r['status']) {
      'submitted' => 'pm',
      'pm_approved' => 'overall',
      _ => null,
    };
  }

  bool _canCancel(Map<String, dynamic> r, int userId) =>
      ['draft', 'submitted'].contains(r['status']) && r['requester']['id'] == userId;

  Map<String, dynamic> _requestSummary(Map<String, dynamic> r) => {
        for (final k in const [
          'id', 'request_no', 'version', 'status', 'status_label', 'status_tone', 'project',
          'requester', 'purpose', 'needed_on', 'submitted_at', 'created_at',
        ])
          k: r[k],
        'lines_count': (r['lines'] as List).length,
      };

  Map<String, dynamic> _requestDetail(Map<String, dynamic> r, int userId) {
    final step = _decisionStep(r, userId);
    return {
      ..._requestSummary(r),
      'project_manager': r['project_manager'],
      'lines': [
        for (final l in r['lines'] as List)
          {
            ...(l as Map<String, dynamic>),
            'on_hand': _fmtQty(_totalOnHand(l['item']['id'] as int)),
          },
      ],
      'approvals': r['approvals'],
      'can': {
        'decide': step != null,
        'decision_step': step,
        'max_qty_field': switch (step) {
          'pm' => 'requested_qty',
          'overall' => 'pm_approved_qty',
          _ => null,
        },
        'cancel': _canCancel(r, userId),
      },
    };
  }

  FakeResponse _approvals(int userId, Map<String, dynamic> query) {
    var list = _requests.where((r) => _decisionStep(r, userId) != null).toList()
      ..sort((a, b) => (a['submitted_at'] as String? ?? '').compareTo(b['submitted_at'] as String? ?? ''));
    list = _searchRequests(list, query['q']);
    return FakeResponse(200, _paginate(list.map(_requestSummary).toList(), query));
  }

  FakeResponse _requestList(int userId, Map<String, dynamic> query) {
    var list = _requests.where((r) {
      // Engineers see their own requests; managers see everything.
      if (!_can(userId, 'approve_requests')) return r['requester']['id'] == userId;
      return r['status'] != 'draft' || r['requester']['id'] == userId;
    }).toList()
      ..sort((a, b) => (b['created_at'] as String).compareTo(a['created_at'] as String));
    final status = '${query['status'] ?? ''}';
    if (status.isNotEmpty) list = list.where((r) => r['status'] == status).toList();
    final projectId = '${query['project_id'] ?? ''}';
    if (projectId.isNotEmpty) list = list.where((r) => '${r['project']['id']}' == projectId).toList();
    list = _searchRequests(list, query['q']);
    return FakeResponse(200, _paginate(list.map(_requestSummary).toList(), query));
  }

  List<Map<String, dynamic>> _searchRequests(List<Map<String, dynamic>> list, Object? q) {
    final term = '${q ?? ''}'.trim().toLowerCase();
    if (term.isEmpty) return list;
    return list
        .where((r) =>
            '${r['request_no']}'.toLowerCase().contains(term) || '${r['purpose']}'.toLowerCase().contains(term))
        .toList();
  }

  FakeResponse _decideRequest(Map<String, dynamic> r, int userId, Map<String, dynamic> json) {
    if ('${json['version']}' != '${r['version']}') {
      return FakeResponse(409, {
        'message': 'Request ${r['request_no']} was changed (now version ${r['version']}). Reload and try again.',
      });
    }
    final step = _decisionStep(r, userId);
    if (step == null) {
      return FakeResponse(409, {
        'message': 'Request ${r['request_no']} is ${r['status_label']}; this action is not allowed now.',
      });
    }
    final decision = '${json['decision'] ?? ''}';
    if (decision != 'approved' && decision != 'rejected') {
      throw _Validation({'decision': ['The selected decision is invalid.']});
    }
    final reason = '${json['reason'] ?? ''}'.trim();
    if (decision == 'rejected' && reason.isEmpty) {
      throw _Validation({'reason': ['Please give a reason for rejecting.']});
    }
    final qty = json['qty'] is Map ? (json['qty'] as Map).cast<String, dynamic>() : <String, dynamic>{};
    final ceilingField = step == 'pm' ? 'requested_qty' : 'pm_approved_qty';
    final targetField = step == 'pm' ? 'pm_approved_qty' : 'final_approved_qty';
    final approved = <int, String>{};
    if (decision == 'approved') {
      final errors = <String, List<String>>{};
      for (final l in (r['lines'] as List).cast<Map<String, dynamic>>()) {
        final ceiling = Decimal.parse('${l[ceilingField] ?? '0'}');
        final raw = qty['${l['id']}'];
        if (raw == null) {
          approved[l['id'] as int] = _fmtQty(ceiling);
          continue;
        }
        final value = Decimal.tryParse('$raw');
        if (value == null || value < Decimal.zero || value > ceiling) {
          errors['qty.${l['id']}'] = ['Must be between 0 and ${_trim(ceiling)}.'];
        } else {
          approved[l['id'] as int] = _fmtQty(value);
        }
      }
      if (errors.isNotEmpty) throw _Validation(errors);
      if (approved.values.every((v) => Decimal.parse(v) == Decimal.zero)) {
        throw _Validation({'qty': ['Approve at least one line, or reject the request.']});
      }
    }
    for (final l in (r['lines'] as List).cast<Map<String, dynamic>>()) {
      if (decision == 'approved') l[targetField] = approved[l['id']];
    }
    final approver = {'id': userId, 'name': _users[userId]!['name']};
    (r['approvals'] as List).add({
      'step': step,
      'version': r['version'],
      'decision': decision,
      'reason': reason.isEmpty ? null : reason,
      'approver': approver,
      'was_substitute': false,
      'decided_at': _iso(DateTime.now()),
    });
    _setStatus(
      r,
      switch ((step, decision)) {
        ('pm', 'approved') => 'pm_approved',
        ('pm', _) => 'pm_rejected',
        (_, 'approved') => 'overall_approved',
        _ => 'overall_rejected',
      },
    );
    return FakeResponse(200, {'data': _requestDetail(r, userId)});
  }

  FakeResponse _cancelRequest(Map<String, dynamic> r, int userId) {
    if (!_canCancel(r, userId)) {
      return FakeResponse(409, {
        'message': 'Request ${r['request_no']} is ${r['status_label']}; this action is not allowed now.',
      });
    }
    _setStatus(r, 'cancelled');
    return FakeResponse(200, {'data': _requestDetail(r, userId)});
  }

  FakeResponse _createRequest(int userId, Map<String, dynamic> json) {
    if (!_can(userId, 'create_request')) {
      return const FakeResponse(403, {'message': 'You may not raise requests.', 'code': 'forbidden'});
    }
    final errors = <String, List<String>>{};
    final project = _projects.where((p) => '${p['id']}' == '${json['project_id']}').firstOrNull;
    if (project == null) errors['project_id'] = ['Please choose a project.'];
    final purpose = '${json['purpose'] ?? ''}'.trim();
    if (purpose.isEmpty) errors['purpose'] = ['The purpose field is required.'];
    final neededOn = '${json['needed_on'] ?? ''}';
    if (DateTime.tryParse(neededOn) == null) errors['needed_on'] = ['Please choose the date needed.'];
    final lines = json['lines'] is List ? (json['lines'] as List) : const [];
    if (lines.isEmpty) errors['lines'] = ['Add at least one item.'];
    final newLines = <Map<String, dynamic>>[];
    for (var i = 0; i < lines.length; i++) {
      final l = lines[i] is Map ? (lines[i] as Map).cast<String, dynamic>() : <String, dynamic>{};
      final item = _items.where((it) => '${it['id']}' == '${l['item_id']}').firstOrNull;
      if (item == null) errors['lines.$i.item_id'] = ['Choose an item.'];
      final q = Decimal.tryParse('${l['requested_qty'] ?? ''}');
      if (q == null || q <= Decimal.zero) errors['lines.$i.requested_qty'] = ['Quantity must be more than 0.'];
      if (item != null && q != null) {
        newLines.add({
          'id': _nextLineId++,
          'item': {'id': item['id'], 'sku': item['sku'], 'name': item['name'], 'unit': item['unit']},
          'requested_qty': _fmtQty(q),
          'pm_approved_qty': null,
          'final_approved_qty': null,
          'issued_qty': '0.000',
          'notes': (l['notes'] as String?)?.trim().isEmpty ?? true ? null : l['notes'],
        });
      }
    }
    if (errors.isNotEmpty) throw _Validation(errors);
    final now = DateTime.now();
    final id = _nextRequestId++;
    final r = <String, dynamic>{
      'id': id,
      'request_no': 'MR-${now.year}-${id.toString().padLeft(4, '0')}',
      'version': 1,
      'project': project,
      'requester': {'id': userId, 'name': _users[userId]!['name']},
      'project_manager': {'id': 3, 'name': 'Brian Okello'},
      'purpose': purpose,
      'needed_on': neededOn,
      'submitted_at': json['submit'] == true ? _iso(now) : null,
      'created_at': _iso(now),
      'lines': newLines,
      'approvals': <Map<String, dynamic>>[],
    };
    _setStatus(r, json['submit'] == true ? 'submitted' : 'draft');
    _requests.add(r);
    return FakeResponse(201, {'data': _requestDetail(r, userId)});
  }

  static const _statusMeta = {
    'draft': ('Draft', 'muted'),
    'submitted': ('Awaiting PM', 'pending'),
    'pm_approved': ('Awaiting final approval', 'pending'),
    'pm_rejected': ('Rejected by PM', 'rejected'),
    'overall_approved': ('Approved', 'approved'),
    'overall_rejected': ('Rejected', 'rejected'),
    'partially_issued': ('Partially issued', 'partial'),
    'fully_issued': ('Issued', 'approved'),
    'cancelled': ('Cancelled', 'muted'),
  };

  void _setStatus(Map<String, dynamic> r, String status) {
    r['status'] = status;
    r['status_label'] = _statusMeta[status]!.$1;
    r['status_tone'] = _statusMeta[status]!.$2;
  }

  // ---------------------------------------------------------------- stock

  Decimal _totalOnHand(int itemId) =>
      (_balances[itemId] ?? const {}).values.fold(Decimal.zero, (a, b) => a + b);

  bool _isLow(Map<String, dynamic> item, int? storeId) {
    final onHand = storeId == null ? _totalOnHand(item['id'] as int) : (_balances[item['id']]?[storeId] ?? Decimal.zero);
    return onHand <= Decimal.parse(item['min_level'] as String);
  }

  Map<String, dynamic> _itemJson(Map<String, dynamic> item, int userId, int? storeId) {
    final onHand = storeId == null ? _totalOnHand(item['id'] as int) : (_balances[item['id']]?[storeId] ?? Decimal.zero);
    return {
      'id': item['id'],
      'sku': item['sku'],
      'name': item['name'],
      'category': item['category'],
      'unit': item['unit'],
      'on_hand': _fmtQty(onHand),
      'min_level': item['min_level'],
      'low': _isLow(item, storeId),
      'is_active': item['is_active'],
      if (_can(userId, 'view_stock_value')) 'avg_cost': item['avg_cost'],
    };
  }

  FakeResponse _itemList(int userId, Map<String, dynamic> query) {
    final storeId = int.tryParse('${query['store_id'] ?? ''}');
    final term = '${query['q'] ?? ''}'.trim().toLowerCase();
    var list = _items.where((i) {
      if (term.isNotEmpty &&
          !'${i['sku']}'.toLowerCase().contains(term) &&
          !'${i['name']}'.toLowerCase().contains(term)) {
        return false;
      }
      if ('${query['low'] ?? ''}' == '1' && !_isLow(i, storeId)) return false;
      if ('${query['active'] ?? ''}' == '1' && i['is_active'] != true) return false;
      return true;
    }).toList()
      ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    return FakeResponse(200, _paginate(list.map((i) => _itemJson(i, userId, storeId)).toList(), query));
  }

  FakeResponse _itemDetail(int userId, String id) {
    final item = _items.where((i) => '${i['id']}' == id).firstOrNull;
    if (item == null) return const FakeResponse(404, {'message': 'Not found.'});
    return FakeResponse(200, {
      'data': {
        ..._itemJson(item, userId, null),
        'balances': [
          for (final s in _stores)
            {'store': s, 'on_hand': _fmtQty(_balances[item['id']]?[s['id']] ?? Decimal.zero)},
        ],
      },
    });
  }

  // ---------------------------------------------------------------- claims

  bool _claimVisible(Map<String, dynamic> c, int userId) =>
      _can(userId, 'decide_claims') || c['claimant']['id'] == userId;

  FakeResponse _withClaim(String id, int userId, FakeResponse Function(Map<String, dynamic>) f) {
    final c = _claims.where((c) => '${c['id']}' == id && _claimVisible(c, userId)).firstOrNull;
    if (c == null) return const FakeResponse(404, {'message': 'Not found.'});
    return f(c);
  }

  Map<String, dynamic> _claimJson(Map<String, dynamic> c, int userId) {
    final mine = c['claimant']['id'] == userId;
    return {
      for (final e in c.entries)
        if (!e.key.startsWith('_')) e.key: e.value,
      'receipt_url': c['has_receipt'] == true ? '$baseUrl/claims/${c['id']}/receipt' : null,
      'can': {
        'decide': _can(userId, 'decide_claims') && !mine && c['status'] == 'submitted',
        'edit': mine && c['status'] == 'rejected',
        'delete': mine && c['status'] == 'submitted',
      },
    };
  }

  FakeResponse _claimList(int userId, Map<String, dynamic> query) {
    var list = _claims.where((c) => _claimVisible(c, userId)).toList()
      ..sort((a, b) => (b['updated_at'] as String).compareTo(a['updated_at'] as String));
    final status = '${query['status'] ?? ''}';
    if (status.isNotEmpty) list = list.where((c) => c['status'] == status).toList();
    final term = '${query['q'] ?? ''}'.trim().toLowerCase();
    if (term.isNotEmpty) {
      list = list
          .where((c) =>
              '${c['claim_no']}'.toLowerCase().contains(term) || '${c['description']}'.toLowerCase().contains(term))
          .toList();
    }
    return FakeResponse(200, _paginate(list.map((c) => _claimJson(c, userId)).toList(), query));
  }

  FakeResponse _decideClaim(Map<String, dynamic> c, int userId, Map<String, dynamic> json) {
    if ('${json['seen_at'] ?? ''}' != c['updated_at']) {
      return FakeResponse(409, {'message': 'Claim ${c['claim_no']} was edited since you opened it. Reload and try again.'});
    }
    if (!(_claimJson(c, userId)['can'] as Map)['decide']) {
      return FakeResponse(409, {'message': 'Claim ${c['claim_no']} is ${c['status_label']}; this action is not allowed now.'});
    }
    final decision = '${json['decision'] ?? ''}';
    final reason = '${json['reason'] ?? ''}'.trim();
    if (decision != 'approved' && decision != 'rejected') {
      throw _Validation({'decision': ['The selected decision is invalid.']});
    }
    if (decision == 'rejected' && reason.isEmpty) {
      throw _Validation({'reason': ['Please give a reason for rejecting.']});
    }
    _setClaimStatus(c, decision);
    c['decision_reason'] = reason.isEmpty ? null : reason;
    c['updated_at'] = _iso(DateTime.now());
    return FakeResponse(200, {'data': _claimJson(c, userId)});
  }

  FakeResponse _createClaim(int userId, Map<String, String> fields, Map<String, FakeUpload> files) {
    if (!_can(userId, 'submit_claim')) {
      return const FakeResponse(403, {'message': 'You may not submit claims.', 'code': 'forbidden'});
    }
    final errors = <String, List<String>>{};
    final project = _projects.where((p) => '${p['id']}' == fields['project_id']).firstOrNull;
    if (project == null) errors['project_id'] = ['Please choose a project.'];
    final category = _categories.where((p) => '${p['id']}' == fields['category_id']).firstOrNull;
    if (category == null) errors['category_id'] = ['Please choose a category.'];
    final costCode = _costCodes.where((p) => '${p['id']}' == fields['cost_code_id']).firstOrNull;
    if (DateTime.tryParse(fields['incurred_on'] ?? '') == null) errors['incurred_on'] = ['Please choose the date.'];
    final amount = Decimal.tryParse(fields['amount'] ?? '');
    if (amount == null || amount <= Decimal.zero) errors['amount'] = ['The amount must be more than 0.'];
    if ((fields['description'] ?? '').trim().isEmpty) errors['description'] = ['The description field is required.'];
    final receipt = files['receipt'];
    if (receipt != null) {
      final ext = receipt.filename.split('.').last.toLowerCase();
      if (!['pdf', 'jpg', 'jpeg', 'png'].contains(ext)) {
        errors['receipt'] = ['The receipt must be a file of type: pdf, jpg, png.'];
      } else if (receipt.length > 4 * 1024 * 1024) {
        errors['receipt'] = ['The receipt must not be greater than 4096 kilobytes.'];
      }
    }
    if (errors.isNotEmpty) throw _Validation(errors);
    final now = DateTime.now();
    final id = _nextClaimId++;
    final c = <String, dynamic>{
      'id': id,
      'claim_no': 'CLM-${now.year}-${id.toString().padLeft(4, '0')}',
      'project': project,
      'claimant': {'id': userId, 'name': _users[userId]!['name']},
      'category': category,
      'cost_code': costCode,
      'incurred_on': fields['incurred_on'],
      'amount': amount!.toStringAsFixed(2),
      'description': fields['description']!.trim(),
      'has_receipt': receipt != null,
      'decision_reason': null,
      'updated_at': _iso(now),
      '_receipt_pdf': receipt?.filename.toLowerCase().endsWith('.pdf') ?? false,
    };
    _setClaimStatus(c, 'submitted');
    _claims.add(c);
    return FakeResponse(201, {'data': _claimJson(c, userId)});
  }

  FakeResponse _receipt(Map<String, dynamic> c) {
    if (c['has_receipt'] != true) return const FakeResponse(404, {'message': 'Not found.'});
    if (c['_receipt_pdf'] == true) {
      return FakeResponse(200, null, 'application/pdf', utf8.encode(_samplePdf));
    }
    return FakeResponse(200, null, 'image/png', base64Decode(_samplePng));
  }

  static const _claimMeta = {
    'submitted': ('Awaiting approval', 'pending'),
    'approved': ('Approved', 'approved'),
    'rejected': ('Rejected', 'rejected'),
    'paid': ('Paid', 'muted'),
  };

  void _setClaimStatus(Map<String, dynamic> c, String status) {
    c['status'] = status;
    c['status_label'] = _claimMeta[status]!.$1;
    c['status_tone'] = _claimMeta[status]!.$2;
  }

  // ---------------------------------------------------------------- notifications

  FakeResponse _notificationList(int userId, Map<String, dynamic> query) {
    var list = _notifications.where((n) => n['user_id'] == userId).toList()
      ..sort((a, b) => (b['created_at'] as String).compareTo(a['created_at'] as String));
    if ('${query['unread'] ?? ''}' == '1') list = list.where((n) => n['read'] != true).toList();
    return FakeResponse(200, _paginate([
      for (final n in list)
        {for (final e in n.entries) if (e.key != 'user_id') e.key: e.value},
    ], query));
  }

  // ---------------------------------------------------------------- helpers

  Map<String, dynamic> _paginate(List<Map<String, dynamic>> all, Map<String, dynamic> query) {
    final perPage = (int.tryParse('${query['per_page'] ?? ''}') ?? 20).clamp(1, 50);
    final lastPage = max(1, (all.length / perPage).ceil());
    final page = (int.tryParse('${query['page'] ?? ''}') ?? 1).clamp(1, 1 << 30);
    final start = (page - 1) * perPage;
    final slice = start >= all.length ? <Map<String, dynamic>>[] : all.sublist(start, min(all.length, start + perPage));
    return {
      'data': slice,
      'meta': {'current_page': page, 'last_page': lastPage, 'per_page': perPage, 'total': all.length},
    };
  }

  static String _fmtQty(Decimal d) => d.toStringAsFixed(3);
  static String _trim(Decimal d) => d.toString();

  /// ISO 8601 with a fixed +03:00 offset, like the server (Africa/Kampala).
  static String _iso(DateTime t) {
    final eat = t.toUtc().add(const Duration(hours: 3));
    String two(int v) => v.toString().padLeft(2, '0');
    return '${eat.year}-${two(eat.month)}-${two(eat.day)}T${two(eat.hour)}:${two(eat.minute)}:${two(eat.second)}+03:00';
  }

  static String _ymd(DateTime t) =>
      '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  // ---------------------------------------------------------------- seed data

  void _seed() {
    Map<String, dynamic> user(int id, String name, String email, String role, String label, Map<String, bool> can,
            {bool mustChange = false}) =>
        {
          'id': id,
          'name': name,
          'email': email,
          'phone': '+256 700 000 ${id.toString().padLeft(3, '0')}',
          'role': role,
          'role_label': label,
          'must_change_password': mustChange,
          'currency': 'UGX',
          'currency_decimals': 0,
          'company_name': 'Kemmy Tech',
          'can': can,
          'is_active': true,
        };
    const pmCan = {
      'approve_requests': true,
      'create_request': true,
      'submit_claim': true,
      'decide_claims': true,
      'view_stock_value': true,
      'view_claims': true,
    };
    _users[3] = user(3, 'Brian Okello', 'brian@kemmy.test', 'project_manager', 'Project Manager', pmCan);
    _users[5] = user(5, 'Jane Akello', 'engineer@kemmy.test', 'site_engineer', 'Site Engineer', const {
      'approve_requests': false,
      'create_request': true,
      'submit_claim': true,
      'decide_claims': false,
      'view_stock_value': false,
      'view_claims': true,
    });
    _users[8] = user(8, 'Peter Ssemwogerere', 'peter@kemmy.test', 'site_engineer', 'Site Engineer', const {});
    _users[9] = user(9, 'Grace Nansubuga', 'new@kemmy.test', 'project_manager', 'Project Manager', pmCan,
        mustChange: true);
    for (final id in _users.keys) {
      _passwords[id] = password;
    }

    _projects.addAll([
      {'id': 1, 'code': 'PRJ-001', 'name': 'Kira Road Apartments'},
      {'id': 2, 'code': 'PRJ-002', 'name': 'Ntinda Office Block'},
      {'id': 3, 'code': 'PRJ-003', 'name': 'Mukono Warehouse'},
    ]);
    _stores.addAll([
      {'id': 1, 'code': 'MAIN', 'name': 'Main Store'},
      {'id': 2, 'code': 'KIRA', 'name': 'Kira Site Store'},
    ]);
    _categories.addAll([
      {'id': 1, 'name': 'Transport'},
      {'id': 2, 'name': 'Meals & allowances'},
      {'id': 3, 'name': 'Small purchases'},
      {'id': 4, 'name': 'Equipment hire'},
    ]);
    _costCodes.addAll([
      {'id': 1, 'code': '01-100', 'name': 'Site preliminaries'},
      {'id': 2, 'code': '03-300', 'name': 'Concrete works'},
      {'id': 3, 'code': '05-500', 'name': 'Finishes'},
    ]);

    const items = [
      ('CEM-425', 'Cement 42.5R', 'Cement & aggregates', 'bag', '20.000', '32000.00'),
      ('CEM-325', 'Cement 32.5N', 'Cement & aggregates', 'bag', '20.000', '29500.00'),
      ('SND-PLS', 'Plaster sand', 'Cement & aggregates', 'tonne', '5.000', '45000.00'),
      ('AGG-20', 'Aggregate 20mm', 'Cement & aggregates', 'tonne', '5.000', '68000.00'),
      ('REB-Y12', 'Rebar Y12 (12 m)', 'Steel', 'length', '50.000', '41000.00'),
      ('REB-Y16', 'Rebar Y16 (12 m)', 'Steel', 'length', '40.000', '72500.00'),
      ('REB-Y10', 'Rebar Y10 (12 m)', 'Steel', 'length', '50.000', '28500.00'),
      ('BND-WIR', 'Binding wire', 'Steel', 'kg', '25.000', '7500.00'),
      ('BRK-STD', 'Burnt clay bricks', 'Masonry', 'piece', '2000.000', '350.00'),
      ('BLK-150', 'Concrete block 150mm', 'Masonry', 'piece', '500.000', '2800.00'),
      ('TMB-2X4', 'Timber 2x4 (14 ft)', 'Timber', 'piece', '60.000', '18000.00'),
      ('PLY-18', 'Plywood 18mm', 'Timber', 'sheet', '20.000', '95000.00'),
      ('NAI-4IN', 'Nails 4 inch', 'Fixings', 'kg', '20.000', '8500.00'),
      ('NAI-3IN', 'Nails 3 inch', 'Fixings', 'kg', '20.000', '8500.00'),
      ('PVC-110', 'PVC pipe 110mm', 'Plumbing', 'length', '15.000', '54000.00'),
      ('PPR-25', 'PPR pipe 25mm', 'Plumbing', 'length', '30.000', '16500.00'),
      ('CBL-2.5', 'Cable 2.5mm² (100 m)', 'Electrical', 'roll', '5.000', '185000.00'),
      ('CBL-1.5', 'Cable 1.5mm² (100 m)', 'Electrical', 'roll', '5.000', '125000.00'),
      ('PNT-WHT', 'Emulsion paint white (20 L)', 'Finishes', 'bucket', '10.000', '165000.00'),
      ('TIL-600', 'Floor tile 600x600', 'Finishes', 'm²', '50.000', '38500.00'),
      ('IRS-G28', 'Iron sheet G28', 'Roofing', 'sheet', '100.000', '36000.00'),
      ('DPC-ROL', 'DPC membrane roll', 'Waterproofing', 'roll', '4.000', '98000.00'),
      ('GLV-HVY', 'Gloves heavy duty', 'Safety', 'pair', '30.000', '6500.00'),
      ('HLM-STD', 'Safety helmet', 'Safety', 'piece', '10.000', '22000.00'),
      ('OLD-LIME', 'Hydrated lime (discontinued)', 'Cement & aggregates', 'bag', '0.000', '18000.00'),
    ];
    final rnd = Random(42);
    for (var i = 0; i < items.length; i++) {
      final (sku, name, cat, unit, min, cost) = items[i];
      final id = i + 1;
      _items.add({
        'id': id,
        'sku': sku,
        'name': name,
        'category': cat,
        'unit': unit,
        'min_level': min,
        'is_active': !sku.startsWith('OLD'),
        'avg_cost': cost,
      });
      final minD = Decimal.parse(min);
      // Every fourth item is low in total; the rest comfortably stocked.
      final factor = i % 4 == 1 ? 0.4 : 1.5 + rnd.nextDouble() * 4;
      final main = Decimal.parse(((double.parse(min) * factor).round()).toString());
      final site = Decimal.parse(((double.parse(min) * factor * 0.3).round()).toString());
      _balances[id] = {1: main, 2: i % 3 == 0 ? site : Decimal.zero};
      if (minD == Decimal.zero) _balances[id] = {1: Decimal.parse('12.5'), 2: Decimal.zero};
    }

    const purposes = [
      'Slab casting, 2nd floor',
      'Column reinforcement block B',
      'Blockwork ground floor',
      'Roofing phase 1',
      'Plumbing first fix',
      'Electrical conduits',
      'Formwork for staircase',
      'Plastering internal walls',
      'Foundation footings',
      'Site safety top-up',
    ];
    const statuses = [
      'submitted', 'submitted', 'pm_approved', 'submitted', 'overall_approved', 'draft',
      'partially_issued', 'pm_rejected', 'fully_issued', 'pm_approved', 'cancelled', 'overall_rejected',
    ];
    final requesters = [
      {'id': 5, 'name': 'Jane Akello'},
      {'id': 8, 'name': 'Peter Ssemwogerere'},
      {'id': 3, 'name': 'Brian Okello'},
    ];
    for (var i = 0; i < 32; i++) {
      final id = _nextRequestId++;
      final status = statuses[i % statuses.length];
      final created = _now.subtract(Duration(days: 32 - i, hours: rnd.nextInt(8)));
      // Keep requests waiting for the PM persona away from their own requests.
      var requester = requesters[i % requesters.length];
      if (['submitted', 'pm_approved'].contains(status) && requester['id'] == 3) requester = requesters[0];
      if (status == 'draft') requester = requesters[i % 2 == 0 ? 0 : 2];
      final lineCount = 1 + rnd.nextInt(4);
      final lines = <Map<String, dynamic>>[];
      final used = <int>{};
      for (var j = 0; j < lineCount; j++) {
        var itemIdx = rnd.nextInt(items.length - 1);
        while (used.contains(itemIdx)) {
          itemIdx = (itemIdx + 1) % (items.length - 1);
        }
        used.add(itemIdx);
        final item = _items[itemIdx];
        final requested = Decimal.fromInt(5 + rnd.nextInt(60));
        final pmApproved = ['pm_approved', 'overall_approved', 'partially_issued', 'fully_issued', 'overall_rejected']
                .contains(status)
            ? (j == 0 && requested > Decimal.fromInt(10) ? requested - Decimal.fromInt(5) : requested)
            : null;
        final finalApproved = ['overall_approved', 'partially_issued', 'fully_issued'].contains(status) ? pmApproved : null;
        final issued = switch (status) {
          'fully_issued' => finalApproved,
          'partially_issued' => j == 0 ? finalApproved : Decimal.zero,
          _ => Decimal.zero,
        };
        lines.add({
          'id': _nextLineId++,
          'item': {'id': item['id'], 'sku': item['sku'], 'name': item['name'], 'unit': item['unit']},
          'requested_qty': _fmtQty(requested),
          'pm_approved_qty': pmApproved == null ? null : _fmtQty(pmApproved),
          'final_approved_qty': finalApproved == null ? null : _fmtQty(finalApproved),
          'issued_qty': _fmtQty(issued ?? Decimal.zero),
          'notes': j == 1 ? 'Deliver to block B' : null,
        });
      }
      final approvals = <Map<String, dynamic>>[];
      final pm = {'id': 3, 'name': 'Brian Okello'};
      final director = {'id': 1, 'name': 'Kenneth Waks'};
      String at(int h) => _iso(created.add(Duration(hours: h)));
      if (status != 'draft' && status != 'submitted' && status != 'cancelled') {
        approvals.add({
          'step': 'pm',
          'version': 1,
          'decision': status == 'pm_rejected' ? 'rejected' : 'approved',
          'reason': status == 'pm_rejected' ? 'Quantities exceed the BOQ for this stage.' : null,
          'approver': pm,
          'was_substitute': false,
          'decided_at': at(5),
        });
      }
      if (['overall_approved', 'partially_issued', 'fully_issued', 'overall_rejected'].contains(status)) {
        approvals.add({
          'step': 'overall',
          'version': 1,
          'decision': status == 'overall_rejected' ? 'rejected' : 'approved',
          'reason': status == 'overall_rejected' ? 'Budget for this phase is exhausted.' : null,
          'approver': director,
          'was_substitute': i % 5 == 0,
          'decided_at': at(20),
        });
      }
      final project = _projects[i % _projects.length];
      final r = <String, dynamic>{
        'id': id,
        'request_no': 'MR-${created.year}-${id.toString().padLeft(4, '0')}',
        'version': i % 7 == 0 ? 2 : 1,
        'project': project,
        'requester': requester,
        'project_manager': status == 'draft' ? null : pm,
        'purpose': purposes[i % purposes.length],
        'needed_on': _ymd(created.add(Duration(days: 7 + i % 10))),
        'submitted_at': status == 'draft' ? null : at(1),
        'created_at': _iso(created),
        'lines': lines,
        'approvals': approvals,
      };
      _setStatus(r, status);
      _requests.add(r);
    }

    const claimDescs = [
      'Boda boda transport for site supervisor',
      'Lunch allowance for casual labourers',
      'Padlocks for site store',
      'Water pump hire (1 day)',
      'Fuel for generator',
      'Printing of drawings',
    ];
    const claimStatuses = ['submitted', 'submitted', 'approved', 'rejected', 'paid', 'submitted', 'approved'];
    final claimants = [
      {'id': 5, 'name': 'Jane Akello'},
      {'id': 8, 'name': 'Peter Ssemwogerere'},
      {'id': 3, 'name': 'Brian Okello'},
    ];
    for (var i = 0; i < 18; i++) {
      final id = _nextClaimId++;
      final t = _now.subtract(Duration(days: 18 - i, hours: rnd.nextInt(10)));
      var claimant = claimants[i % claimants.length];
      final status = claimStatuses[i % claimStatuses.length];
      if (status == 'submitted' && claimant['id'] == 3) claimant = claimants[0];
      final c = <String, dynamic>{
        'id': id,
        'claim_no': 'CLM-${t.year}-${id.toString().padLeft(4, '0')}',
        'project': _projects[i % _projects.length],
        'claimant': claimant,
        'category': _categories[i % _categories.length],
        'cost_code': i % 3 == 0 ? null : _costCodes[i % _costCodes.length],
        'incurred_on': _ymd(t.subtract(const Duration(days: 1))),
        'amount': '${(5 + rnd.nextInt(180)) * 1000}.00',
        'description': claimDescs[i % claimDescs.length],
        'has_receipt': i % 4 != 3,
        'decision_reason': status == 'rejected' ? 'No receipt attached for this amount.' : null,
        'updated_at': _iso(t),
        '_receipt_pdf': i % 2 == 1,
      };
      _setClaimStatus(c, status);
      _claims.add(c);
    }

    var nid = 1;
    for (final uid in [3, 5, 9]) {
      final waiting = _requests.where((r) => r['status'] == 'submitted').take(4).toList();
      for (var i = 0; i < waiting.length; i++) {
        final r = waiting[i];
        _notifications.add({
          'id': 'f${(nid++).toString().padLeft(7, '0')}-0000-4000-8000-000000000000',
          'user_id': uid,
          'subject': 'Request ${r['request_no']} needs your approval',
          'body': '${r['requester']['name']} requested materials for ${r['project']['name']}: ${r['purpose']}.',
          'kind': 'warn',
          'read': i > 1,
          'created_at': _iso(_now.subtract(Duration(hours: 2 + i * 9))),
          'target': {'type': 'request', 'id': r['id']},
        });
      }
      final approved = _requests.where((r) => r['status'] == 'overall_approved').take(2);
      for (final r in approved) {
        _notifications.add({
          'id': 'f${(nid++).toString().padLeft(7, '0')}-0000-4000-8000-000000000000',
          'user_id': uid,
          'subject': 'Request ${r['request_no']} was approved',
          'body': 'All lines approved. The store will issue the materials.',
          'kind': 'ok',
          'read': true,
          'created_at': _iso(_now.subtract(const Duration(days: 2))),
          'target': {'type': 'request', 'id': r['id']},
        });
      }
      final claim = _claims.firstWhere((c) => c['status'] == 'rejected');
      _notifications.add({
        'id': 'f${(nid++).toString().padLeft(7, '0')}-0000-4000-8000-000000000000',
        'user_id': uid,
        'subject': 'Claim ${claim['claim_no']} was rejected',
        'body': 'Reason: ${claim['decision_reason']}',
        'kind': 'bad',
        'read': false,
        'created_at': _iso(_now.subtract(const Duration(hours: 30))),
        'target': {'type': 'claim', 'id': claim['id']},
      });
      _notifications.add({
        'id': 'f${(nid++).toString().padLeft(7, '0')}-0000-4000-8000-000000000000',
        'user_id': uid,
        'subject': 'Low stock: Rebar Y16',
        'body': 'Main Store is below the minimum level.',
        'kind': 'info',
        'read': true,
        'created_at': _iso(_now.subtract(const Duration(days: 4))),
        'target': {'type': null, 'id': null},
      });
    }
  }

  /// 1x1 PNG (keeps the fake mode free of binary assets).
  static const _samplePng =
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

  static const _samplePdf = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n'
      '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
      '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n'
      '4 0 obj<</Length 55>>stream\nBT /F1 18 Tf 40 70 Td (Sample receipt - demo) Tj ET\nendstream endobj\n'
      '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n'
      'trailer<</Root 1 0 R>>\n%%EOF\n';
}

class _Validation implements Exception {
  _Validation(this.errors);
  final Map<String, List<String>> errors;
}
