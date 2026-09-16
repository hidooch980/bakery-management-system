/// یکی از مغازه‌هایی که این شخص می‌تواند ببیند.
///
/// تقریباً همیشه یکی است — همان جایی که کار می‌کند. برای مالکی که چند
/// مغازه دارد، فهرست همان چیزی است که در بالای صفحه انتخاب می‌شود.
class MyBakery {
  const MyBakery({
    required this.id,
    required this.name,
    required this.isCurrent,
  });

  final int id;
  final String name;

  /// اینکه همین حالا ارقامِ روی صفحه مال این مغازه است.
  ///
  /// از سرور می‌آید نه از مقایسه در گوشی: گوشی ممکن است شناسه‌ای را
  /// فرستاده باشد که سرور نپذیرفته — آن‌وقت «انتخاب‌شده» چیزی است که
  /// سرور می‌گوید، نه چیزی که گوشی خواسته بود.
  final bool isCurrent;

  factory MyBakery.fromJson(Map<String, dynamic> json) => MyBakery(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name'] is String ? json['name'] as String : '',
        isCurrent: json['is_current'] == true,
      );
}
