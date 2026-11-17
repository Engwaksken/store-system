import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/config/app_config.dart';
import 'package:store_system/core/config/branding.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/router/app_router.dart';
import 'package:store_system/features/auth/data/auth_controller.dart';
import 'package:store_system/features/auth/domain/user.dart';
import 'package:store_system/features/feedback/domain/feedback_models.dart';
import 'package:store_system/features/users/domain/account_models.dart';

import '../helpers.dart';

User _user({bool manageUsers = false}) => User.fromJson({
      'id': 1,
      'name': 'A',
      'company_name': 'Acme Builders',
      'app_subtitle': 'Stores',
      'can': {'manage_users': manageUsers, 'manage_feedback': manageUsers},
    });

void main() {
  group('system name', () {
    test('branding falls back when the server sends no name', () {
      expect(AppBranding.fromJson({'company_name': ''}).name, AppConfig.fallbackAppName);
      final b = AppBranding.fromJson({'company_name': ' Acme ', 'app_subtitle': 'Store & Projects'});
      expect(b.name, 'Acme');
      expect(b.subtitle, 'Store & Projects');
    });

    test('appNameProvider uses /branding', () async {
      final container = ProviderContainer(overrides: fakeOverrides());
      addTearDown(container.dispose);
      await container.read(appBrandingProvider.future);
      expect(container.read(appNameProvider), 'Kemmy Tech');
      expect(container.read(appSubtitleProvider), 'Store & Projects');
    });

    test('user json carries admin permissions and subtitle', () {
      final u = _user(manageUsers: true);
      expect(u.can.manageUsers, isTrue);
      expect(u.can.manageFeedback, isTrue);
      expect(u.appSubtitle, 'Stores');
      expect(User.fromJson(u.toJson()).can.manageUsers, isTrue);
    });
  });

  test('router keeps non-admins out of /users', () {
    expect(authRedirect(AuthSignedIn(_user()), '/users'), '/more');
    expect(authRedirect(AuthSignedIn(_user()), '/users/3'), '/more');
    expect(authRedirect(AuthSignedIn(_user(manageUsers: true)), '/users/new'), isNull);
    expect(authRedirect(AuthSignedIn(_user()), '/feedback'), isNull);
  });

  group('fake backend: users', () {
    late FakeBackend b;
    late String admin;

    setUp(() {
      b = FakeBackend();
      admin = 'Bearer ${fakeLogin(b)}';
    });

    test('non-admins get 403', () {
      final engineer = 'Bearer ${fakeLogin(b, email: 'engineer@kemmy.test')}';
      expect(b.handle(method: 'GET', path: 'users', authorization: engineer).status, 403);
    });

    test('create, list and edit a user', () {
      final missingProject = b.handle(method: 'POST', path: 'users', authorization: admin, body: {
        'name': 'Sam',
        'email': 'sam@kemmy.test',
        'role': 'staff',
        'is_active': true,
      });
      expect(missingProject.status, 422);

      final created = b.handle(method: 'POST', path: 'users', authorization: admin, body: {
        'name': 'Sam',
        'email': 'sam@kemmy.test',
        'role': 'staff',
        'is_active': true,
        'project_ids': [1],
      });
      expect(created.status, 201);
      final result = SaveAccountResult.fromJson(created.body as Map<String, dynamic>);
      expect(result.account.projects.single.code, 'PRJ-001');
      expect(result.credentialsEmailed, isTrue);

      final list = b.handle(method: 'GET', path: 'users', authorization: admin, query: {'q': 'sam@'});
      expect(((list.body as Map)['data'] as List).length, 1);

      final updated = b.handle(method: 'PUT', path: 'users/${result.account.id}', authorization: admin, body: {
        ...const AccountInput(name: 'Sam O', email: 'sam@kemmy.test', role: 'storekeeper', isActive: false).toJson(),
      });
      expect(updated.status, 200);
      final a = SaveAccountResult.fromJson(updated.body as Map<String, dynamic>).account;
      expect(a.isActive, isFalse);
      expect(a.roleLabel, 'Storekeeper');
    });

    test('admin cannot deactivate themselves', () {
      final res = b.handle(method: 'PUT', path: 'users/3', authorization: admin, body: {
        'name': 'Brian Okello',
        'email': 'brian@kemmy.test',
        'role': 'project_manager',
        'is_active': false,
      });
      expect(res.status, 422);
    });

    test('options parse', () {
      final o = UserFormOptions.fromJson(
        b.handle(method: 'GET', path: 'users/options', authorization: admin).body as Map<String, dynamic>,
      );
      expect(o.roles.map((r) => r.value), contains('staff'));
      expect(o.projects, hasLength(3));
      expect(o.roleLabel('admin'), 'Owner / Admin');
    });
  });

  group('fake backend: feedback', () {
    late FakeBackend b;
    late String admin;
    late String engineer;

    setUp(() {
      b = FakeBackend();
      admin = 'Bearer ${fakeLogin(b)}';
      engineer = 'Bearer ${fakeLogin(b, email: 'engineer@kemmy.test')}';
    });

    test('send, see own only, admin replies', () {
      final bad = b.handle(method: 'POST', path: 'feedback', authorization: engineer, body: {'category': 'bug'});
      expect(bad.status, 422);

      final sent = b.handle(method: 'POST', path: 'feedback', authorization: engineer, body: {
        'category': 'bug',
        'subject': 'Crash',
        'message': 'It closes',
        'rating': 2,
        'device': 'Pixel',
      });
      expect(sent.status, 201);
      final item = FeedbackItem.fromJson((sent.body as Map)['data'] as Map<String, dynamic>);
      expect(item.reference, 'FB-00001');
      expect(item.statusTone, 'pending');
      expect(item.device, isNull, reason: 'device details are for admins');
      expect(item.canRespond, isFalse);

      // The admin sees it and can reply; the engineer cannot reply.
      final all = b.handle(method: 'GET', path: 'feedback', authorization: admin);
      expect(((all.body as Map)['data'] as List).length, 1);
      final mine = b.handle(method: 'GET', path: 'feedback', authorization: admin, query: {'scope': 'mine'});
      expect(((mine.body as Map)['data'] as List), isEmpty);
      expect(
        b.handle(method: 'POST', path: 'feedback/${item.id}/respond', authorization: engineer, body: {'status': 'closed'}).status,
        403,
      );
      final replied = b.handle(
        method: 'POST',
        path: 'feedback/${item.id}/respond',
        authorization: admin,
        body: {'status': 'resolved', 'response': 'Fixed'},
      );
      final r = FeedbackItem.fromJson((replied.body as Map)['data'] as Map<String, dynamic>);
      expect(r.status, 'resolved');
      expect(r.hasReply, isTrue);
      expect(r.responder?.name, 'Brian Okello');
      expect(r.device, 'Pixel');
    });

    test('rating stars', () {
      expect(ratingStars(3), '★★★☆☆');
      expect(ratingStars(9), '★★★★★');
    });
  });
}
