import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/router/app_router.dart';
import 'package:store_system/features/auth/data/auth_controller.dart';
import 'package:store_system/features/auth/domain/user.dart';

import '../helpers.dart';

User _user({bool mustChange = false, bool approve = true}) => User.fromJson({
      'id': 1,
      'name': 'A',
      'must_change_password': mustChange,
      'can': {'approve_requests': approve},
    });

void main() {
  group('authRedirect', () {
    test('unknown / restore failed -> splash', () {
      expect(authRedirect(const AuthUnknown(), '/home'), '/splash');
      expect(authRedirect(const AuthUnknown(), '/splash'), isNull);
      expect(authRedirect(const AuthRestoreFailed('offline'), '/request/3'), '/splash');
    });

    test('signed out -> login', () {
      expect(authRedirect(const AuthSignedOut(), '/home'), '/login');
      expect(authRedirect(const AuthSignedOut(), '/login'), isNull);
    });

    test('must change password is forced', () {
      final s = AuthSignedIn(_user(mustChange: true));
      expect(authRedirect(s, '/home'), '/change-password');
      expect(authRedirect(s, '/request/1'), '/change-password');
      expect(authRedirect(s, '/change-password'), isNull);
    });

    test('signed in leaves splash/login, guards approvals', () {
      expect(authRedirect(AuthSignedIn(_user()), '/login'), '/home');
      expect(authRedirect(AuthSignedIn(_user()), '/splash'), '/home');
      expect(authRedirect(AuthSignedIn(_user()), '/approvals'), isNull);
      expect(authRedirect(AuthSignedIn(_user(approve: false)), '/approvals'), '/home');
      expect(authRedirect(AuthSignedIn(_user()), '/change-password'), isNull);
    });
  });

  group('FakeBackend contract behaviour', () {
    late FakeBackend b;
    late String auth;

    setUp(() {
      b = FakeBackend();
      auth = 'Bearer ${fakeLogin(b)}';
    });

    Map<String, dynamic> firstApproval() {
      final res = b.handle(method: 'GET', path: 'approvals', authorization: auth);
      return ((res.body as Map)['data'] as List).first as Map<String, dynamic>;
    }

    test('stale version -> 409', () {
      final r = firstApproval();
      final res = b.handle(
        method: 'POST',
        path: 'requests/${r['id']}/decision',
        authorization: auth,
        body: {'decision': 'approved', 'version': (r['version'] as int) + 1},
      );
      expect(res.status, 409);
    });

    test('qty above ceiling -> 422 on qty.<line>', () {
      final r = firstApproval();
      final detail = (b.handle(method: 'GET', path: 'requests/${r['id']}', authorization: auth).body as Map)['data'] as Map;
      final line = (detail['lines'] as List).first as Map;
      final res = b.handle(
        method: 'POST',
        path: 'requests/${r['id']}/decision',
        authorization: auth,
        body: {
          'decision': 'approved',
          'version': r['version'],
          'qty': {'${line['id']}': '99999'},
        },
      );
      expect(res.status, 422);
      expect(((res.body as Map)['errors'] as Map).keys, contains('qty.${line['id']}'));
    });

    test('reject without reason -> 422, with reason -> 200', () {
      final r = firstApproval();
      final bad = b.handle(
        method: 'POST',
        path: 'requests/${r['id']}/decision',
        authorization: auth,
        body: {'decision': 'rejected', 'version': r['version']},
      );
      expect(bad.status, 422);
      final ok = b.handle(
        method: 'POST',
        path: 'requests/${r['id']}/decision',
        authorization: auth,
        body: {'decision': 'rejected', 'reason': 'No budget', 'version': r['version']},
      );
      expect(ok.status, 200);
      expect(((ok.body as Map)['data'] as Map)['status_tone'], 'rejected');
    });

    test('engineer cannot see avg_cost', () {
      final eng = 'Bearer ${fakeLogin(b, email: 'engineer@kemmy.test')}';
      final items = ((b.handle(method: 'GET', path: 'items', authorization: eng).body as Map)['data'] as List);
      expect((items.first as Map).containsKey('avg_cost'), isFalse);
      final pmItems = ((b.handle(method: 'GET', path: 'items', authorization: auth).body as Map)['data'] as List);
      expect((pmItems.first as Map).containsKey('avg_cost'), isTrue);
    });
  });
}
