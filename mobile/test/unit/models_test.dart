import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/paginated.dart';
import 'package:store_system/features/auth/domain/user.dart';
import 'package:store_system/features/claims/domain/claim_models.dart';
import 'package:store_system/features/dashboard/domain/dashboard_models.dart';
import 'package:store_system/features/notifications/domain/notification_models.dart';
import 'package:store_system/features/requests/domain/request_models.dart';
import 'package:store_system/features/stock/domain/stock_models.dart';

void main() {
  group('User', () {
    test('parses every field incl. can flags', () {
      final u = User.fromJson({
        'id': 7,
        'name': 'Brian Okello',
        'email': 'brian@example.com',
        'phone': '+256700000000',
        'role': 'project_manager',
        'role_label': 'Project Manager',
        'must_change_password': false,
        'currency': 'UGX',
        'currency_decimals': 0,
        'company_name': 'Kemmy Tech',
        'can': {
          'approve_requests': true,
          'create_request': true,
          'submit_claim': true,
          'decide_claims': true,
          'view_stock_value': false,
          'view_claims': true,
        },
      });
      expect(u.id, 7);
      expect(u.roleLabel, 'Project Manager');
      expect(u.currencyDecimals, 0);
      expect(u.can.approveRequests, isTrue);
      expect(u.can.viewStockValue, isFalse);
      expect(u.initials, 'BO');
      expect(u.mustChangePassword, isFalse);
    });

    test('tolerates nulls and missing can', () {
      final u = User.fromJson({'id': '3', 'name': 'Jane', 'phone': null, 'must_change_password': 1});
      expect(u.id, 3);
      expect(u.phone, isNull);
      expect(u.mustChangePassword, isTrue);
      expect(u.can.createRequest, isFalse);
      expect(u.initials, 'J');
    });
  });

  group('Requests', () {
    final summaryJson = {
      'id': 12,
      'request_no': 'MR-2026-0012',
      'version': 1,
      'status': 'submitted',
      'status_label': 'Awaiting PM',
      'status_tone': 'pending',
      'project': {'id': 1, 'code': 'PRJ-001', 'name': 'Kira Road'},
      'requester': {'id': 5, 'name': 'Jane'},
      'purpose': 'Slab casting',
      'needed_on': '2026-11-20',
      'lines_count': 3,
      'submitted_at': '2026-11-14T09:30:00+03:00',
      'created_at': '2026-11-14T09:00:00+03:00',
    };

    test('RequestSummary', () {
      final r = RequestSummary.fromJson(summaryJson);
      expect(r.requestNo, 'MR-2026-0012');
      expect(r.statusTone, 'pending');
      expect(r.project!.label, 'PRJ-001 · Kira Road');
      expect(r.requester!.name, 'Jane');
      expect(r.neededOn, '2026-11-20');
      expect(r.linesCount, 3);
    });

    test('RequestDetail keeps decimal strings, nulls, approvals and can', () {
      final d = RequestDetail.fromJson({
        ...summaryJson,
        'project_manager': null,
        'lines': [
          {
            'id': 31,
            'item': {'id': 4, 'sku': 'CEM-425', 'name': 'Cement 42.5R', 'unit': 'bag'},
            'requested_qty': '20.000',
            'pm_approved_qty': null,
            'final_approved_qty': null,
            'issued_qty': '0.000',
            'on_hand': '140.000',
            'notes': null,
          },
          {
            'id': 32,
            'item': {'id': 5, 'sku': 'REB', 'name': 'Rebar', 'unit': 'length'},
            'requested_qty': '12.500',
            'pm_approved_qty': '10.250',
            'final_approved_qty': null,
            'issued_qty': '0.000',
            'on_hand': '3.000',
            'notes': 'Block B',
          },
        ],
        'approvals': [
          {
            'step': 'pm',
            'version': 1,
            'decision': 'approved',
            'reason': null,
            'approver': {'id': 3, 'name': 'PM'},
            'was_substitute': false,
            'decided_at': '2026-11-14T10:00:00+03:00',
          },
        ],
        'can': {'decide': true, 'decision_step': 'overall', 'max_qty_field': 'pm_approved_qty', 'cancel': false},
      });
      expect(d.projectManager, isNull);
      expect(d.lines, hasLength(2));
      expect(d.lines.first.requestedQty, '20.000');
      expect(d.lines.first.pmApprovedQty, isNull);
      expect(d.lines.first.notes, isNull);
      expect(d.lines[1].pmApprovedQty, '10.250');
      expect(d.approvals.single.stepLabel, 'Project manager');
      expect(d.approvals.single.wasSubstitute, isFalse);
      expect(d.can.decide, isTrue);
      expect(d.can.decisionStep, 'overall');
      // Ceiling follows can.max_qty_field.
      expect(d.ceilingFor(d.lines[1]), '10.250');
      expect(d.ceilingFor(d.lines[0]), '0'); // pm_approved_qty null -> 0
    });

    test('numeric quantities are converted to strings, never doubles', () {
      final l = RequestLine.fromJson({
        'id': 1,
        'item': {'id': 1, 'sku': 'X', 'name': 'X', 'unit': 'bag'},
        'requested_qty': 20,
        'issued_qty': 1.5,
      });
      expect(l.requestedQty, '20');
      expect(l.issuedQty, '1.5');
      expect(l.onHand, isNull);
    });
  });

  group('Stock', () {
    test('Item without avg_cost (no stock value permission)', () {
      final i = Item.fromJson({
        'id': 4,
        'sku': 'CEM-425',
        'name': 'Cement 42.5R',
        'category': 'Cement & aggregates',
        'unit': 'bag',
        'on_hand': '140.000',
        'min_level': '20.000',
        'low': false,
        'is_active': true,
      });
      expect(i.avgCost, isNull);
      expect(i.onHand, '140.000');
      expect(i.low, isFalse);
    });

    test('ItemDetail with avg_cost and balances', () {
      final d = ItemDetail.fromJson({
        'id': 4,
        'sku': 'CEM-425',
        'name': 'Cement',
        'category': null,
        'unit': 'bag',
        'on_hand': '140.000',
        'min_level': '20.000',
        'low': true,
        'is_active': true,
        'avg_cost': '32000.00',
        'balances': [
          {
            'store': {'id': 1, 'code': 'MAIN', 'name': 'Main Store'},
            'on_hand': '100.000',
          },
          {
            'store': {'id': 2, 'code': 'KIRA', 'name': 'Kira'},
            'on_hand': '40.000',
          },
        ],
      });
      expect(d.item.avgCost, '32000.00');
      expect(d.item.category, isNull);
      expect(d.item.low, isTrue);
      expect(d.balances.map((b) => b.store.code), ['MAIN', 'KIRA']);
      expect(d.balances.last.onHand, '40.000');
    });
  });

  group('Claims', () {
    test('Claim with null cost code and no receipt', () {
      final c = Claim.fromJson({
        'id': 9,
        'claim_no': 'CLM-2026-0009',
        'status': 'submitted',
        'status_label': 'Awaiting approval',
        'status_tone': 'pending',
        'project': {'id': 1, 'code': 'PRJ-001', 'name': 'Kira'},
        'claimant': {'id': 5, 'name': 'Jane'},
        'category': {'id': 2, 'name': 'Transport'},
        'cost_code': null,
        'incurred_on': '2026-11-12',
        'amount': '45000.00',
        'description': 'Boda',
        'has_receipt': false,
        'receipt_url': null,
        'decision_reason': null,
        'updated_at': '2026-11-14T09:30:00+03:00',
        'can': {'decide': true, 'edit': false, 'delete': false},
      });
      expect(c.costCode, isNull);
      expect(c.receiptUrl, isNull);
      expect(c.amount, '45000.00');
      expect(c.updatedAt, '2026-11-14T09:30:00+03:00'); // sent back verbatim as seen_at
      expect(c.can.decide, isTrue);
      expect(c.category!.name, 'Transport');
    });

    test('ClaimOptions', () {
      final o = ClaimOptions.fromJson({
        'projects': [
          {'id': 1, 'code': 'P1', 'name': 'One'},
        ],
        'categories': [
          {'id': 1, 'name': 'Transport'},
        ],
        'cost_codes': [],
      });
      expect(o.projects.single.code, 'P1');
      expect(o.categories.single.name, 'Transport');
      expect(o.costCodes, isEmpty);
    });
  });

  group('Notifications & dashboard', () {
    test('Notification target routes', () {
      final n = AppNotification.fromJson({
        'id': 'abc-uuid',
        'subject': 'Request MR-0012 needs your approval',
        'body': '...',
        'kind': 'warn',
        'read': false,
        'created_at': '2026-11-14T09:30:00+03:00',
        'target': {'type': 'request', 'id': 12},
      });
      expect(n.target!.route, '/request/12');
      expect(n.markRead().read, isTrue);

      final claim = AppNotification.fromJson({
        'id': 'x',
        'target': {'type': 'claim', 'id': '9'},
      });
      expect(claim.target!.route, '/claims/9');

      final none = AppNotification.fromJson({
        'id': 'y',
        'target': {'type': null, 'id': null},
      });
      expect(none.target!.route, isNull);
      expect(none.kind, 'info');
    });

    test('Dashboard', () {
      final d = Dashboard.fromJson({
        'cards': [
          {'key': 'awaiting_me', 'label': 'Waiting for my approval', 'value': 3, 'tone': 'gold'},
          {'key': 'x', 'label': 'X', 'value': '1500.00', 'tone': ''},
        ],
        'awaiting_me': [
          {'id': 1, 'request_no': 'MR-1', 'status_tone': 'pending'},
        ],
        'unread_notifications': 2,
      });
      expect(d.cards.first.value, 3);
      expect(d.cards.last.value, '1500.00');
      expect(d.awaitingMe.single.requestNo, 'MR-1');
      expect(d.unreadNotifications, 2);
    });

    test('Paginated meta', () {
      final p = Paginated.fromJson({
        'data': [
          {'id': 'a', 'subject': 's'},
        ],
        'meta': {'current_page': 1, 'last_page': 4, 'per_page': 20, 'total': 73},
      }, AppNotification.fromJson);
      expect(p.data, hasLength(1));
      expect(p.meta.total, 73);
      expect(p.meta.hasMore, isTrue);

      final last = Paginated.fromJson({
        'data': [],
        'meta': {'current_page': 4, 'last_page': 4, 'per_page': 20, 'total': 73},
      }, AppNotification.fromJson);
      expect(last.meta.hasMore, isFalse);
    });
  });
}
