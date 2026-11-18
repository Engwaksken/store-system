import 'package:flutter/material.dart';

/// Brand colours.
class Brand {
  static const navy = Color(0xFF12243E);
  static const teal = Color(0xFF0F766E);
  static const gold = Color(0xFFC99A2E);
}

/// Badge colours for `status_tone` and dashboard `tone`.
enum Tone { pending, approved, rejected, partial, muted, navy, gold }

Tone toneFromStatus(String? statusTone) => switch (statusTone) {
      'pending' => Tone.pending,
      'approved' => Tone.approved,
      'rejected' => Tone.rejected,
      'partial' => Tone.partial,
      _ => Tone.muted,
    };

/// Dashboard card tone: `navy | gold | bad | ok | ""`.
Tone toneFromCard(String? tone) => switch (tone) {
      'navy' => Tone.navy,
      'gold' => Tone.gold,
      'bad' => Tone.rejected,
      'ok' => Tone.approved,
      _ => Tone.muted,
    };

/// Notification `kind`: `info | warn | bad | ok`.
Tone toneFromKind(String? kind) => switch (kind) {
      'warn' => Tone.pending,
      'bad' => Tone.rejected,
      'ok' => Tone.approved,
      _ => Tone.navy,
    };

/// Background + foreground pair with WCAG AA contrast in each brightness.
class ToneColors {
  const ToneColors(this.background, this.foreground);
  final Color background;
  final Color foreground;

  static ToneColors of(Tone tone, Brightness brightness) {
    final dark = brightness == Brightness.dark;
    switch (tone) {
      case Tone.pending:
        return dark
            ? const ToneColors(Color(0xFF4A3A0A), Color(0xFFFCD34D))
            : const ToneColors(Color(0xFFFEF3C7), Color(0xFF854D0E));
      case Tone.approved:
        return dark
            ? const ToneColors(Color(0xFF0E3B24), Color(0xFF86EFAC))
            : const ToneColors(Color(0xFFDCFCE7), Color(0xFF166534));
      case Tone.rejected:
        return dark
            ? const ToneColors(Color(0xFF4C1515), Color(0xFFFCA5A5))
            : const ToneColors(Color(0xFFFEE2E2), Color(0xFF991B1B));
      case Tone.partial:
        return dark
            ? const ToneColors(Color(0xFF172E54), Color(0xFF93C5FD))
            : const ToneColors(Color(0xFFDBEAFE), Color(0xFF1E40AF));
      case Tone.muted:
        return dark
            ? const ToneColors(Color(0xFF2E3440), Color(0xFFCBD5E1))
            : const ToneColors(Color(0xFFF1F5F9), Color(0xFF475569));
      case Tone.navy:
        return dark
            ? const ToneColors(Color(0xFF1E3A5F), Color(0xFFBFD7F5))
            : const ToneColors(Color(0xFFE2E8F0), Brand.navy);
      case Tone.gold:
        return dark
            ? const ToneColors(Color(0xFF4A3812), Color(0xFFF3D27A))
            : const ToneColors(Color(0xFFFBF1D9), Color(0xFF7A5A12));
    }
  }
}

/// The system colours from the web app's Settings > Appearance (`/branding` -> `theme`).
class BrandColors {
  const BrandColors({this.primary = Brand.navy, this.accent = Brand.gold});

  /// Sidebar / headings on the web; app bar and buttons in the app.
  final Color primary;
  final Color accent;

  static const defaults = BrandColors();

  /// Text colour that stays readable on [background].
  static Color onColor(Color background) =>
      background.computeLuminance() > 0.45 ? const Color(0xFF111827) : Colors.white;

  /// `#RRGGBB` -> Color, or null.
  static Color? parse(Object? hex) {
    if (hex is! String) return null;
    final m = RegExp(r'^#?([0-9a-fA-F]{6})$').firstMatch(hex.trim());
    return m == null ? null : Color(0xFF000000 | int.parse(m.group(1)!, radix: 16));
  }

  static String toHex(Color c) =>
      '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';

  @override
  bool operator ==(Object other) => other is BrandColors && other.primary == primary && other.accent == accent;

  @override
  int get hashCode => Object.hash(primary, accent);
}

class AppTheme {
  static ThemeData light([BrandColors brand = BrandColors.defaults]) {
    final onPrimary = BrandColors.onColor(brand.primary);
    final scheme = ColorScheme.fromSeed(
      seedColor: brand.primary,
      brightness: Brightness.light,
    ).copyWith(
      primary: brand.primary,
      onPrimary: onPrimary,
      secondary: brand.primary,
      onSecondary: onPrimary,
      tertiary: brand.accent,
      onTertiary: BrandColors.onColor(brand.accent),
      surface: Colors.white,
    );
    // A cool grey page tinted with the brand colour, so white cards clearly stand out from it.
    return _base(scheme, brand).copyWith(
      scaffoldBackgroundColor: Color.alphaBlend(brand.primary.withValues(alpha: 0.05), const Color(0xFFEEF1F5)),
    );
  }

  static ThemeData dark([BrandColors brand = BrandColors.defaults]) {
    // In dark mode a lighter tone of the same colour keeps buttons readable.
    final scheme = ColorScheme.fromSeed(
      seedColor: brand.primary,
      brightness: Brightness.dark,
    ).copyWith(
      tertiary: ColorScheme.fromSeed(seedColor: brand.accent, brightness: Brightness.dark).primary,
    );
    // Page darker than the cards, which use surfaceContainer (see _base).
    return _base(scheme, brand).copyWith(scaffoldBackgroundColor: scheme.surfaceContainerLowest);
  }

  static ThemeData _base(ColorScheme scheme, BrandColors brand) {
    final isLight = scheme.brightness == Brightness.light;
    final radius = BorderRadius.circular(12);
    // Buttons use the system primary colour.
    final buttonBg = scheme.primary;
    final buttonFg = scheme.onPrimary;
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: isLight ? brand.primary : scheme.surface,
        foregroundColor: isLight ? BrandColors.onColor(brand.primary) : scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 2,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        // A soft shadow plus a hairline border lifts cards off the page in both themes.
        elevation: isLight ? 1.5 : 0,
        shadowColor: const Color(0x2912243E),
        surfaceTintColor: Colors.transparent,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: radius,
          side: BorderSide(color: scheme.outlineVariant.withValues(alpha: isLight ? 0.5 : 0.8)),
        ),
        color: isLight ? scheme.surface : scheme.surfaceContainer,
        clipBehavior: Clip.antiAlias,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: buttonBg,
          foregroundColor: buttonFg,
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: buttonBg,
          foregroundColor: buttonFg,
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: scheme.primary,
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: scheme.primary)),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: buttonBg,
        foregroundColor: buttonFg,
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
        filled: true,
        fillColor: isLight ? Colors.white : scheme.surfaceContainerHighest,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: isLight ? Colors.white : scheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        elevation: 3,
        shadowColor: const Color(0x3312243E),
        indicatorColor: scheme.primaryContainer,
        labelTextStyle: WidgetStateProperty.all(
          const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
        ),
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      ),
      dividerTheme: DividerThemeData(color: scheme.outlineVariant.withValues(alpha: 0.6)),
      snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
    );
  }
}
