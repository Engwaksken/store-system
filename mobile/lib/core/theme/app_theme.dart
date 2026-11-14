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

class AppTheme {
  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: Brand.navy,
      brightness: Brightness.light,
    ).copyWith(
      primary: Brand.navy,
      onPrimary: Colors.white,
      secondary: Brand.teal,
      onSecondary: Colors.white,
      tertiary: Brand.gold,
      onTertiary: const Color(0xFF231A00),
      surface: Colors.white,
    );
    return _base(scheme).copyWith(
      scaffoldBackgroundColor: const Color(0xFFF5F7FA),
    );
  }

  static ThemeData dark() {
    final scheme = ColorScheme.fromSeed(
      seedColor: Brand.navy,
      brightness: Brightness.dark,
    ).copyWith(
      primary: const Color(0xFFA9C7F0),
      onPrimary: const Color(0xFF0B1A2E),
      secondary: const Color(0xFF5EEAD4),
      onSecondary: const Color(0xFF00201C),
      tertiary: const Color(0xFFE8C468),
    );
    return _base(scheme);
  }

  static ThemeData _base(ColorScheme scheme) {
    final isLight = scheme.brightness == Brightness.light;
    final radius = BorderRadius.circular(12);
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: isLight ? Brand.navy : scheme.surface,
        foregroundColor: isLight ? Colors.white : scheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 2,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: radius,
          side: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.6)),
        ),
        color: scheme.surface,
        clipBehavior: Clip.antiAlias,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: isLight ? Brand.teal : scheme.secondary,
          foregroundColor: isLight ? Colors.white : scheme.onSecondary,
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: isLight ? Brand.teal : scheme.secondary,
        foregroundColor: isLight ? Colors.white : scheme.onSecondary,
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
        filled: true,
        fillColor: isLight ? Colors.white : scheme.surfaceContainerHighest,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      navigationBarTheme: NavigationBarThemeData(
        indicatorColor: isLight ? const Color(0xFFD7E6F7) : scheme.secondaryContainer,
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
