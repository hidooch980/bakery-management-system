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
