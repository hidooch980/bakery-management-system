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
 *
 * It checks both directions, because for a long time it only checked one.
 * A record with no movement leaves flour on the shelf that is not there;
 * a movement with no record leaves flour spent that nobody spent. On
 * 2026-08-17 the owner asked for a consumption list, eleven movements
 * turned out to point at records that had been deleted, and reading only
 * the link column I twice announced flour was missing when every one of
 * them had been given back. The reversal is in the ledger; the link to it
 * is not always, because `reverses_movement_id` was only added on
 * 2026-08-16 and everything cancelled before that has none.
 *
 * So the second pass settles it the only way that is safe: by the
 * quantity actually put back, not by the link.
 */
class AuditStockMovements extends Command
{
    protected $signature = 'stock:audit {--json : Machine-readable output}';

    protected $description = 'Finds records that should have moved stock and did not';

    /** What StockReversal writes when a record is deleted. */
    private const REVERSAL_REASONS = [
        'production_reversal',
        'flour_sale_reversal',
        'consignment_return',
    ];

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

        $orphans = $this->orphans();
        $misLinked = $this->misLinkedReversals();

        if ($gaps === [] && $orphans === [] && $misLinked === []) {
            $this->info('هر رکوردی که باید انبار را جابه‌جا می‌کرد، کرده است.');
            $this->info('هر حرکتی هم صاحبی دارد یا برگردانده شده است.');
            $this->info('و هر ابطالی به همان حرکتی وصل است که باطلش کرده.');

            return self::SUCCESS;
        }

        if ($gaps === []) {
            $this->reportOrphans($orphans);
            $this->reportMisLinked($misLinked);

            return self::FAILURE;
        }

        $this->newLine();
        $this->error(count($gaps).' رکورد انبار را جابه‌جا نکرده‌اند:');
        $this->table(['نوع', 'شناسه', 'تاریخ'], $gaps);
        $this->reportOrphans($this->orphans());
        $this->reportMisLinked($this->misLinkedReversals());

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

    /**
     * Movements whose record is gone and whose quantity was never put back.
     *
     * Deleting a record is supposed to reverse its movements — StockReversal
     * does it off the ledger rather than the formula — so an orphan on its
     * own means nothing. What matters is whether the quantity came back.
     *
     * Which is asked by quantity and item, not by `reverses_movement_id`.
     * That column arrived on 2026-08-16; every reversal written before it
     * has none, and the migration that backfilled it matched on quantity
     * alone and wired two of them to the wrong movement. A check that
     * trusted the link called four settled cancellations a 560 kg hole.
     */
    private function orphans(): array
    {
        $found = [];

        $movements = InventoryMovement::query()
            ->withoutGlobalScopes()
            ->whereNotNull('source_type')
            ->orderBy('id')
            ->get();

        // What each deleted record put back, so two identical movements from
        // one record are not both settled by a single reversal.
        $returned = [];

        foreach ($movements as $movement) {
            $class = $movement->source_type;

            if (class_exists($class) && $class::withoutGlobalScopes()->find($movement->source_id)) {
                continue;
            }

            $opposite = $movement->direction === 'out' ? 'in' : 'out';
            $key = $movement->inventory_item_id.'|'.$opposite;

            $returned[$key] ??= InventoryMovement::query()
                ->withoutGlobalScopes()
                ->where('inventory_item_id', $movement->inventory_item_id)
                ->where('direction', $opposite)
                ->whereIn('reason', self::REVERSAL_REASONS)
                ->pluck('quantity')
                ->map(fn ($q) => (float) $q)
                ->all();

            $at = array_search((float) $movement->quantity, $returned[$key], true);

            if ($at !== false) {
                // Spent, so the next identical movement has to find its own.
                unset($returned[$key][$at]);

                continue;
            }

            $found[] = [
                class_basename($class).'#'.$movement->source_id,
                $movement->id,
                $movement->direction,
                (float) $movement->quantity,
                $movement->item?->name ?? '—',
                $movement->created_at?->toDateString() ?? '—',
            ];
        }

        return $found;
    }

    /**
     * Reversals pointing at a movement whose record is still there.
     *
     * A reversal is written when a record is deleted, so it cannot undo a
     * movement belonging to one that still exists — nothing deleted it.
     * The backfill that filled this column in matched on item and quantity
     * and took the first candidate, and this shop bakes the same 440 kg
     * batch over and over, so two ended up wired to movements nine days
     * older from a dough entry nobody ever removed.
     *
     * It moves no stock, which is why nothing else notices. What it moves
     * is which quota period a refund is counted against.
     */
    private function misLinkedReversals(): array
    {
        $found = [];

        $reversals = InventoryMovement::query()
            ->withoutGlobalScopes()
            ->whereNotNull('reverses_movement_id')
            ->orderBy('id')
            ->get();

        foreach ($reversals as $reversal) {
            $original = InventoryMovement::withoutGlobalScopes()->find($reversal->reverses_movement_id);

            if ($original === null) {
                $found[] = [$reversal->id, $reversal->reverses_movement_id, 'حرکتی با این شناسه نیست'];

                continue;
            }

            $class = $original->source_type;

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if ($class::withoutGlobalScopes()->find($original->source_id)) {
                $found[] = [
                    $reversal->id,
                    $original->id,
                    class_basename($class).'#'.$original->source_id.' هنوز موجود است',
                ];
            }
        }

        return $found;
    }

    private function reportMisLinked(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->error(count($rows).' ابطال به حرکت اشتباه وصل است:');
        $this->table(['ابطال', 'وصل به', 'مشکل'], $rows);
        $this->line('روی موجودی اثر ندارد، ولی برگشت را به دورهٔ سهمیهٔ اشتباه می‌برد.');
    }

    private function reportOrphans(array $orphans): void
    {
        if ($orphans === []) {
            return;
        }

        $this->newLine();
        $this->error(count($orphans).' حرکت انبار صاحب ندارد و برگردانده هم نشده:');
        $this->table(
            ['منبع حذف‌شده', 'حرکت', 'جهت', 'مقدار', 'کالا', 'تاریخ'],
            $orphans,
        );
        $this->line('اینها از انبار کم یا زیاد شده‌اند بدون رکوردی که توضیحشان بدهد.');
    }

    private function when(Model $record): string
    {
        return ($record->occurred_on ?? $record->sold_on ?? $record->created_at)?->toDateString() ?? '—';
    }
}
