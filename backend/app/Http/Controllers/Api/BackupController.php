<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppCalendar;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Whether the shop's data is being kept, told to the person responsible
 * for it.
 *
 * The dumps have run twice a day for weeks and nobody could see that
 * without an ssh session. A backup nobody checks is a backup nobody finds
 * out has stopped — and this one has a way of stopping quietly: the
 * command writes the file, then tries to mail it, and if the panel's
 * outgoing mail is off it logs «فایل فقط روی دیسک ماند» and exits
 * successfully. Twice-daily green, and no copy off the machine.
 *
 * The files themselves are deliberately not downloadable here. The whole
 * shop — wages, debts, every customer — in one file over the API is not a
 * risk worth a convenience nobody asked for; a .sql.gz on a phone is of
 * no use to anybody anyway. This endpoint says *whether*, not *what*.
 */
class BackupController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $dir = storage_path('app/backups');
        $files = collect(glob("{$dir}/*.sql.gz") ?: [])
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => filesize($path) ?: 0,
                'at' => filemtime($path) ?: 0,
            ])
            ->sortByDesc('at')
            ->values();

        $latest = $files->first();
        $takenAt = $latest ? now()->setTimestamp($latest['at']) : null;

        // Whether it is stale is a judgement the phone should not have to
        // make: the schedule is twice a day, so anything older than a day
        // and a half has missed at least two runs.
        $hoursSince = $takenAt ? $takenAt->diffInHours(now()) : null;

        return $this->success([
            'count' => $files->count(),
            'total_bytes' => (int) $files->sum('size'),
            'latest_at' => $takenAt?->toDateTimeString(),
            'latest_at_display' => $takenAt ? AppCalendar::dateTime($takenAt) : null,
            'latest_bytes' => (int) ($latest['size'] ?? 0),
            'hours_since' => $hoursSince === null ? null : (int) $hoursSince,
            'is_stale' => $hoursSince === null || $hoursSince > 36,
            'recent' => $files->take(5)->map(fn (array $f) => [
                'name' => $f['name'],
                'size' => $f['size'],
                'at_display' => AppCalendar::dateTime(now()->setTimestamp($f['at'])),
            ])->all(),
        ]);
    }

    /**
     * Takes one now.
     *
     * Runs the same command cron runs rather than a second copy of the
     * logic — a backup path that only the button exercises is a path
     * nobody has tested at three in the morning.
     */
    public function store(): JsonResponse
    {
        $exit = Artisan::call('backup:database', ['--keep' => 60]);
        $output = trim(Artisan::output());

        if ($exit !== 0) {
            return $this->error($output ?: 'پشتیبان‌گیری انجام نشد.', 500);
        }

        return $this->success(
            ['output' => $output],
            'پشتیبان گرفته شد.',
        );
    }
}
