import 'package:shared_preferences/shared_preferences.dart';

/// What the shop entered last time, so it does not have to be typed again.
///
/// A bakery bakes the same way most days: this one has put ten bags in and
/// five kilos of spray flour on nearly every batch of the last month, and
/// has never once shaped a nanino. Asking for all three from an empty box,
/// on a number keypad, with floury hands, is asking for a mistyped figure
/// as much as it is asking for time.
///
/// Kept on the phone rather than the server. It is a convenience for
/// whoever holds this handset, not a fact about the shop, and it has to
/// work when the connection does not.
class LastUsed {
  const LastUsed._();

  static const _bags = 'last_dough_bags';

  static const _spray = 'last_spray_flour_kg';


  /// Ten, until the shop says otherwise — the commonest batch by far.
  static Future<int> doughBags() async {
    final prefs = await SharedPreferences.getInstance();

    return prefs.getInt(_bags) ?? 10;
  }

  static Future<void> rememberDoughBags(int bags) async {
    if (bags <= 0) return;

    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_bags, bags);
  }

  static Future<double> sprayFlourKg() async {
    final prefs = await SharedPreferences.getInstance();

    return prefs.getDouble(_spray) ?? 5;
  }

  static Future<void> rememberSprayFlourKg(double kg) async {
    if (kg < 0) return;

    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble(_spray, kg);
  }

  // Nanino was remembered here too, on the reasoning that the last count
  // beats no guess at all. That is true of spray flour, which goes on
  // every batch. It is false of nanino: this shop shaped some on one day
  // out of the last twenty, and the remembered figure is submitted by
  // both entry screens without either one being opened to look at it. One
  // order of a hundred would have quietly ridden every batch after it and
  // taken a kilo of dough with each loaf that was never shaped.
}
