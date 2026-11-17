import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/config/branding.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';

/// Root widget. The window / task-switcher title is the system name set by the
/// admin in the web app (Settings > Company name), not a hard-coded brand.
class StoreSystemApp extends ConsumerWidget {
  const StoreSystemApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final colors = ref.watch(brandColorsProvider);
    return MaterialApp.router(
      title: ref.watch(appNameProvider),
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(colors),
      darkTheme: AppTheme.dark(colors),
      themeMode: ThemeMode.system,
      routerConfig: router,
    );
  }
}
