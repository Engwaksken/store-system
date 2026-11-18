import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:store_system/app.dart';
import 'package:store_system/core/fake/fake_backend.dart';
import 'package:store_system/core/security/biometric_lock.dart';

import '../helpers.dart';

class _FakeBiometrics implements BiometricAuth {
  _FakeBiometrics({this.supported = true, this.succeed = true});
  bool supported;
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

Future<void> _start(WidgetTester tester, {required FakeBackend backend, required _FakeBiometrics bio, MemoryBiometricPrefs? prefs, bool locked = false}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  final p = prefs ?? MemoryBiometricPrefs(locked);
  await tester.pumpWidget(ProviderScope(
    retry: (_, _) => null,
    overrides: [
      ...fakeOverrides(backend: backend),
      biometricAuthProvider.overrideWithValue(bio),
      biometricPrefsProvider.overrideWithValue(p),
      initialBiometricEnabledProvider.overrideWithValue(locked),
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

void main() {
  testWidgets('biometric unlock is turned on from Edit profile after a successful check', (tester) async {
    final bio = _FakeBiometrics();
    final prefs = MemoryBiometricPrefs();
    await _start(tester, backend: FakeBackend(), bio: bio, prefs: prefs);
    await _login(tester);
    _go(tester, '/profile/edit');
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.byKey(const Key('biometric-toggle')), 200, scrollable: find.byType(Scrollable).first);
    await tester.tap(find.byKey(const Key('biometric-toggle')));
    await tester.pumpAndSettle();
    expect(bio.prompts, 1);
    expect(prefs.value, isTrue);
    expect(find.text('Biometric unlock is on'), findsOneWidget);

    // A phone without biometrics or screen lock cannot turn it on.
    await tester.tap(find.byKey(const Key('biometric-toggle')));
    await tester.pumpAndSettle();
    expect(prefs.value, isFalse);
    bio.supported = false;
    await tester.tap(find.byKey(const Key('biometric-toggle')));
    await tester.pumpAndSettle();
    expect(prefs.value, isFalse);
    expect(find.textContaining('no fingerprint'), findsOneWidget);
  });

  testWidgets('a locked app shows the unlock screen until the fingerprint matches', (tester) async {
    final backend = FakeBackend();
    final bio = _FakeBiometrics(succeed: false);
    await _start(tester, backend: backend, bio: bio, locked: true);
    await _login(tester);

    expect(find.text('App locked'), findsOneWidget);
    expect(bio.prompts, greaterThanOrEqualTo(1));
    bio.succeed = true;
    await tester.tap(find.byKey(const Key('biometric-unlock')));
    await tester.pumpAndSettle();
    expect(find.text('App locked'), findsNothing);
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
