import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

import '../../features/auth/data/auth_controller.dart';
import '../../features/auth/data/auth_repository.dart';
import '../api/api_exception.dart';
import 'biometric_store.dart';

export 'biometric_store.dart';

/// Fingerprint / face sign-in for the app (device biometrics, with the phone PIN as fallback).
///
/// Turning it on asks the server for a device key (`POST /me/biometric-key`) and keeps it in the
/// keystore. While it is on:
/// * the sign-in screen offers "Sign in with fingerprint or face", even after signing out;
/// * the app locks when it opens and after [BiometricController.relockAfter] in the background.
///
/// It is turned off (and the key revoked) when someone else signs in on this phone, when the
/// server no longer accepts the key (password changed elsewhere, removed on the web), or from the
/// switch in Edit profile.
abstract class BiometricAuth {
  /// The device can verify the owner (biometrics or screen lock).
  Future<bool> available();

  /// Shows the system prompt; true when the owner was verified.
  Future<bool> authenticate(String reason);
}

class LocalBiometricAuth implements BiometricAuth {
  final _auth = LocalAuthentication();

  @override
  Future<bool> available() async {
    try {
      return await _auth.isDeviceSupported();
    } on PlatformException {
      return false;
    } on LocalAuthException {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) async {
    try {
      return await _auth.authenticate(localizedReason: reason, persistAcrossBackgrounding: true);
    } on PlatformException {
      return false;
    } on LocalAuthException {
      return false;
    }
  }
}

final biometricAuthProvider = Provider<BiometricAuth>((ref) => LocalBiometricAuth());

class BiometricState {
  const BiometricState({this.credential, this.locked = false});

  /// Present while fingerprint sign-in is on for this phone.
  final BiometricCredential? credential;

  /// The signed-in app is covered by the unlock screen.
  final bool locked;

  bool get enabled => credential != null;
}

class BiometricController extends Notifier<BiometricState> {
  /// Locks again after the app has been in the background this long.
  static const relockAfter = Duration(seconds: 30);
  DateTime? _backgroundSince;

  @override
  BiometricState build() {
    ref.listen(authControllerProvider, (prev, next) {
      if (next is! AuthSignedIn) return;
      final cred = state.credential;
      if (cred != null && cred.userId != next.user.id) {
        // Someone else signed in on this phone: the previous person's fingerprint sign-in goes.
        _forget(revoke: true);
      } else if (prev is AuthSignedOut && state.locked) {
        // Just signed in (password or fingerprint): nothing to unlock.
        state = BiometricState(credential: cred);
      }
    });
    final cred = ref.read(initialBiometricCredentialProvider);
    return BiometricState(credential: cred, locked: cred != null);
  }

  BiometricAuth get _auth => ref.read(biometricAuthProvider);
  AuthRepository get _repo => ref.read(authRepositoryProvider);

  Future<void> _forget({bool revoke = false}) async {
    final cred = state.credential;
    state = const BiometricState();
    await ref.read(biometricStoreProvider).save(null);
    if (revoke && cred != null) await _repo.revokeBiometricKey(cred.key);
  }

  /// Turning it on asks for the fingerprint first, so it is known to work. Returns an error message, or null.
  Future<String?> setEnabled(bool enabled) async {
    if (!enabled) {
      await _forget(revoke: true);
      return null;
    }
    final user = ref.read(currentUserProvider);
    if (user == null) return 'Sign in first.';
    if (!await _auth.available()) return 'This phone has no fingerprint, face unlock or screen lock set up.';
    if (!await _auth.authenticate('Confirm it is you to turn on fingerprint sign-in')) {
      return 'Not confirmed; fingerprint sign-in stays off.';
    }
    try {
      final deviceName = await ref.read(deviceNameProvider.future);
      final key = await _repo.createBiometricKey(deviceName: deviceName, replaceKey: state.credential?.key);
      final cred = BiometricCredential(key: key, userId: user.id, name: user.name, email: user.email);
      await ref.read(biometricStoreProvider).save(cred);
      state = BiometricState(credential: cred);
      return null;
    } on ApiException catch (e) {
      return e.message;
    }
  }

