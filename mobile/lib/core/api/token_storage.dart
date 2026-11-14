import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists the Sanctum token. Keeps an in-memory copy so the interceptor does
/// not hit the keystore on every request.
abstract class TokenStorage {
  String? get cachedToken;
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStorage implements TokenStorage {
  SecureTokenStorage([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'api_token';
  final FlutterSecureStorage _storage;
  String? _token;

  @override
  String? get cachedToken => _token;

  @override
  Future<String?> read() async {
    try {
      _token = await _storage.read(key: _key);
    } catch (_) {
      // Keystore corruption (e.g. after a backup restore) must not brick the app.
      _token = null;
    }
    return _token;
  }

  @override
  Future<void> write(String token) async {
    _token = token;
    await _storage.write(key: _key, value: token);
  }

  @override
  Future<void> clear() async {
    _token = null;
    try {
      await _storage.delete(key: _key);
    } catch (_) {}
  }
}

/// Used by tests and by fake mode on platforms without a keystore.
class MemoryTokenStorage implements TokenStorage {
  MemoryTokenStorage([this._token]);
  String? _token;

  @override
  String? get cachedToken => _token;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async => _token = token;

  @override
  Future<void> clear() async => _token = null;
}
