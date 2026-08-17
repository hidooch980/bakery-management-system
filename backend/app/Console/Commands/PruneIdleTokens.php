<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes the sessions nobody is using.
 *
 * A token here used to last forever. On 2026-08-17 this shop's server held
 * five, two of them issued in July, used once, and never touched again —
 * and both would still have opened the whole system on the day somebody
 * found the phone they were sitting on. Nothing expires them, nobody logs
 * out, and a token that is never used is exactly the one whose loss nobody
 * would notice.
 *
 * Absolute expiry is set in config/sanctum.php as well, but six months is
 * a long time to leave an abandoned key under the mat. This is the shorter
 * rule: unused for a month, closed.
 *
 * Someone who opens the app in an ordinary week never meets either.
 */
class PruneIdleTokens extends Command
{
    protected $signature = 'tokens:prune-idle {--days=30 : Close tokens unused for this many days}
                                              {--dry-run : List them without closing}';

    protected $description = 'Closes access tokens nobody has used for a month';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // A token created and never used is judged on when it was made,
        // otherwise a key issued and immediately dropped never ages.
        $idle = DB::table('personal_access_tokens')
            ->whereRaw('COALESCE(last_used_at, created_at) < ?', [$cutoff])
            ->get(['id', 'tokenable_id', 'name', 'last_used_at', 'created_at']);

        if ($idle->isEmpty()) {
            $this->info("هیچ توکنی بیش از {$days} روز بی‌استفاده نمانده است.");

            return self::SUCCESS;
        }

        $this->table(
            ['شناسه', 'کاربر', 'نام', 'آخرین استفاده'],
            $idle->map(fn ($t) => [
                $t->id,
                $t->tokenable_id,
                $t->name,
                substr($t->last_used_at ?? $t->created_at, 0, 10),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->line('اجرای آزمایشی — چیزی بسته نشد.');

            return self::SUCCESS;
        }

        DB::table('personal_access_tokens')->whereIn('id', $idle->pluck('id'))->delete();

        $this->info($idle->count().' توکن بی‌استفاده بسته شد. صاحبانشان دوباره وارد می‌شوند.');

        return self::SUCCESS;
    }
}
