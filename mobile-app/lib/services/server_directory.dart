import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Where the backend lives today.
///
/// The address used to be baked into the APK at build time, so the day the
/// server moved every installed copy stopped working until its owner found
/// and installed a new build. The address is now published in the
/// repository instead, at a URL that never changes, and the app reads it on
/// startup — moving the server becomes editing one file.
///
/// More than one address can be published at a time, which is what makes a
/// move survivable: the old and the new server both run for a day, the file
/// lists both, and each phone settles on whichever answers. Once the old
/// machine is switched off it simply stops answering, and the phones that
/// were still on it move across by themselves.
///
/// Nothing here is allowed to keep the user waiting. No signal, a blocked
/// GitHub, or a malformed file all fall back: first to the address that
/// worked last time, then to the one compiled in.
class ServerDirectory {
  ServerDirectory({Dio? dio, String? repo, String? fallback})
      : _dio = dio ?? Dio(),
        _repo = repo ?? defaultRepo,
        _fallback = fallback ?? defaultBaseUrl;

  /// Same repository the updater reads, so a fork only overrides it once.
  static const defaultRepo = String.fromEnvironment(
    'UPDATE_REPO',
    defaultValue: 'hidooch980/bakery-management-system',
  );

  /// 10.0.2.2 is the Android emulator's alias for the host machine.
  static const defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const _cacheKey = 'api_base_url';

  /// Short on purpose. The address has almost never changed and the server
  /// is almost always the first one tried, so neither the lookup nor the
  /// probes may hold up the login screen.
  static const _lookupTimeout = Duration(seconds: 6);

  static const _probeTimeout = Duration(seconds: 4);

  final Dio _dio;
  final String _repo;
  final String _fallback;

  /// The address to talk to, resolved best-effort. Never throws.
  Future<String> resolve() async {
    final cached = await _readCache();

    List<String> candidates;

    try {
      candidates = await _fetchPublished();
    } on Object {
      // Offline, blocked, or serving something unreadable — whatever the
      // app already had is still the best guess anyone has.
      candidates = const [];
    }

    if (candidates.isEmpty) {
      return cached ?? _fallback;
    }

    // The address that worked last time goes first among equals: during a
    // move both servers answer, and a phone that is already talking to one
    // of them has no reason to hop to the other mid-day.
    if (cached != null && candidates.contains(cached)) {
      candidates = [cached, ...candidates.where((url) => url != cached)];
    }

    for (final candidate in candidates) {
      if (await _isAlive(candidate)) {
        await _writeCache(candidate);
        return candidate;
      }
    }

    // Every published address is unreachable, which on a phone with no
    // signal says nothing about the servers. Keep what worked before and
    // let the ordinary request errors surface.
    return cached ?? candidates.first;
  }

  /// Every address published in the repository, most preferred first.
  Future<List<String>> _fetchPublished() async {
    final response = await _dio.get<dynamic>(
      'https://raw.githubusercontent.com/$_repo/main/server.json',
      options: Options(
        receiveTimeout: _lookupTimeout,
        sendTimeout: _lookupTimeout,
        // A 404 is an answer, not a crash: it just means nothing is
        // published yet, so the app keeps using what it already had.
        validateStatus: (status) => status != null && status < 500,
        responseType: ResponseType.json,
      ),
    );

    if (response.statusCode != 200 || response.data is! Map) {
      return const [];
    }

    final data = response.data as Map;

    final urls = <String>[];

    void add(Object? value) {
      final url = normalise(value);

      // A file that lists the same address twice should probe it once.
      if (url != null && !urls.contains(url)) {
        urls.add(url);
      }
    }

    add(data['api_base_url']);

    if (data['fallback_urls'] is List) {
      for (final entry in data['fallback_urls'] as List) {
        add(entry);
      }
    }

    return urls;
  }

  /// Whether a server is up and is actually the bakery's.
  Future<bool> _isAlive(String baseUrl) async {
    try {
      final response = await _dio.get<dynamic>(
        '$baseUrl/health',
        options: Options(
          receiveTimeout: _probeTimeout,
          sendTimeout: _probeTimeout,
          validateStatus: (status) => status != null && status < 500,
          responseType: ResponseType.json,
        ),
      );

      final data = response.data;

      // Checking the body, not just the status: a parked domain or a
      // captive portal answers 200 to anything, and settling on one would
      // strand the phone somewhere that never serves the API.
      return response.statusCode == 200 &&
          data is Map &&
          data['service'] == 'bakery';
    } on Object {
      return false;
    }
  }

  /// Trims a published address into something Dio can use, or null if it is
  /// not an address at all. A trailing slash would double up against the
  /// paths every call appends.
  static String? normalise(Object? value) {
    if (value is! String) {
      return null;
    }

    final trimmed = value.trim().replaceAll(RegExp(r'/+$'), '');

    if (trimmed.isEmpty) {
      return null;
    }

    final uri = Uri.tryParse(trimmed);

    if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
      return null;
    }

    return trimmed;
  }

  Future<String?> _readCache() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return normalise(prefs.getString(_cacheKey));
    } on Object {
      return null;
    }
  }

  Future<void> _writeCache(String url) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_cacheKey, url);
    } on Object {
      // A cache that will not save is not worth failing startup over.
    }
  }
}
