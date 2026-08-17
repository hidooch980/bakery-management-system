<?php

namespace App\Console\Commands;

use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\DoughEntry;
use App\Models\FlourSale;
use App\Models\InventoryMovement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Every record that spent stock, against the stock it actually moved.
 *
 * The warehouse balance is derived from movements, and a record written
 * without its movement leaves the store holding flour that is not there.
 * Nothing complains at the time: the bake is on file, the sacks are on the
 * shelf in the ledger, and the two only disagree when someone counts.
 *
 * It has happened twice. A consignment recorded two days before that model
 * grew its stock hook, and a data migration of mine that called
 * `DoughEntry::create` directly instead of going through
 * ProductionRecorder — which is the class that moves stock. Between them,
 * 2,405 kg of flour, sixty sacks, that the store thought it had.
 *
 * So this is a command rather than a script that got thrown away:
 *
 *     php artisan stock:audit
 *
 * Worth running after any data migration that writes production, sales or
 * consignments, and after any upgrade that adds a model hook.
 */
class AuditStockMovements extends Command
{
    protected $signature = 'stock:audit {--json : Machine-readable output}';

    protected $description = 'Finds records that should have moved stock and did not';

    /** Every kind of record whose creation is supposed to move the warehouse. */
    private const SOURCES = [
        ConsignmentFlour::class => 'آرد امانی',
        FlourSale::class => 'فروش آرد',
        DoughEntry::class => 'ثبت خمیر',
        ChaneEntry::class => 'ثبت چانه',
    ];

    public function handle(): int
    {
        $gaps = [];
        $rows = [];

        foreach (self::SOURCES as $class => $label) {
            $records = $class::query()->withoutGlobalScopes()->orderBy('id')->get();
            $missing = $records->reject(fn (Model $r) => $this->moved($r));

            $rows[] = [$label, $records->count(), $missing->count()];

            foreach ($missing as $record) {
                $gaps[] = [$label, $record->id, $this->when($record)];
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($gaps, JSON_UNESCAPED_UNICODE));

            return $gaps === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['نوع', 'تعداد', 'بدون حرکت انبار'], $rows);

        if ($gaps === []) {
            $this->info('هر رکوردی که باید انبار را جابه‌جا می‌کرد، کرده است.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($gaps).' رکورد انبار را جابه‌جا نکرده‌اند:');
        $this->table(['نوع', 'شناسه', 'تاریخ'], $gaps);

        // Deliberately not offered as an auto-fix. What the missing
        // movement should be depends on the shop's formula on the day, and
        // writing it needs a migration that says which day it belongs to —
        // a movement stamped today lands in the wrong quota period.
        $this->line('اصلاحشان باید با تاریخ روز خودش نوشته شود، وگرنه در دورهٔ سهمیهٔ اشتباه می‌نشیند.');

        return self::FAILURE;
    }

    /**
     * A chane entry only moves stock when it dusted the bench with flour,
     * so one with no spray flour is not a gap.
     */
    private function moved(Model $record): bool
    {
        if ($record instanceof ChaneEntry && (float) $record->spray_flour_kg <= 0) {
            return true;
        }

        return InventoryMovement::where('source_type', $record::class)
            ->where('source_id', $record->id)
            ->exists();
    }

    private function when(Model $record): string
    {
        return ($record->occurred_on ?? $record->sold_on ?? $record->created_at)?->toDateString() ?? '—';
    }
}