  /// "Sign in with fingerprint or face" on the sign-in screen. Returns an error message, or null when signed in.
  Future<String?> signIn() async {
    final cred = state.credential;
    if (cred == null) return 'Fingerprint sign-in is not set up on this phone.';
    if (!await _auth.authenticate('Sign in as ${cred.name}')) return 'Not confirmed. Try again or use your password.';
    try {
      final deviceName = await ref.read(deviceNameProvider.future);
      await ref.read(authControllerProvider.notifier).loginWithBiometricKey(cred.key, deviceName);
      state = BiometricState(credential: state.credential);
      return null;
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized || e.kind == ApiErrorKind.deactivated) {
        await _forget();
        return e.kind == ApiErrorKind.deactivated
            ? e.message
            : 'Fingerprint sign-in is no longer set up for this phone (for example, the password was changed). '
                'Sign in with your password, then turn it on again in Edit profile.';
      }
      return e.message;
    }
  }

  Future<bool> unlock() async {
    final ok = await _auth.authenticate('Unlock the app');
    if (ok) state = BiometricState(credential: state.credential);
    return ok;
  }

  void onLifecycle(AppLifecycleState s) {
    if (!state.enabled) return;
    if (s == AppLifecycleState.paused || s == AppLifecycleState.hidden) {
      _backgroundSince ??= DateTime.now();
    } else if (s == AppLifecycleState.resumed) {
      final since = _backgroundSince;
      _backgroundSince = null;
      if (since != null && DateTime.now().difference(since) >= relockAfter && !state.locked) {
        state = BiometricState(credential: state.credential, locked: true);
      }
    }
  }
}

final biometricProvider = NotifierProvider<BiometricController, BiometricState>(BiometricController.new);

/// Covers the app with an unlock screen while fingerprint sign-in is on and the app is locked (signed-in users only).
class BiometricGate extends ConsumerStatefulWidget {
  const BiometricGate({super.key, required this.child});
  final Widget child;

  @override
  ConsumerState<BiometricGate> createState() => _BiometricGateState();
}

class _BiometricGateState extends ConsumerState<BiometricGate> with WidgetsBindingObserver {
  bool _prompting = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _maybePrompt());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    ref.read(biometricProvider.notifier).onLifecycle(state);
    if (state == AppLifecycleState.resumed) _maybePrompt();
  }

  Future<void> _maybePrompt() async {
    final lock = ref.read(biometricProvider);
    if (!lock.locked || _prompting || ref.read(currentUserProvider) == null) return;
    _prompting = true;
    try {
      await ref.read(biometricProvider.notifier).unlock();
    } finally {
      _prompting = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final lock = ref.watch(biometricProvider);
    final signedIn = ref.watch(currentUserProvider) != null;
    // The session restored at start-up (splash) -> ask for the fingerprint straight away.
    ref.listen(authControllerProvider, (prev, next) {
      if (prev is AuthUnknown && next is AuthSignedIn) _maybePrompt();
    });
    if (!lock.locked || !signedIn) return widget.child;
    final theme = Theme.of(context);
    return Stack(children: [
      widget.child,
      Positioned.fill(
        child: Material(
          color: theme.colorScheme.surface,
          child: SafeArea(
            child: Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.fingerprint, size: 72, color: theme.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text('App locked', style: theme.textTheme.titleLarge),
                  const SizedBox(height: 8),
                  const Text('Use your fingerprint, face or phone PIN to continue.', textAlign: TextAlign.center),
                  const SizedBox(height: 24),
                  FilledButton.icon(
                    key: const Key('biometric-unlock'),
                    onPressed: _maybePrompt,
                    icon: const Icon(Icons.lock_open),
                    label: const Text('Unlock'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                    child: const Text('Sign out and use password'),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ),
    ]);
  }
}
