import 'dart:io';
import 'package:path_provider/path_provider.dart';

class BackupService {
  Future<String> createLocalBackup(String data) async {
    final dir = await getApplicationDocumentsDirectory();
    final backupDir = Directory('${dir.path}/backups');

    if (!await backupDir.exists()) {
      await backupDir.create(recursive: true);
    }

    final file = File(
      '${backupDir.path}/bakery_backup_${DateTime.now().millisecondsSinceEpoch}.json',
    );

    await file.writeAsString(data);

    return file.path;
  }
}
