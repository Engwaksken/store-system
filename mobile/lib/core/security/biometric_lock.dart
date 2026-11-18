import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

import '../../features/auth/data/auth_controller.dart';

/// Fingerprint / face unlock for the app (device biometrics, with the phone PIN as fallback).
/// The setting lives on this device only and is cleared when the user signs out.
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

/// Where the on/off choice is kept.
abstract class BiometricPrefs {
  Future<bool> load();
  Future<void> save(bool enabled);
}

class SecureBiometricPrefs implements BiometricPrefs {
  static const _key = 'biometric_lock';
  final _storage = const FlutterSecureStorage();

  @override
  Future<bool> load() async => (await _storage.read(key: _key)) == '1';

  @override
  Future<void> save(bool enabled) => enabled ? _storage.write(key: _key, value: '1') : _storage.delete(key: _key);
}

class MemoryBiometricPrefs implements BiometricPrefs {
  MemoryBiometricPrefs([this.value = false]);
  bool value;

  @override
  Future<bool> load() async => value;

  @override
  Future<void> save(bool enabled) async => value = enabled;
}

final biometricAuthProvider = Provider<BiometricAuth>((ref) => LocalBiometricAuth());
final biometricPrefsProvider = Provider<BiometricPrefs>((ref) => MemoryBiometricPrefs());

/// Value read before the first frame (main.dart), so a locked app never flashes its content.
final initialBiometricEnabledProvider = Provider<bool>((ref) => false);

class BiometricLockState {
  const BiometricLockState({required this.enabled, required this.locked});
  final bool enabled;
  final bool locked;
}

class BiometricLockController extends Notifier<BiometricLockState> {
  /// Locks again after the app has been in the background this long.
  static const relockAfter = Duration(seconds: 30);
  DateTime? _backgroundSince;

  @override
  BiometricLockState build() {
    ref.listen(authControllerProvider, (prev, next) {
      // Signing out turns the lock off: the next person signs in with their password.
      if (prev is AuthSignedIn && next is AuthSignedOut && state.enabled) _set(false);
    });
    final enabled = ref.read(initialBiometricEnabledProvider);
    return BiometricLockState(enabled: enabled, locked: enabled);
  }

  Future<void> _set(bool enabled) async {
    await ref.read(biometricPrefsProvider).save(enabled);
    state = BiometricLockState(enabled: enabled, locked: false);
  }

  /// Turning it on asks for the fingerprint first, so it is known to work. Returns an error message, or null.
  Future<String?> setEnabled(bool enabled) async {
    if (!enabled) {
      await _set(false);
      return null;
    }
    final auth = ref.read(biometricAuthProvider);
    if (!await auth.available()) return 'This phone has no fingerprint, face unlock or screen lock set up.';
    if (!await auth.authenticate('Confirm it is you to turn on biometric unlock')) return 'Not confirmed; biometric unlock stays off.';
    await _set(true);
    return null;
  }

  Future<bool> unlock() async {
    final ok = await ref.read(biometricAuthProvider).authenticate('Unlock the app');
    if (ok) state = BiometricLockState(enabled: state.enabled, locked: false);
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
        state = BiometricLockState(enabled: true, locked: true);
      }
    }
  }
}

final biometricLockProvider = NotifierProvider<BiometricLockController, BiometricLockState>(BiometricLockController.new);

/// Covers the app with an unlock screen while the biometric lock is on and locked (signed-in users only).
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
    ref.read(biometricLockProvider.notifier).onLifecycle(state);
    if (state == AppLifecycleState.resumed) _maybePrompt();
  }

  Future<void> _maybePrompt() async {
    final lock = ref.read(biometricLockProvider);
    if (!lock.locked || _prompting || ref.read(currentUserProvider) == null) return;
    _prompting = true;
    try {
      await ref.read(biometricLockProvider.notifier).unlock();
    } finally {
      _prompting = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final lock = ref.watch(biometricLockProvider);
    final signedIn = ref.watch(currentUserProvider) != null;
    ref.listen(currentUserProvider, (prev, next) {
      if (prev == null && next != null) _maybePrompt();
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
