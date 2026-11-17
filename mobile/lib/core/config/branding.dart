import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/data/auth_controller.dart';
import '../api/json.dart';
import '../api/providers.dart';
import 'app_config.dart';

/// Name, tagline and images set by the admin in the web app (Settings > Company).
class AppBranding {
  const AppBranding({required this.name, this.subtitle, this.logoUrl, this.faviconUrl});

  /// The system name (`company_name`), shown as the app title everywhere.
  final String name;

  /// The tagline under the name (`app_subtitle`, e.g. "Store & Projects").
  final String? subtitle;
  final String? logoUrl;
  final String? faviconUrl;

  factory AppBranding.fromJson(Json json) {
    final name = asString(json['company_name']).trim();
    final subtitle = asString(json['app_subtitle']).trim();
    return AppBranding(
      name: name.isEmpty ? AppConfig.fallbackAppName : name,
      subtitle: subtitle.isEmpty ? null : subtitle,
      logoUrl: asStringOrNull(json['logo_url']),
      faviconUrl: asStringOrNull(json['favicon_url']),
    );
  }
}

/// Public `/branding` endpoint: available before sign-in.
final appBrandingProvider = FutureProvider<AppBranding>((ref) async {
  final json = await ref.watch(apiClientProvider).get('/branding', skipSession: true);
  return AppBranding.fromJson(json);
});

/// The system name used as the app title: from `/branding`, else from the signed-in
/// user's profile (same setting), else the built-in fallback while offline.
final appNameProvider = Provider<String>((ref) {
  final branded = ref.watch(appBrandingProvider).asData?.value.name;
  if (branded != null && branded.isNotEmpty) return branded;
  final fromUser = ref.watch(currentUserProvider)?.companyName ?? '';
  return fromUser.isNotEmpty ? fromUser : AppConfig.fallbackAppName;
});

/// The tagline under the system name, or null when none is known yet.
final appSubtitleProvider = Provider<String?>((ref) {
  final branded = ref.watch(appBrandingProvider).asData?.value.subtitle;
  if (branded != null) return branded;
  final fromUser = ref.watch(currentUserProvider)?.appSubtitle ?? '';
  return fromUser.isEmpty ? null : fromUser;
});
