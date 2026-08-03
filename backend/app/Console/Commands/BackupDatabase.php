<?php

namespace App\Console\Commands;

use App\Support\Jalali;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;

/**
 * Takes a compressed dump of the database, keeps a rolling set on disk and
 * mails a copy off the machine.
 *
 * A backup that only ever lives on the same server is not a backup — it is
 * the same disk, and the same mistake reaches both. The mailed copy is the
 * one that survives; the local set is there so a restore does not have to
 * wait on an inbox.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--keep=14 : How many daily backups to keep on disk}
        {--no-mail : Write the file but do not send it}';

    protected $description = 'پشتیبان‌گیری از دیتابیس و ارسال به ایمیل';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $dir = storage_path('app/backups');

        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            $this->error("پوشه‌ی پشتیبان ساخته نشد: {$dir}");

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d_His');
        $path = "{$dir}/{$config['database']}_{$stamp}.sql.gz";

        if (! $this->dump($config, $path)) {
            return self::FAILURE;
        }

        $size = filesize($path);
        $this->info(sprintf('پشتیبان ساخته شد: %s (%s)', basename($path), $this->human($size)));

        $this->prune($dir, (int) $this->option('keep'));

        if (! $this->option('no-mail')) {
            $this->mail($path, $size);
        }

        return self::SUCCESS;
    }

    /**
     * The password goes through the environment rather than the command
     * line, where it would be visible to anyone running `ps`.
     */
    private function dump(array $config, string $path): bool
    {
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --single-transaction --quick '
            .'--routines --events --default-character-set=utf8mb4 %s | gzip -9 > %s',
            escapeshellarg((string) $config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg((string) $config['username']),
            escapeshellarg((string) $config['database']),
            escapeshellarg($path),
        );

        $process = Process::fromShellCommandline($command, null, [
            'MYSQL_PWD' => (string) $config['password'],
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($path) || filesize($path) === 0) {
            $this->error('mysqldump شکست خورد: '.trim($process->getErrorOutput()));
            @unlink($path);

            return false;
        }

        return true;
    }

    /** Keeps the newest [keep] files and deletes the rest. */
    private function prune(string $dir, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $files = glob("{$dir}/*.sql.gz") ?: [];

        // Newest first, so everything past the keep count is the old end.
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('حذف نسخه‌ی قدیمی: '.basename($old));
        }
    }

    private function mail(string $path, int $size): void
    {
        $recipients = (array) config('backup.mail_to');

        if ($recipients === []) {
            $this->warn('BACKUP_MAIL_TO تنظیم نشده — فایل فقط روی دیسک ماند.');

            return;
        }

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER روی log است؛ نامه ارسال نمی‌شود. تنظیمات SMTP را وارد کنید.');

            return;
        }

        $shop = config('app.name');
        $date = Jalali::date(now());

        // Sent one at a time, so one bad or full inbox cannot stop the copy
        // reaching the others — and so each address sees only its own.
        foreach ($recipients as $to) {
            try {
                Mail::raw(
                    "پشتیبان دیتابیس {$shop}\nتاریخ: {$date}\nحجم: {$this->human($size)}",
                    function ($message) use ($to, $path, $date) {
                        $message->to($to)
                            ->subject("پشتیبان دیتابیس — {$date}")
                            ->attach($path);
                    }
                );

                $this->info("ارسال شد به {$to}");
            } catch (\Throwable $e) {
                // A failed send must not lose the backup that was just written.
                $this->error("ارسال به {$to} ناموفق بود: ".$e->getMessage());
            }
        }

        $this->line('فایل روی دیسک: '.$path);
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f مگابایت', $bytes / 1048576)
            : sprintf('%.0f کیلوبایت', $bytes / 1024);
    }
}
