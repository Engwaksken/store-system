import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/config/branding.dart';
import 'package:store_system/core/theme/app_theme.dart';

import '../helpers.dart';

void main() {
  test('parse / toHex / readable text colour', () {
    expect(BrandColors.parse('#7A1F2B'), const Color(0xFF7A1F2B));
    expect(BrandColors.parse('nope'), isNull);
    expect(BrandColors.toHex(const Color(0xFF7A1F2B)), '#7A1F2B');
    expect(BrandColors.onColor(const Color(0xFF12243E)), Colors.white);
    expect(BrandColors.onColor(const Color(0xFFFFE08A)), isNot(Colors.white));
  });

  test('buttons, FAB and app bar use the system primary colour', () {
    const brand = BrandColors(primary: Color(0xFF7A1F2B), accent: Color(0xFFD4A33A));
    final t = AppTheme.light(brand);
    final states = <WidgetState>{};
    expect(t.filledButtonTheme.style!.backgroundColor!.resolve(states), brand.primary);
    expect(t.elevatedButtonTheme.style!.backgroundColor!.resolve(states), brand.primary);
    expect(t.floatingActionButtonTheme.backgroundColor, brand.primary);
    expect(t.appBarTheme.backgroundColor, brand.primary);
    expect(t.colorScheme.primary, brand.primary);
  });

  test('colours come from /branding and are saved for the next start', () async {
    final cache = MemoryBrandingCache();
    final container = ProviderContainer(overrides: [
      ...fakeOverrides(),
      brandingCacheProvider.overrideWithValue(cache),
    ]);
    addTearDown(container.dispose);
    expect(container.read(brandColorsProvider), BrandColors.defaults);
    await container.read(appBrandingProvider.future);
    expect(container.read(brandColorsProvider).primary, const Color(0xFF12243E));
    expect((await cache.load())?.primary, const Color(0xFF12243E));
  });

  test('saved colours are used before the server answers', () {
    final container = ProviderContainer(overrides: [
      ...fakeOverrides(),
      cachedBrandingProvider.overrideWithValue(
        const AppBranding(name: 'X', primary: Color(0xFF7A1F2B), accent: Color(0xFFD4A33A)),
      ),
    ]);
    addTearDown(container.dispose);
    expect(container.read(brandColorsProvider).primary, const Color(0xFF7A1F2B));
  });
}
