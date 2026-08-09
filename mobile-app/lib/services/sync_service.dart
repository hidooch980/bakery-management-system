import 'dart:io';

import 'package:dio/dio.dart';

import 'api_client.dart';

/// Ships a local backup file up to the server.
///
/// Multipart, so it cannot go through [ApiClient.post] which speaks JSON.
/// It still borrows that client's address and token rather than keeping its
/// own: the server moves at runtime via [ApiClient.useBaseUrl], and a
/// hard-coded address here would strand the upload the day the box changes.
class SyncService {
  SyncService(this._api, {Dio? dio}) : _dio = dio ?? Dio();

  final ApiClient _api;
  final Dio _dio;

  /// Returns false rather than throwing — a failed backup must not surface
  /// as a crash in front of someone mid-shift.
  Future<bool> uploadBackup(String filePath) async {
    try {
      final file = File(filePath);
      if (!await file.exists()) return false;

      final token = await _api.readToken();
      if (token == null) return false;

      final formData = FormData.fromMap({
        'backup': await MultipartFile.fromFile(
          file.path,
          filename: file.uri.pathSegments.last,
        ),
      });

      final response = await _dio.post<dynamic>(
        '${_api.baseUrl}/backup/upload',
        data: formData,
        options: Options(
          headers: {
            'Authorization': 'Bearer $token',
            'Accept': 'application/json',
          },
        ),
      );

      return response.statusCode == 200;
    } catch (_) {
      return false;
    }
  }
}
