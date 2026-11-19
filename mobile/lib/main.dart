import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/api/providers.dart';
import 'core/api/token_storage.dart';
import 'core/config/app_config.dart';
import 'core/config/branding.dart';
import 'core/security/biometric_lock.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  const config = AppConfig.fromEnvironment;

  // The system name saved last time, so the title is right from the first frame.
  final BrandingCache brandingCache = config.useFakeApi ? MemoryBrandingCache() : SecureBrandingCache();
  AppBranding? cachedBranding;
  try {
    cachedBranding = await brandingCache.load().timeout(const Duration(seconds: 2));
  } catch (_) {}

  // Fingerprint sign-in, read before the first frame so a locked app never shows its content.
  final BiometricStore biometricStore = config.useFakeApi ? MemoryBiometricStore() : SecureBiometricStore();
  BiometricCredential? biometric;
  try {
    biometric = await biometricStore.load().timeout(const Duration(seconds: 2));
  } catch (_) {}

  runApp(
    ProviderScope(
      // Failed API calls are surfaced with a "Try again" button; automatic
      // retries would hammer the server on 401/403/422.
      retry: (retryCount, error) => null,
      overrides: [
        // The fake backend keeps state in memory only, so the token can too.
        if (config.useFakeApi) tokenStorageProvider.overrideWithValue(MemoryTokenStorage()),
        brandingCacheProvider.overrideWithValue(brandingCache),
        cachedBrandingProvider.overrideWithValue(cachedBranding),
        biometricStoreProvider.overrideWithValue(biometricStore),
        initialBiometricCredentialProvider.overrideWithValue(biometric),
      ],
      child: const StoreSystemApp(),
    ),
  );
}
