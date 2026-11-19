import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// What this phone keeps when fingerprint / face sign-in is on: the device key issued by
/// `POST /me/biometric-key` (released only after the phone's biometrics or PIN succeed) and
/// who it belongs to, so the sign-in screen can say whose account it opens.
class BiometricCredential {
  const BiometricCredential({required this.key, required this.userId, required this.name, required this.email});

  final String key;
  final int userId;
  final String name;
  final String email;

  Map<String, dynamic> toJson() => {'key': key, 'user_id': userId, 'name': name, 'email': email};

  static BiometricCredential? fromJson(Object? json) {
    if (json is! Map) return null;
    final key = json['key'];
    final userId = json['user_id'];
    if (key is! String || key.isEmpty || userId is! int) return null;
    return BiometricCredential(key: key, userId: userId, name: '${json['name'] ?? ''}', email: '${json['email'] ?? ''}');
  }
}

/// Where the credential is kept (the keystore on a real phone).
abstract class BiometricStore {
  Future<BiometricCredential?> load();
  Future<void> save(BiometricCredential? credential);
}

class SecureBiometricStore implements BiometricStore {
  static const _key = 'biometric_sign_in';

  /// Setting from the earlier unlock-only version; removed on first use.
  static const _legacyKey = 'biometric_lock';
  final _storage = const FlutterSecureStorage();

  @override
  Future<BiometricCredential?> load() async {
    try {
      await _storage.delete(key: _legacyKey);
      final raw = await _storage.read(key: _key);
      return raw == null ? null : BiometricCredential.fromJson(jsonDecode(raw));
    } catch (_) {
      // Keystore corruption (e.g. after a backup restore): fall back to the password.
      return null;
    }
  }

  @override
  Future<void> save(BiometricCredential? credential) async {
    try {
      if (credential == null) {
        await _storage.delete(key: _key);
      } else {
        await _storage.write(key: _key, value: jsonEncode(credential.toJson()));
      }
    } catch (_) {}
  }
}

class MemoryBiometricStore implements BiometricStore {
  MemoryBiometricStore([this.value]);
  BiometricCredential? value;

  @override
  Future<BiometricCredential?> load() async => value;

  @override
  Future<void> save(BiometricCredential? credential) async => value = credential;
}

final biometricStoreProvider = Provider<BiometricStore>((ref) => MemoryBiometricStore());

/// Read before the first frame (main.dart), so a locked app never flashes its content.
final initialBiometricCredentialProvider = Provider<BiometricCredential?>((ref) => null);
