import 'package:flutter/material.dart';

/// The shop's palette — "the kiln".
///
/// Iron and ember. The ground is the dark of a cold oven and the warm
/// notes are the colours iron actually passes through as it heats, which
/// is why they are a ramp rather than a single accent: [emberCool] through
/// [emberPale] is a scale, and where a figure sits on it is the reading.
/// A quota nearly spent glows; one barely touched is dull. That saves the
/// eye a number at arm's length across a hot, bright room, which is where
/// most of these screens are read.
///
/// Dark is the design; light is the same identity in daylight, for the
/// owner reading figures at a desk rather than the floor reading them
/// beside an oven. Both keep contrast high rather than softening it.
class AppColors {
  // ---------------------------------------------------- the ember ramp

  /// Dullest — plenty left, nothing to attend to.
  static const Color emberCool = Color(0xFF7A1F12);

  /// Warming — worth noticing.
  static const Color emberWarm = Color(0xFFC24A16);

  /// Hot — the accent proper, and the one buttons carry.
  static const Color emberHot = Color(0xFFE8952D);

  /// Palest — nearly spent, or the figure of the moment.
  static const Color emberPale = Color(0xFFF5D08A);

  /// The ramp in order, for anything mapping a proportion onto it.
  static const List<Color> ember = [emberCool, emberWarm, emberHot, emberPale];

  /// Where a fraction of 0..1 falls on the ramp.
  static Color emberAt(double fraction) {
    final f = fraction.clamp(0.0, 1.0);

    if (f >= 1) return emberPale;

    final span = 1 / (ember.length - 1);
    final index = (f / span).floor();

    return Color.lerp(ember[index], ember[index + 1], (f - index * span) / span)!;
  }

  // ------------------------------------------------------------ iron

  static const Color iron = Color(0xFF12161C); // cold oven — the dark ground
  static const Color ironSurface = Color(0xFF1A2029);
  static const Color ironCard = Color(0xFF212936);
  static const Color ironLine = Color(0xFF2A333F);

  // ----------------------------------------------------------- ash

  /// Daylight: ash and flour rather than white, so the ember still reads.
  static const Color ash = Color(0xFFEDEEF0);
  static const Color ashSurface = Color(0xFFFAFAFB);
  static const Color ashLine = Color(0xFFD9DDE2);

  /// The accent has to darken for a pale ground or it turns to highlighter.
  static const Color crust = Color(0xFFA8501A);

  /// Semantic, and deliberately off the ramp: a settled account and a
  /// primary button must never be told apart by shade alone.
  static const Color success = Color(0xFF0B7A54);
  static const Color successDark = Color(0xFF3FB98B);
  static const Color info = Color(0xFF2C6FA8);
  static const Color danger = Color(0xFFC5373C);
  static const Color dangerDark = Color(0xFFF1666B);
  static const Color warning = Color(0xFFC24A16);
}

class AppTheme {
  static const _fontFamily = 'Vazirmatn';

  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.crust,
      brightness: Brightness.light,
      primary: AppColors.crust,
      secondary: AppColors.info,
      surface: AppColors.ashSurface,
      error: AppColors.danger,
      outlineVariant: AppColors.ashLine,
    );

    return _base(scheme).copyWith(
      scaffoldBackgroundColor: AppColors.ash,
    );
  }

  static ThemeData dark() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.emberHot,
      brightness: Brightness.dark,
      primary: AppColors.emberHot,
      secondary: AppColors.info,
      surface: AppColors.ironSurface,
      error: AppColors.dangerDark,
      outlineVariant: AppColors.ironLine,
    );

    return _base(scheme).copyWith(
      scaffoldBackgroundColor: AppColors.iron,
      cardTheme: _base(scheme).cardTheme.copyWith(color: AppColors.ironCard),
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
        color: isDark ? AppColors.ironCard : AppColors.ashSurface,
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
        fillColor: isDark ? AppColors.ironCard : AppColors.ashSurface,
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
