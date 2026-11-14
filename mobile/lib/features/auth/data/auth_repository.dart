import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/providers.dart';
import '../../../core/api/token_storage.dart';
import '../domain/user.dart';

class AuthRepository {
  AuthRepository(this._api, this._tokens);
  final ApiClient _api;
  final TokenStorage _tokens;

  Future<String?> storedToken() => _tokens.read();

  Future<User> login({required String email, required String password, required String deviceName}) async {
    final json = await _api.post(
      '/auth/login',
      body: {'email': email.trim(), 'password': password, 'device_name': deviceName},
      skipSession: true,
    );
    final token = asString(json['token']);
    await _tokens.write(token);
    return User.fromJson(asMap(json['user']));
  }

  /// Restores the session; 401 is handled by the caller (no session event).
  Future<User> me() async {
    final json = await _api.get('/me', skipSession: true);
    return User.fromJson(asMap(json['user']));
  }

  Future<User> changePassword({
    required String current,
    required String password,
    required String confirmation,
  }) async {
    final json = await _api.post('/auth/change-password', body: {
      'current_password': current,
      'password': password,
      'password_confirmation': confirmation,
    });
    return User.fromJson(asMap(json['user']));
  }

  /// Revokes the token server-side (best effort) and always forgets it locally.
  Future<void> logout() async {
    try {
      if (_tokens.cachedToken != null) await _api.post('/auth/logout', skipSession: true);
    } catch (_) {
      // Offline or already revoked: still sign out locally.
    } finally {
      await _tokens.clear();
    }
  }

  Future<void> forgetToken() => _tokens.clear();
}

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(apiClientProvider), ref.watch(tokenStorageProvider)),
);

/// Device label sent as `device_name` so users can recognise their sessions.
final deviceNameProvider = FutureProvider<String>((ref) async {
  const fallback = 'Kemmy Store app';
  if (kIsWeb) return fallback;
  try {
    final info = DeviceInfoPlugin();
    if (Platform.isAndroid) {
      final a = await info.androidInfo;
      final name = '${_cap(a.manufacturer)} ${a.model}'.trim();
      return name.isEmpty ? 'Android' : name;
    }
    if (Platform.isIOS) {
      final i = await info.iosInfo;
      return i.utsname.machine.isNotEmpty ? i.utsname.machine : i.model;
    }
  } catch (_) {}
  return fallback;
});

String _cap(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
