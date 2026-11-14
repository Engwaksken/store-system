import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/api/providers.dart';
import '../../../core/format/formatters.dart';
import '../domain/user.dart';
import 'auth_repository.dart';

sealed class AuthState {
  const AuthState();
}

/// App just started; the splash screen is restoring the session.
class AuthUnknown extends AuthState {
  const AuthUnknown();
}

class AuthSignedOut extends AuthState {
  const AuthSignedOut({this.message});

  /// Why the user was signed out (shown on the login screen), if not voluntary.
  final String? message;
}

class AuthSignedIn extends AuthState {
  const AuthSignedIn(this.user);
  final User user;
}

/// A token exists but `/me` could not be reached (offline / server down).
class AuthRestoreFailed extends AuthState {
  const AuthRestoreFailed(this.message);
  final String message;
}

class AuthController extends Notifier<AuthState> {
  StreamSubscription<SessionEvent>? _sub;

  AuthRepository get _repo => ref.read(authRepositoryProvider);

  @override
  AuthState build() {
    _sub = ref.read(sessionEventsProvider).stream.listen(_onSessionEvent);
    ref.onDispose(() => _sub?.cancel());
    return const AuthUnknown();
  }

  /// Called by the splash screen.
  Future<void> restore() async {
    state = const AuthUnknown();
    final token = await _repo.storedToken();
    if (token == null || token.isEmpty) {
      state = const AuthSignedOut();
      return;
    }
    try {
      state = AuthSignedIn(await _repo.me());
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized) {
        await _repo.forgetToken();
        state = const AuthSignedOut();
      } else if (e.kind == ApiErrorKind.deactivated) {
        await _repo.forgetToken();
        state = AuthSignedOut(message: e.message);
      } else {
        state = AuthRestoreFailed(e.message);
      }
    }
  }

  /// Throws [ApiException] on failure (the login screen shows it).
  Future<void> login(String email, String password, String deviceName) async {
    final user = await _repo.login(email: email, password: password, deviceName: deviceName);
    state = AuthSignedIn(user);
  }

  Future<void> changePassword(String current, String password, String confirmation) async {
    final user = await _repo.changePassword(current: current, password: password, confirmation: confirmation);
    state = AuthSignedIn(user);
  }

  /// Re-reads `/me` (e.g. after the app resumes) without disturbing the UI on failure.
  Future<void> refreshUser() async {
    if (state is! AuthSignedIn) return;
    try {
      final user = await _repo.me();
      if (ref.mounted) state = AuthSignedIn(user);
    } on ApiException catch (e) {
      if (e.kind == ApiErrorKind.unauthorized) await _signOutLocally();
    }
  }

  Future<void> logout() async {
    await _repo.logout();
    state = const AuthSignedOut();
  }

  Future<void> _signOutLocally({String? message}) async {
    await _repo.forgetToken();
    state = AuthSignedOut(message: message);
  }

  void _onSessionEvent(SessionEvent e) {
    switch (e) {
      case SessionEvent.unauthorized:
        if (state is AuthSignedIn) {
          _signOutLocally(message: 'Your session has ended. Please sign in again.');
        }
      case SessionEvent.passwordChangeRequired:
        final s = state;
        if (s is AuthSignedIn && !s.user.mustChangePassword) {
          state = AuthSignedIn(s.user.copyWith(mustChangePassword: true));
        }
      case SessionEvent.deactivated:
        _signOutLocally(message: 'Your account has been deactivated. Contact your administrator.');
    }
  }
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);

/// The signed-in user, or null.
final currentUserProvider = Provider<User?>((ref) {
  final s = ref.watch(authControllerProvider);
  return s is AuthSignedIn ? s.user : null;
});

/// Formatters configured with the user's currency.
final formattersProvider = Provider<Formatters>((ref) {
  final user = ref.watch(currentUserProvider);
  return Formatters(currency: user?.currency ?? '', currencyDecimals: user?.currencyDecimals ?? 0);
});
