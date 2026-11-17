import 'dart:async';

import 'package:flutter/painting.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../features/auth/data/auth_controller.dart';
import '../api/json.dart';
import '../api/providers.dart';
import '../theme/app_theme.dart';
import 'app_config.dart';

/// Name, tagline and images set by the admin in the web app (Settings > Company).
class AppBranding {
  const AppBranding({required this.name, this.subtitle, this.logoUrl, this.faviconUrl, this.primary, this.accent});

  /// The system name (`company_name`), shown as the app title everywhere.
  final String name;

  /// The tagline under the name (`app_subtitle`, e.g. "Projects Management").
  final String? subtitle;
  final String? logoUrl;
  final String? faviconUrl;

  /// Settings > Appearance colours; null when the server sends none.
  final Color? primary;
  final Color? accent;

  BrandColors get colors => BrandColors(primary: primary ?? Brand.navy, accent: accent ?? Brand.gold);

  factory AppBranding.fromJson(Json json) {
    final name = asString(json['company_name']).trim();
    final subtitle = asString(json['app_subtitle']).trim();
    final theme = asMap(json['theme']);
    return AppBranding(
      name: name.isEmpty ? AppConfig.fallbackAppName : name,
      subtitle: subtitle.isEmpty ? null : subtitle,
      logoUrl: asStringOrNull(json['logo_url']),
      faviconUrl: asStringOrNull(json['favicon_url']),
      primary: BrandColors.parse(theme['primary']),
      accent: BrandColors.parse(theme['accent']),
    );
  }
}

/// Remembers the last system name received, so the right title shows from the
/// first frame (before `/branding` answers, or offline) and follows a rename in Settings.
abstract class BrandingCache {
  Future<AppBranding?> load();
  Future<void> save(AppBranding branding);
}

class SecureBrandingCache implements BrandingCache {
  SecureBrandingCache([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const _nameKey = 'branding_name';
  static const _subtitleKey = 'branding_subtitle';
  static const _primaryKey = 'branding_primary';
  static const _accentKey = 'branding_accent';
  final FlutterSecureStorage _storage;

  @override
  Future<AppBranding?> load() async {
    try {
      final name = await _storage.read(key: _nameKey);
      if (name == null || name.trim().isEmpty) return null;
      final subtitle = await _storage.read(key: _subtitleKey);
      return AppBranding(
        name: name,
        subtitle: (subtitle ?? '').isEmpty ? null : subtitle,
        primary: BrandColors.parse(await _storage.read(key: _primaryKey)),
        accent: BrandColors.parse(await _storage.read(key: _accentKey)),
      );
    } catch (_) {
      return null; // a broken keystore must never block start-up
    }
  }

  @override
  Future<void> save(AppBranding branding) async {
    try {
      await _storage.write(key: _nameKey, value: branding.name);
      await _storage.write(key: _subtitleKey, value: branding.subtitle ?? '');
      await _storage.write(key: _primaryKey, value: branding.primary == null ? '' : BrandColors.toHex(branding.primary!));
      await _storage.write(key: _accentKey, value: branding.accent == null ? '' : BrandColors.toHex(branding.accent!));
    } catch (_) {}
  }
}

class MemoryBrandingCache implements BrandingCache {
  MemoryBrandingCache([this._value]);
  AppBranding? _value;

  @override
  Future<AppBranding?> load() async => _value;

  @override
  Future<void> save(AppBranding branding) async => _value = branding;
}

/// Overridden in `main()` with the persistent cache.
final brandingCacheProvider = Provider<BrandingCache>((ref) => MemoryBrandingCache());

/// The branding saved on the previous run, read before the first frame (overridden in `main()`).
final cachedBrandingProvider = Provider<AppBranding?>((ref) => null);

/// Public `/branding` endpoint: available before sign-in. Each answer is saved for the next start.
final appBrandingProvider = FutureProvider<AppBranding>((ref) async {
  final json = await ref.watch(apiClientProvider).get('/branding', skipSession: true);
  final branding = AppBranding.fromJson(json);
  unawaited(ref.read(brandingCacheProvider).save(branding));
  return branding;
});

/// The system name used as the app title: from `/branding`, else the signed-in user's
/// profile (same setting), else the name saved last time, else the built-in fallback.
final appNameProvider = Provider<String>((ref) {
  final branded = ref.watch(appBrandingProvider).asData?.value.name;
  if (branded != null && branded.isNotEmpty) return branded;
  final fromUser = ref.watch(currentUserProvider)?.companyName ?? '';
  if (fromUser.isNotEmpty) return fromUser;
  return ref.watch(cachedBrandingProvider)?.name ?? AppConfig.fallbackAppName;
});

/// The tagline under the system name, or null when none is known yet.
final appSubtitleProvider = Provider<String?>((ref) {
  final branded = ref.watch(appBrandingProvider).asData;
  if (branded != null) return branded.value.subtitle;
  final fromUser = ref.watch(currentUserProvider)?.appSubtitle ?? '';
  if (fromUser.isNotEmpty) return fromUser;
  return ref.watch(cachedBrandingProvider)?.subtitle;
});

/// System colours for the app theme: from `/branding`, else saved last time, else the defaults.
final brandColorsProvider = Provider<BrandColors>((ref) {
  final live = ref.watch(appBrandingProvider).asData?.value;
  if (live?.primary != null) return live!.colors;
  final saved = ref.watch(cachedBrandingProvider);
  if (saved?.primary != null) return saved!.colors;
  return BrandColors.defaults;
});
