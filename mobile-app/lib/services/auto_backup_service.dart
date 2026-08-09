import 'package:workmanager/workmanager.dart';
import 'backup_service.dart';

class AutoBackupService {
  static void callbackDispatcher() {
    Workmanager().executeTask((task, inputData) async {
      if (task == "daily_backup") {
        final backup = BackupService();
        await backup.createLocalBackup(
          '{"time":"${DateTime.now()}","type":"daily"}',
        );
      }
      return Future.value(true);
    });
  }

  static Future<void> init() async {
    await Workmanager().initialize(
      callbackDispatcher,
    );

    await Workmanager().registerPeriodicTask(
      "bakery_daily_backup",
      "daily_backup",
      frequency: const Duration(hours: 24),
    );
  }
}
