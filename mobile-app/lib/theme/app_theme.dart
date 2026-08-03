import 'package:flutter/material.dart';

/// The shop's palette.
///
/// The ground is a cool neutral and the only warm note is the colour of a
/// baked top, so the accent carries every button and figure that matters
/// instead of competing with a warm background for attention. Screens are
/// read at arm's length in a bright shop, so contrast is kept high in both
/// brightnesses rather than softened.
class AppColors {
  static const Color crust = Color(0xFFC2740F); // baked top — the accent
  static const Color wheat = Color(0xFFE9A03C); // the same, lifted for dark

  static const Color paper = Color(0xFFEEF1F4); // cool light ground
  static const Color surface = Color(0xFFFFFFFF);
  static const Color line = Color(0xFFDCE2E8);

  static const Color darkBase = Color(0xFF0B0F14); // cool dark ground
  static const Color darkSurface = Color(0xFF151B23);
  static const Color darkCard = Color(0xFF1B222B);
  static const Color darkLine = Color(0xFF28313C);

  /// Semantic, and deliberately not the accent: a settled account and a
  /// primary button must never be told apart by shade alone.
  static const Color success = Color(0xFF0B7A54);
  static const Color successDark = Color(0xFF35C793);
  static const Color info = Color(0xFF2C6FA8);
  static const Color danger = Color(0xFFC5373C);
  static const Color dangerDark = Color(0xFFF1666B);
  static const Color warning = Color(0xFFC2740F);
}

class AppTheme {
  static const _fontFamily = 'Vazirmatn';

  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.crust,
      brightness: Brightness.light,
      primary: AppColors.crust,
      secondary: AppColors.info,
      surface: AppColors.surface,
      error: AppColors.danger,
      outlineVariant: AppColors.line,
    );

    return _base(scheme).copyWith(
      scaffoldBackgroundColor: AppColors.paper,
    );
  }

  static ThemeData dark() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.wheat,
      brightness: Brightness.dark,
      primary: AppColors.wheat,
      secondary: AppColors.info,
      surface: AppColors.darkSurface,
      error: AppColors.dangerDark,
      outlineVariant: AppColors.darkLine,
    );

    return _base(scheme).copyWith(
      scaffoldBackgroundColor: AppColors.darkBase,
      cardTheme: _base(scheme).cardTheme.copyWith(color: AppColors.darkCard),
    );
  }

  /// Everything that is identical between the two brightnesses lives here,
  /// so light and dark can never drift apart visually.
  static ThemeData _base(ColorScheme scheme) {
    final isDark = scheme.brightness == Brightness.dark;

    // Vazirmatn is bundled as an asset, so the app renders correctly offline.
    final textTheme = ThemeData(brightness: scheme.brightness)
        .textTheme
        .apply(fontFamily: _fontFamily);

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      fontFamily: _fontFamily,
      textTheme: textTheme,
      splashFactory: InkSparkle.splashFactory,

      appBarTheme: AppBarTheme(
        centerTitle: true,
        elevation: 0,
        scrolledUnderElevation: 2,
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        titleTextStyle: textTheme.titleLarge?.copyWith(
          fontWeight: FontWeight.w700,
          color: scheme.onSurface,
        ),
      ),

      cardTheme: CardThemeData(
        elevation: 0,
        margin: EdgeInsets.zero,
        color: isDark ? AppColors.darkCard : Colors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(20),
          side: BorderSide(
            color: scheme.outlineVariant.withValues(alpha: isDark ? 0.35 : 0.6),
          ),
        ),
      ),

      // Large, high-contrast controls — this app is used with floury hands.
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size.fromHeight(58),
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
          elevation: 0,
          textStyle: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(58),
          textStyle: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(54),
          textStyle: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? AppColors.darkCard : Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 18),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: scheme.primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: scheme.error),
        ),
        labelStyle: TextStyle(color: scheme.onSurfaceVariant),
      ),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),

      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        side: BorderSide(color: scheme.outlineVariant),
      ),

      dialogTheme: DialogThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
      ),

      bottomSheetTheme: const BottomSheetThemeData(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
        ),
      ),

      dividerTheme: DividerThemeData(
        color: scheme.outlineVariant.withValues(alpha: 0.5),
        space: 1,
      ),

      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: FadeForwardsPageTransitionsBuilder(),
        },
      ),
    );
  }
}
