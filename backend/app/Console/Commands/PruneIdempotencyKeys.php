<?php

namespace App\Console\Commands;

use App\Http\Middleware\IdempotentWrites;
use App\Models\IdempotentRequest;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'حذف شناسه‌های تکرارنشدنی که دیگر بازپخش نمی‌شوند';

    /**
     * A key only has to outlive the queue that might replay it. Keeping
     * them for ever would grow a table nobody reads, with a copy of every
     * write's response in it.
     */
    public function handle(): int
    {
        $cutoff = now()->subHours(IdempotentWrites::REMEMBER_FOR_HOURS);

        $removed = IdempotentRequest::where('created_at', '<', $cutoff)->delete();

        $this->info("{$removed} شناسه حذف شد.");

        return self::SUCCESS;
    }
}
