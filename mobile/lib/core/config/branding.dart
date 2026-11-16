import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/json.dart';
import '../api/providers.dart';
import 'app_config.dart';

class AppBranding {
  const AppBranding({required this.name, this.logoUrl, this.faviconUrl});

  final String name;
  final String? logoUrl;
  final String? faviconUrl;

  factory AppBranding.fromJson(Json json) => AppBranding(
    name: asString(json['company_name'], AppConfig.appName),
    logoUrl: asStringOrNull(json['logo_url']),
    faviconUrl: asStringOrNull(json['favicon_url']),
  );
}

final appBrandingProvider = FutureProvider<AppBranding>((ref) async {
  final json = await ref
      .watch(apiClientProvider)
      .get('/branding', skipSession: true);
  return AppBranding.fromJson(json);
});
