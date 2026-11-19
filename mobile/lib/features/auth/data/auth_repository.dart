import 'package:dio/dio.dart';
import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/providers.dart';
import '../../../core/api/token_storage.dart';
import '../../../core/config/app_config.dart';
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

  /// Fingerprint / face sign-in: trades the device key for a new token (`POST /auth/biometric-login`).
  Future<User> biometricLogin({required String key, required String deviceName}) async {
    final json = await _api.post(
      '/auth/biometric-login',
      body: {'key': key, 'device_name': deviceName},
      skipSession: true,
    );
    await _tokens.write(asString(json['token']));
    return User.fromJson(asMap(json['user']));
  }

  /// Turns on fingerprint sign-in for this phone; returns the device key (`POST /me/biometric-key`).
  Future<String> createBiometricKey({required String deviceName, String? replaceKey}) async {
    final json = await _api.post('/me/biometric-key', body: {
      'device_name': deviceName,
      'replace_key': ?replaceKey,
    });
    return asString(json['key']);
  }

  /// Turns fingerprint sign-in off on the server (best effort; works signed out too).
  Future<void> revokeBiometricKey(String key) async {
    try {
      await _api.post('/auth/biometric-key/revoke', body: {'key': key}, skipSession: true);
    } catch (_) {
      // Offline: the key stays listed on the web Sign-in & security page, where it can be removed.
    }
  }

  /// Restores the session; 401 is handled by the caller (no session event).
  Future<User> me() async {
    final json = await _api.get('/me', skipSession: true);
    return User.fromJson(asMap(json['user']));
  }

  /// `PUT /me`. [currentPassword] is only needed when the e-mail changes.
  Future<User> updateProfile({
    required String name,
    required String email,
    String? phone,
    String? currentPassword,
  }) async {
    final json = await _api.put('/me', body: {
      'name': name,
      'email': email,
      'phone': phone,
      if ((currentPassword ?? '').isNotEmpty) 'current_password': currentPassword,
    });
    return User.fromJson(asMap(json['user']));
  }

  /// `POST /me/avatar` (multipart `avatar`).
  Future<User> uploadAvatar(String path, String fileName) async {
    final form = FormData.fromMap({'avatar': await MultipartFile.fromFile(path, filename: fileName)});
    final json = await _api.postMultipart('/me/avatar', form);
    return User.fromJson(asMap(json['user']));
  }

  Future<User> deleteAvatar() async {
    final json = await _api.delete('/me/avatar');
    return User.fromJson(asMap(json['user']));
  }

  Future<User> changePassword({
    required String current,
    required String password,
    required String confirmation,
    String? keepBiometricKey,
  }) async {
    final json = await _api.post('/auth/change-password', body: {
      'current_password': current,
      'password': password,
      'password_confirmation': confirmation,
      'keep_biometric_key': ?keepBiometricKey,
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
  const fallback = '${AppConfig.fallbackAppName} app';
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
