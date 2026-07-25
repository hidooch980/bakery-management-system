import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Thrown for any non-2xx API response, carrying the backend's Persian message.
class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.errors});

  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;

  bool get isUnauthorized => statusCode == 401;

  bool get isForbidden => statusCode == 403;

  @override
  String toString() => message;
}

/// Single entry point for every backend call. Owns the token lifecycle so no
/// screen has to think about headers.
class ApiClient {
  ApiClient({String? baseUrl})
      : _dio = Dio(
          BaseOptions(
            baseUrl: baseUrl ?? defaultBaseUrl,
            connectTimeout: const Duration(seconds: 15),
            receiveTimeout: const Duration(seconds: 20),
            headers: {'Accept': 'application/json'},
            // Let non-2xx flow through so we can map them to ApiException.
            validateStatus: (status) => status != null && status < 500,
          ),
        ) {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _storage.read(key: _tokenKey);
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
      ),
    );
  }

  /// 10.0.2.2 is the Android emulator's alias for the host machine.
  /// Override with --dart-define=API_BASE_URL=... for a real device.
  static const defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const _tokenKey = 'auth_token';

  final Dio _dio;
  final _storage = const FlutterSecureStorage();

  Future<void> saveToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  Future<Map<String, dynamic>> get(String path,
      {Map<String, dynamic>? query}) async {
    return _unwrap(await _send(() => _dio.get(path, queryParameters: query)));
  }

  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) async {
    return _unwrap(await _send(() => _dio.post(path, data: body)));
  }

  Future<Map<String, dynamic>> put(String path, [Map<String, dynamic>? body]) async {
    return _unwrap(await _send(() => _dio.put(path, data: body)));
  }

  Future<Response<dynamic>> _send(Future<Response<dynamic>> Function() call) async {
    try {
      return await call();
    } on DioException catch (e) {
      throw ApiException(
        switch (e.type) {
          DioExceptionType.connectionTimeout ||
          DioExceptionType.receiveTimeout =>
            'اتصال به سرور برقرار نشد. اینترنت را بررسی کنید.',
          DioExceptionType.connectionError =>
            'سرور در دسترس نیست. آدرس سرور را بررسی کنید.',
          _ => 'خطا در ارتباط با سرور.',
        },
      );
    }
  }

  /// Every backend response uses the same {success, message, data} envelope.
  Map<String, dynamic> _unwrap(Response<dynamic> response) {
    final body = response.data;

    if (body is! Map<String, dynamic>) {
      throw ApiException('پاسخ سرور نامعتبر بود.', statusCode: response.statusCode);
    }

    if (response.statusCode! >= 200 && response.statusCode! < 300) {
      return body;
    }

    throw ApiException(
      body['message'] as String? ?? 'خطای نامشخص رخ داد.',
      statusCode: response.statusCode,
      errors: body['errors'] as Map<String, dynamic>?,
    );
  }
}
