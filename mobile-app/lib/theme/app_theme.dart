import 'package:flutter/material.dart';

/// The shop's palette — "one task".
///
/// A near-black ground and a single yellow. The yellow is not decoration
/// and never appears twice on a screen: it marks the one thing to be
/// touched. That is the whole design — a person holding the phone with
/// floury hands at four in the morning should be able to see, without
/// reading, where their thumb goes.
///
/// Dark is the design. Light is the same identity in daylight — white
/// ground, the same yellow, the same rule about it appearing once — for
/// the owner reading figures at a desk rather than the floor reading them
/// beside an oven.
///
/// The ember ramp survives from the previous palette because a few
/// readings genuinely are a scale rather than a state: the diesel meter
/// fills from dull to bright as the quota goes. It is now built out of
/// the yellow so it belongs to this palette rather than the old one.
class AppColors {
  // ------------------------------------------------------- the yellow

  /// The one accent. Everything touchable is this; nothing else is.
  static const Color signal = Color(0xFFF5C518);

  /// On a pale ground the same yellow turns to highlighter, so it darkens.
  static const Color signalInk = Color(0xFF8A6D00);

  /// The accent as it should appear at this brightness.
  ///
  /// Anything drawing the accent itself — a border, a label, an outline —
  /// asks for it this way rather than naming a constant, because getting
  /// it wrong is invisible until someone opens the app in daylight.
  static Color signalFor(Brightness brightness) =>
      brightness == Brightness.dark ? signal : signalInk;

  /// Text and icons sitting on the yellow.
  static const Color onSignal = Color(0xFF17150A);

  // ---------------------------------------------------- the ember ramp

  /// Dullest — plenty left, nothing to attend to.
  static const Color emberCool = Color(0xFF5A4A12);

  /// Warming — worth noticing.
  static const Color emberWarm = Color(0xFFA88A16);

  /// Hot — the accent proper, and the one buttons carry.
  static const Color emberHot = signal;

  /// Palest — nearly spent, or the figure of the moment.
  static const Color emberPale = Color(0xFFFCE79A);

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

  // ------------------------------------------------------------ night

  static const Color iron = Color(0xFF111214); // the dark ground
  static const Color ironSurface = Color(0xFF17191D);
  static const Color ironCard = Color(0xFF1D1F23);
  static const Color ironLine = Color(0xFF2A2C30);

  // ------------------------------------------------------------- day

  static const Color ash = Color(0xFFF4F4F2);
  static const Color ashSurface = Color(0xFFFFFFFF);
  static const Color ashLine = Color(0xFFDDDEDB);

  /// Kept for the few places that name it; the accent on a pale ground.
  static const Color crust = signalInk;

  /// Semantic, and deliberately nowhere near the yellow: a settled account
  /// and a button waiting to be pressed must never be told apart by shade.
  static const Color success = Color(0xFF1E6B44);
  static const Color successDark = Color(0xFF4CBF87);
  static const Color info = Color(0xFF3A6EA5);
  static const Color danger = Color(0xFFB3352A);
  static const Color dangerDark = Color(0xFFF07C6E);
  static const Color warning = Color(0xFFB37A0E);

  // ------------------------------------------------------------ money

  /// Money in, money out, and money that is neither.
  ///
  /// Not the same idea as [success] and [danger], which mean "fine" and
  /// "wrong": a large expense is not an error and a settled debt is not
  /// income. The admin screens had this pair written as bare hex in fifty
  /// places, which is how the app ended up with two greens that were
  /// almost but not quite the same.
  ///
  /// Mid-toned on purpose — these have to read on the near-black ground
  /// and on white, and a figure is the last thing that should change
  /// meaning with the time of day.
  static const Color moneyIn = Color(0xFF2E9E6B);
  static const Color moneyOut = Color(0xFFD1495B);
  static const Color moneyNeutral = Color(0xFF3B82C4);

  /// Goods rather than money — flour arriving, and flour that belongs to
  /// another shop. Kept off the yellow like everything else: these label a
  /// kind of record, and the yellow means «press this».
  static const Color stock = Color(0xFF3F8F86);
  static const Color partner = Color(0xFF6C63FF);

  // -------------------------------------------------------- attention

  /// Something to look at, as opposed to something to press.
  ///
  /// An overdue follow-up, an advance waiting on an answer, thin
  /// attendance. Deliberately an orange rather than the yellow: the two
  /// have to be told apart across a room, because one of them means the
  /// thumb goes here and the other does not.
  ///
  /// One value for both grounds, like the money pair — partly so it reads
  /// the same at three in the morning as at noon, and partly so it can be
  /// `const`, which is what the enums and static fields that need it are.
  static const Color attention = Color(0xFFC2691C);

  /// Where an amount falls, by sign. Zero is neutral rather than positive:
  /// a balance of nothing is not good news, it is no news.
  static Color forAmount(num amount) => switch (amount) {
        > 0 => moneyIn,
        < 0 => moneyOut,
        _ => moneyNeutral,
      };
}

class AppTheme {
  static const _fontFamily = 'Vazirmatn';

  /// Daylight, same identity.
  ///
  /// The yellow does not survive the trip: at full strength on a pale
  /// ground it stops reading as a button and starts reading as a
  /// highlighter drawn over one. So the *button* darkens to
  /// [AppColors.signalInk] and the yellow keeps only the jobs where it
  /// sits on something dark — a chip, a filled marker — which is what
  /// holds the two themes together as one design rather than two.
  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: AppColors.signalInk,
      brightness: Brightness.light,
      primary: AppColors.signalInk,
      onPrimary: Colors.white,
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
      seedColor: AppColors.signal,
      brightness: Brightness.dark,
      primary: AppColors.signal,
      // Black on the yellow, not white: at this brightness white text on
      // yellow is unreadable, and Material's own contrast pick lands on
      // white often enough to be worth stating.
      onPrimary: AppColors.onSignal,
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
