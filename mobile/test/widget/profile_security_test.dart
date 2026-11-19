import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:store_system/app.dart';
import 'package:store_system/core/api/token_storage.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/security/biometric_lock.dart';
import 'package:store_system/features/auth/data/auth_controller.dart';

import '../helpers.dart';

class _FakeBiometrics implements BiometricAuth {
  _FakeBiometrics({this.succeed = true});
  bool supported = true;
  bool succeed;
  int prompts = 0;

  @override
  Future<bool> available() async => supported;

  @override
  Future<bool> authenticate(String reason) async {
    prompts++;
    return succeed;
  }
}

Future<void> _start(
  WidgetTester tester, {
  required FakeBackend backend,
  required _FakeBiometrics bio,
  MemoryBiometricStore? store,
  MemoryTokenStorage? tokens,
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  final s = store ?? MemoryBiometricStore();
  await tester.pumpWidget(ProviderScope(
    retry: (_, _) => null,
    overrides: [
      ...fakeOverrides(backend: backend, tokens: tokens),
      biometricAuthProvider.overrideWithValue(bio),
      biometricStoreProvider.overrideWithValue(s),
      initialBiometricCredentialProvider.overrideWithValue(s.value),
    ],
    child: const StoreSystemApp(),
  ));
  await tester.pumpAndSettle();
}

Future<void> _login(WidgetTester tester, {String email = 'brian@kemmy.test'}) async {
  await tester.enterText(find.byKey(const Key('login-email')), email);
  await tester.enterText(find.byKey(const Key('login-password')), FakeBackend.password);
  await tester.ensureVisible(find.byKey(const Key('login-submit')));
  await tester.tap(find.byKey(const Key('login-submit')));
  await tester.pumpAndSettle();
}

void _go(WidgetTester tester, String location) => GoRouter.of(tester.element(find.byType(Scaffold).first)).push(location);

ProviderContainer _container(WidgetTester tester) => ProviderScope.containerOf(tester.element(find.byType(StoreSystemApp)));

Future<void> _toggle(WidgetTester tester) async {
  await tester.scrollUntilVisible(find.byKey(const Key('biometric-toggle')), 200, scrollable: find.byType(Scrollable).first);
  await tester.tap(find.byKey(const Key('biometric-toggle')));
  await tester.pumpAndSettle();
}

Future<void> _tapBiometricSignIn(WidgetTester tester) async {
  await tester.ensureVisible(find.byKey(const Key('login-biometric')));
  await tester.tap(find.byKey(const Key('login-biometric')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('fingerprint sign-in is turned on from Edit profile and signs in after signing out', (tester) async {
    final backend = FakeBackend();
    final bio = _FakeBiometrics();
    final store = MemoryBiometricStore();
    await _start(tester, backend: backend, bio: bio, store: store);
    expect(find.byKey(const Key('login-biometric')), findsNothing);
    await _login(tester);
    _go(tester, '/profile/edit');
    await tester.pumpAndSettle();

    await _toggle(tester);
    expect(bio.prompts, 1);
    expect(store.value, isNotNull);
    expect(backend.biometricKeyCount(store.value!.userId), 1);
    expect(find.text('Fingerprint sign-in is on'), findsOneWidget);

    // Signing out keeps it; the sign-in screen offers the fingerprint.
    // Not awaited directly: the fake API's timers only run while the test pumps.
    final signOut = _container(tester).read(authControllerProvider.notifier).logout();
    await tester.pumpAndSettle();
    await signOut;
    expect(find.byKey(const Key('login-biometric')), findsOneWidget);

    // A failed fingerprint does not sign in.
    bio.succeed = false;
    await _tapBiometricSignIn(tester);
    expect(find.textContaining('Not confirmed'), findsOneWidget);
    expect(_container(tester).read(currentUserProvider), isNull);

    bio.succeed = true;
    await _tapBiometricSignIn(tester);
    expect(_container(tester).read(currentUserProvider), isNotNull);
    expect(find.text('App locked'), findsNothing);
  });

  testWidgets('turning it off revokes the key; a phone without biometrics cannot turn it on', (tester) async {
    final backend = FakeBackend();
    final bio = _FakeBiometrics();
    final store = MemoryBiometricStore();
    await _start(tester, backend: backend, bio: bio, store: store);
    await _login(tester);
    _go(tester, '/profile/edit');
    await tester.pumpAndSettle();

    await _toggle(tester);
    final userId = store.value!.userId;
    await _toggle(tester);
    expect(store.value, isNull);
    expect(backend.biometricKeyCount(userId), 0);

    bio.supported = false;
    await _toggle(tester);
    expect(store.value, isNull);
    expect(find.textContaining('no fingerprint'), findsOneWidget);
  });

  testWidgets('a revoked key falls back to the password and is forgotten', (tester) async {
    final store = MemoryBiometricStore(const BiometricCredential(key: '1|gone', userId: 3, name: 'Brian', email: 'brian@kemmy.test'));
    await _start(tester, backend: FakeBackend(), bio: _FakeBiometrics(), store: store);

    await _tapBiometricSignIn(tester);
    expect(find.textContaining('no longer set up'), findsOneWidget);
    expect(store.value, isNull);
    expect(find.byKey(const Key('login-biometric')), findsNothing);
  });

  testWidgets('another person signing in with a password removes the previous fingerprint sign-in', (tester) async {
    final store = MemoryBiometricStore(const BiometricCredential(key: '1|someone', userId: 99, name: 'Someone', email: 'someone@kemmy.test'));
    await _start(tester, backend: FakeBackend(), bio: _FakeBiometrics(), store: store);
    await _login(tester);
    expect(store.value, isNull);
    expect(find.text('App locked'), findsNothing);
  });

  testWidgets('a restored session is locked until the fingerprint matches', (tester) async {
    final backend = FakeBackend();
    final token = fakeLogin(backend);
    final bio = _FakeBiometrics(succeed: false);
    final store = MemoryBiometricStore(const BiometricCredential(key: 'k', userId: 3, name: 'Brian', email: 'brian@kemmy.test'));
    await _start(tester, backend: backend, bio: bio, store: store, tokens: MemoryTokenStorage(token));

    expect(find.text('App locked'), findsOneWidget);
    expect(bio.prompts, greaterThanOrEqualTo(1));
    bio.succeed = true;
    await tester.tap(find.byKey(const Key('biometric-unlock')));
    await tester.pumpAndSettle();
    expect(find.text('App locked'), findsNothing);
  });

  test('fake backend: changing the password keeps only this phone\'s fingerprint key', () {
    final b = FakeBackend();
    final auth = 'Bearer ${fakeLogin(b)}';
    String newKey() =>
        (b.handle(method: 'POST', path: 'me/biometric-key', body: {'device_name': 'Pixel'}, authorization: auth).body as Map)['key'] as String;
    final mine = newKey();
    newKey();
    expect(b.biometricKeyCount(3), 2);
    final res = b.handle(method: 'POST', path: 'auth/change-password', authorization: auth, body: {
      'current_password': FakeBackend.password,
      'password': 'newpass123',
      'password_confirmation': 'newpass123',
      'keep_biometric_key': mine,
    });
    expect(res.status, 200);
    expect(b.biometricKeyCount(3), 1);
    expect(b.handle(method: 'POST', path: 'auth/biometric-login', body: {'key': mine, 'device_name': 'Pixel'}).status, 200);
  });

  testWidgets('home shows issue statistics, More has no About entry', (tester) async {
    await _start(tester, backend: FakeBackend(), bio: _FakeBiometrics());
    await _login(tester);
    expect(find.text('Issue vouchers this month'), findsOneWidget);
    expect(find.text('Items issued this month'), findsOneWidget);

    _go(tester, '/more');
    await tester.pumpAndSettle();
    expect(find.text('About'), findsNothing);
  });

  test('fake backend: requester sees restock progress on the request; avatar can be set and removed', () {
    final b = FakeBackend();
    final auth = 'Bearer ${fakeLogin(b)}';
    Map<String, dynamic> call(String method, String path, {Map<String, dynamic>? body, Map<String, FakeUpload> files = const {}}) {
      final res = b.handle(method: method, path: path, body: body, files: files, authorization: auth);
      expect(res.status, lessThan(300), reason: '$method $path ${res.body}');
      return (res.body as Map).cast<String, dynamic>();
    }

    final reqId = (((call('GET', 'issues/queue')['data'] as List).first) as Map)['id'] as int;
    final line = (((call('GET', 'requests/$reqId/issue')['data'] as Map)['lines'] as List).first) as Map;
    final onHand = line['on_hand'] as String;
    if (double.parse(onHand) > 0) {
      call('POST', 'adjustments', body: {'store_id': 1, 'item_id': (line['item'] as Map)['id'], 'qty_delta': '-$onHand', 'reason': 'Test: empty the shelf'});
    }
    call('POST', 'requests/$reqId/restock', body: {'store_id': 1});
    final detail = call('GET', 'requests/$reqId')['data'] as Map;
    expect((detail['restock'] as List).single['stage'], startsWith('Waiting for the Overall Manager'));

    final up = call('POST', 'me/avatar', files: {'avatar': const FakeUpload(filename: 'me.jpg', length: 1000)});
    expect((up['user'] as Map)['avatar_url'], isNotNull);
    final down = call('DELETE', 'me/avatar');
    expect((down['user'] as Map)['avatar_url'], isNull);
  });
}
