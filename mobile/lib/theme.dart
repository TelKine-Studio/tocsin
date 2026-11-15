import 'package:flutter/material.dart';

/// Visual identity of the app, aligned with the web interface.
class TocsinTheme {
  static const brand = Color(0xFFE8590C);

  static ThemeData build(Brightness brightness) {
    final dark = brightness == Brightness.dark;
    final scheme = ColorScheme.fromSeed(
      seedColor: brand,
      brightness: brightness,
      // Same orange as the app icon and the logo.
      primary: dark ? const Color(0xFFFF8A4C) : brand,
      surface: dark ? const Color(0xFF0F0F11) : const Color(0xFFF7F7F8),
    );
    final cardColor = dark ? const Color(0xFF18181B) : Colors.white;
    final outline = dark ? const Color(0xFF27272A) : const Color(0xFFE4E4E7);

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: scheme.surface,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        centerTitle: false,
        scrolledUnderElevation: 0.5,
      ),
      cardTheme: CardThemeData(
        color: cardColor,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: BorderSide(color: outline),
        ),
      ),
      dividerTheme: DividerThemeData(color: outline, space: 1, thickness: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: cardColor,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 16,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: outline),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.primary, width: 2),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          side: BorderSide(color: outline),
        ),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: dark ? Colors.white : const Color(0xFF18181B),
        foregroundColor: dark ? const Color(0xFF18181B) : Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        side: BorderSide.none,
        backgroundColor: scheme.primaryContainer.withValues(alpha: 0.5),
        labelStyle: TextStyle(
          color: scheme.onPrimaryContainer,
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
        padding: const EdgeInsets.symmetric(horizontal: 4),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    );
  }

  /// Same palette as the web interface (topic avatars).
  static const _palette = [
    (Color(0xFFFFEDD5), Color(0xFFC2410C)),
    (Color(0xFFE0F2FE), Color(0xFF0369A1)),
    (Color(0xFFD1FAE5), Color(0xFF047857)),
    (Color(0xFFEDE9FE), Color(0xFF6D28D9)),
    (Color(0xFFFFE4E6), Color(0xFFBE123C)),
    (Color(0xFFFEF3C7), Color(0xFFB45309)),
    (Color(0xFFCCFBF1), Color(0xFF0F766E)),
  ];

  static (Color, Color) topicColors(String name, Brightness brightness) {
    final hash = name.codeUnits.fold<int>(
      0,
      (h, c) => (h * 31 + c) & 0x7fffffff,
    );
    final (background, foreground) = _palette[hash % _palette.length];
    return brightness == Brightness.dark
        ? (foreground.withValues(alpha: 0.22), background)
        : (background, foreground);
  }
}
