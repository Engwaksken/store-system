import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import '../fake/fake_api_adapter.dart';
import '../fake/fake_backend.dart';
import 'api_client.dart';
import 'token_storage.dart';

final appConfigProvider = Provider<AppConfig>((ref) => AppConfig.fromEnvironment);

final tokenStorageProvider = Provider<TokenStorage>((ref) => SecureTokenStorage());

final sessionEventsProvider = Provider<SessionEvents>((ref) {
  final events = SessionEvents();
  ref.onDispose(events.dispose);
  return events;
});

/// Only used when [AppConfig.useFakeApi] is set.
final fakeBackendProvider = Provider<FakeBackend>(
  (ref) => FakeBackend(baseUrl: ref.watch(appConfigProvider).normalizedBaseUrl),
);

/// Simulated network latency in fake mode (overridden to zero in tests).
final fakeLatencyProvider = Provider<Duration>((ref) => const Duration(milliseconds: 350));

final dioProvider = Provider<Dio>((ref) {
  final config = ref.watch(appConfigProvider);
  final dio = buildDio(
    config: config,
    tokenStorage: ref.watch(tokenStorageProvider),
    events: ref.watch(sessionEventsProvider),
    adapter: config.useFakeApi
        ? FakeApiAdapter(
            ref.watch(fakeBackendProvider),
            baseUrl: config.normalizedBaseUrl,
            latency: ref.watch(fakeLatencyProvider),
          )
        : null,
  );
  ref.onDispose(dio.close);
  return dio;
});

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(ref.watch(dioProvider)));
