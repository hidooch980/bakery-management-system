import 'package:shared_preferences/shared_preferences.dart';

/// What the shop entered last time, so it does not have to be typed again.
///
/// A bakery bakes the same way most days: this one has put ten bags in on
/// nearly every batch of the last month. Asking for that from an empty
/// box, on a number keypad, with floury hands, is asking for a mistyped
/// figure as much as it is asking for time.
///
/// Kept on the phone rather than the server. It is a convenience for
/// whoever holds this handset, not a fact about the shop, and it has to
/// work when the connection does not.
class LastUsed {
  const LastUsed._();

  static const _bags = 'last_dough_bags';

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

  // Spray flour and nanino were remembered here once, on the reasoning
  // that the last figure beats no guess at all. Both are gone for the same
  // reason: the remembered figure is submitted by both entry screens
  // without either one being opened to look at it. Nanino went first — a
  // one-day-in-twenty order of a hundred would have quietly ridden every
  // batch after it. Spray flour followed on the owner's rule (1405/06/08):
  // it starts at zero and the chane maker types what actually went on.
}
