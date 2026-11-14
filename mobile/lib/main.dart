import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/api/providers.dart';
import 'core/api/token_storage.dart';
import 'core/config/app_config.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  const config = AppConfig.fromEnvironment;
  runApp(
    ProviderScope(
      // Failed API calls are surfaced with a "Try again" button; automatic
      // retries would hammer the server on 401/403/422.
      retry: (retryCount, error) => null,
      overrides: [
        // The fake backend keeps state in memory only, so the token can too.
        if (config.useFakeApi) tokenStorageProvider.overrideWithValue(MemoryTokenStorage()),
      ],
      child: const KemmyStoreApp(),
    ),
  );
}
