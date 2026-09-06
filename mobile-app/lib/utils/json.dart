/// Reading what the server sent, in the shapes it actually sends them.
///
/// PHP has one type for «list» and «dictionary», and `json_encode` decides
/// between `[]` and `{}` by looking at the keys. A grouped collection with
/// nothing in it therefore arrives as `[]` — so `by_payment_type` is an
/// object on every day the shop sold something and an empty array on the
/// days it did not.
///
/// Dart's `as Map?` throws on that array. The throw happens while the
/// widget is building, and a widget that throws while building is drawn by
/// the release-mode `ErrorWidget` as a plain grey rectangle: no message, no
/// hint, nothing in any log the shop can reach. The owner sent a
/// photograph of one, half a screen tall, under «تفکیک فروش».
///
/// So a keyed group is read through here, where the empty array is simply
/// one of the ways «nothing» arrives.
Map<String, dynamic> keyedGroup(dynamic value) {
  if (value is Map) return value.cast<String, dynamic>();

  // `[]` — PHP's empty collection. A non-empty list would be a genuine
  // shape change and is still «nothing I can read as a group».
  return const {};
}

/// The rows of a report, in the shapes those arrive in too.
///
/// The mirror of [keyedGroup], and the same trap the other way round: a
/// grouped collection is a list when the server calls `->values()` on it
/// and an object when it does not, and `as List?` throws on the object
/// exactly as `as Map?` threw on the array. Fifteen places read rows that
/// way, each correct against the endpoint it was written for and none of
/// them able to survive that endpoint being written differently later.
///
/// A keyed group read as rows keeps its values, because «one row per
/// payment type» is what the caller wanted either way and the key is
/// already inside each row where it matters.
List<Map<String, dynamic>> rowList(dynamic value) {
  final raw = switch (value) {
    List list => list,
    Map map => map.values,
    _ => const [],
  };

  // Anything in there that is not itself a row is dropped rather than
  // cast: one malformed entry must not take the section off the screen.
  return raw.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
}

/// The name to put on a row about a person, whatever arrived.
///
/// A row whose person did not come through used to read «—», which is
/// indistinguishable from a person whose name is genuinely blank — and
/// «نام کارکنان نمایش نمی‌دهد» could mean either. One of those is the
/// server not sending the relation and the other is the record itself, and
/// they are fixed in different places.
///
/// So an absent name falls back to the id the row already carries. «کارمند
/// #7» is not pretty, but it is a fact somebody can act on: the shop knows
/// who number seven is, and it says plainly that the name is what is
/// missing rather than the person.
String personName(Map<String, dynamic> person, {Object? fallbackId}) {
  final name = '${person['name'] ?? ''}'.trim();

  if (name.isNotEmpty) return name;

  final id = person['id'] ?? fallbackId;

  return id == null ? 'بدون نام' : 'کارمند #$id';
}
