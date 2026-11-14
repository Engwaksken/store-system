import 'package:flutter_riverpod/misc.dart';
import 'package:store_system/core/api/providers.dart';
import 'package:store_system/core/api/token_storage.dart';
import 'package:store_system/core/config/app_config.dart';
import 'package:store_system/core/fake/fake_backend.dart';

const testBaseUrl = 'https://store.kemmytech.com/api/v1';

/// Provider overrides that run the app against the in-memory fake backend.
List<Override> fakeOverrides({FakeBackend? backend, TokenStorage? tokens}) => [
      appConfigProvider.overrideWithValue(const AppConfig(apiBaseUrl: testBaseUrl, useFakeApi: true)),
      tokenStorageProvider.overrideWithValue(tokens ?? MemoryTokenStorage()),
      fakeLatencyProvider.overrideWithValue(Duration.zero),
      if (backend != null) fakeBackendProvider.overrideWithValue(backend),
    ];

/// Signs in directly against [backend] and returns the token.
String fakeLogin(FakeBackend backend, {String email = 'brian@kemmy.test'}) {
  final res = backend.handle(
    method: 'POST',
    path: 'auth/login',
    body: {'email': email, 'password': FakeBackend.password, 'device_name': 'test'},
  );
  return (res.body as Map)['token'] as String;
}
