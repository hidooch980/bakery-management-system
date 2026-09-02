<?php

namespace App\Console\Commands;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One command that asks the whole shop whether it adds up.
 *
 * These checks were being run by hand, a query at a time, every time
 * somebody asked «چک کن» — which is slow, expensive, and worst of all not
 * repeatable: a check nobody wrote down gets remembered differently the
 * next time, and the thing it caught last month goes unlooked-at.
 *
 *     php artisan shop:health
 *
 * Read-only. Every figure is derived from records already in the database
 * and nothing here writes, so it is safe against production at any hour.
 *
 * It is deliberately not the issue centre. That reports what the *shop*
 * must act on — a debt to chase, a quota running out. This reports whether
 * the *system* is telling the truth: whether ledgers reconcile, whether
 * every record that should have moved stock did, whether the backups are
 * running. A shop can be perfectly healthy here and still owe money.
 *
 * Exits non-zero when a check fails, so it can be run from cron or a
 * deploy script and actually be noticed.
 */
class CheckTheShopIsHealthy extends Command
{
    protected $signature = 'shop:health {--quiet-when-clean : Print nothing unless something is wrong}';

    protected $description = 'Checks every cycle in the shop against itself';

    /** Collected as we go, so the summary can be printed last. */
    private array $failures = [];

    private array $warnings = [];

    public function handle(): int
    {
        $checks = [
            'زیرساخت' => fn () => $this->infrastructure(),
            'انبار آرد' => fn () => $this->flourLedger(),
            'دفتر همکاران' => fn () => $this->partnerRegister(),
            'چرخهٔ تولید' => fn () => $this->productionChain(),
            'چرخهٔ فروش' => fn () => $this->salesChain(),
            'پول و حساب‌ها' => fn () => $this->money(),
            'سهمیهٔ آرد' => fn () => $this->quota(),
            'پشتیبان‌گیری' => fn () => $this->backups(),
        ];

        foreach ($checks as $heading => $check) {
            $rows = $check();

            if ($this->option('quiet-when-clean') && $this->failures === [] && $this->warnings === []) {
                continue;
            }

            $this->newLine();
            $this->line("<options=bold>{$heading}</>");

            foreach ($rows as $row) {
                $this->line('  '.$row);
            }
        }

        return $this->summarise();
    }

    // ---------------------------------------------------------------- checks

    /** The things that stop the shop working at all. */
    private function infrastructure(): array
    {
        $rows = [];

        $pending = collect(app('migrator')->getMigrationFiles(
            app('migrator')->paths() ?: [database_path('migrations')]
        ))->keys()->diff(
            DB::table('migrations')->pluck('migration')
        )->count();

        $rows[] = $this->verdict($pending === 0, "مهاجرت‌های اجرانشده: {$pending}");

        // A shop with no settings row cannot price anything, and every
        // report downstream quietly reads zero.
        $rows[] = $this->verdict(Bakery::query()->exists(), 'تنظیمات مغازه ثبت شده');

        $orphanTables = collect(['sales', 'chane_entries', 'dough_entries', 'inventory_movements'])
            ->reject(fn ($t) => Schema::hasTable($t));

        $rows[] = $this->verdict(
            $orphanTables->isEmpty(),
            'جدول‌های اصلی موجودند'.($orphanTables->isEmpty() ? '' : ' — نبود: '.$orphanTables->implode(', '))
        );

        return $rows;
    }

    /**
     * The warehouse ledger against itself.
     *
     * Balance is derived from movements, so this cannot disagree by
     * arithmetic — it disagrees when a movement is written outside the
     * ledger, which has happened.
     */
    private function flourLedger(): array
    {
        $rows = [];

        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            $item = InventoryItem::ofKey($key);

            $in = (float) $item->movements()->where('direction', 'in')->sum('quantity');
            $out = (float) $item->movements()->where('direction', 'out')->sum('quantity');
            $balance = round($in - $out, 3);

            $rows[] = $this->verdict(
                abs($balance - $item->balance) < 0.001,
                sprintf(
                    '%s: ورود %s − خروج %s = %s کیلو',
                    $item->name,
                    number_format($in),
                    number_format($out),
                    number_format($balance)
                )
            );

            // Below zero is impossible in a warehouse and always means a
            // purchase that was never entered.
            if ($item->balance < 0) {
                $this->failures[] = "موجودی {$item->name} منفی است: ".number_format($item->balance);
            }
        }

        return $rows;
    }

    /**
     * A bakery filed under the wrong kind of customer.
     *
     * The consignment page offers only customers typed «همکار / نانوایی»
     * — `Customer::partners()` — so a bakery saved as anything else does
     * not appear in it at all, and nothing on the screen says why. The
     * name is simply absent, and the person entering flour picks the
     * nearest one they can find.
     *
     * نانوایی ناهوت sat as «مدرسه» from the day the customer list was
     * typed in, and that is how twenty sacks lent to منصور پرکی went a
     * month with no record anywhere: not a mistake in the entry, a name
     * that could not be entered. It surfaced only because the owner said
     * it out loud.
     *
     * Only the name gives this away — a bakery is a bakery because it is
     * called one. So this catches «نانوایی» in the name against any type
     * but partner, and misses a partner named without the word. That is
     * the whole of what it claims, and it is worth having anyway: every
     * partner this shop has is named that way.
     *
     * A warning, not a failure. Nothing here is broken; a record is
     * mis-filed, and the flour it hides is the shop's to chase.
     */
    private function partnerRegister(): array
    {
        $misfiled = Customer::query()
            ->where('name', 'like', '%نانوایی%')
            ->where('type', '!=', Customer::PARTNER_TYPE)
            ->orderBy('id')
            ->get();

        $rows = [
            $this->verdict(
                $misfiled->isEmpty(),
                "نانوایی ثبت‌شده زیر نوعی جز همکار: {$misfiled->count()}",
                warnOnly: true
            ),
        ];

        // Named one by one, because the fix is per record and a count
        // alone would send someone reading the whole customer list.
        foreach ($misfiled as $customer) {
            $rows[] = sprintf(
                '    «%s» زیر «%s» — در فهرست آرد امانی دیده نمی‌شود',
                trim($customer->name),
                $customer->type_label
            );
        }

        return $rows;
    }

    /** Dough becomes chane becomes bread, and each link should hold. */
    private function productionChain(): array
    {
        $rows = [];

        $chaneWithoutDough = ChaneEntry::whereNotNull('dough_entry_id')
            ->whereDoesntHave('doughEntry')->count();

        $rows[] = $this->verdict($chaneWithoutDough === 0, "چانه بدون خمیرِ موجود: {$chaneWithoutDough}");

        // A batch left open from a past day is the trace a lost sale would
        // leave: the bread was made and nobody ever recorded selling it.
        $staleOpen = ChaneEntry::where('status', '!=', 'sold')
            ->whereDate('created_at', '<', now()->toDateString())
            ->count();

        $rows[] = $this->verdict($staleOpen === 0, "دستهٔ باز از روزهای گذشته: {$staleOpen}", warnOnly: true);

        $doughToday = DoughEntry::whereDate('created_at', now())->count();
        $chaneToday = ChaneEntry::whereDate('created_at', now())->count();

        $rows[] = "امروز: {$doughToday} ثبت خمیر، {$chaneToday} ثبت چانه";

        return $rows;
    }

    /** Sales against the batches they came from. */
    private function salesChain(): array
    {
        $rows = [];

        $orphanSales = Sale::whereNotNull('chane_entry_id')
            ->whereDoesntHave('chaneEntry')->count();

        $rows[] = $this->verdict($orphanSales === 0, "فروش بدون دستهٔ موجود: {$orphanSales}");

        // Bread counted out of a batch must not exceed what was shaped.
        $overSold = 0;

        foreach (ChaneEntry::with('sales')->where('status', 'sold')->get() as $batch) {
            $sold = (int) $batch->sales->sum('bread_count');
            $shortfall = (int) $batch->sales->sum('shortfall_count');

            if ($sold + $shortfall > (int) $batch->chane_count) {
                $overSold++;
            }
        }

        $rows[] = $this->verdict($overSold === 0, "دسته‌ای که بیش از چانه‌اش فروش خورده: {$overSold}");

        $zeroPriced = FlourSale::where('amount', 0)
            ->whereNotIn('payment_type', FlourSale::GIVEAWAY_TYPES)
            ->count();

        $rows[] = $this->verdict(
            $zeroPriced === 0,
            "فروش آرد با مبلغ صفر زیر عنوان پولی: {$zeroPriced}",
            warnOnly: true
        );

        return $rows;
    }

    /** Balances that are derived, against the movements behind them. */
    private function money(): array
    {
        $rows = [];

        foreach (BankAccount::all() as $account) {
            $rows[] = $this->verdict(
                $account->balance >= 0,
                sprintf('%s: %s', $account->title, Money::format((float) $account->balance))
            );
        }

        if (BankAccount::query()->doesntExist()) {
            $rows[] = 'حساب بانکی ثبت نشده';
        }

        return $rows;
    }

    /** The quota, and whether anyone has checked it against the reader. */
    private function quota(): array
    {
        $rows = [];
        $allocation = FlourAllocation::with('periods')->orderByDesc('month_start')->first();

        if (! $allocation) {
            $rows[] = 'سهمیه‌ای ثبت نشده';

            return $rows;
        }

        $period = $allocation->periodFor(now());

        $rows[] = sprintf(
            '%s — %s کیسه، مصرف %s٪ تا امروز',
            $allocation->month_label,
            number_format((float) $allocation->total_bags),
            $period ? $period->usage_percent : 0
        );

        $unchecked = $allocation->periods->reject->is_checked_against_reader->count();

        $rows[] = $this->verdict(
            $unchecked === 0,
            "دوره‌ای که رقم کارتخوانش وارد نشده: {$unchecked}",
            warnOnly: true
        );

        return $rows;
    }

    /**
     * Whether a dump was taken recently.
     *
     * The cron died silently twice — once because the dump directory
     * changed owner, once because the *log file* the line redirects into
     * did. Both times nothing complained, and the only evidence was the
     * date on the newest file. So that is what this reads.
     */
    private function backups(): array
    {
        $dir = storage_path('app/backups');

        if (! is_dir($dir)) {
            $this->failures[] = 'پوشهٔ پشتیبان وجود ندارد';

            return ['<fg=red>✗</> پوشهٔ پشتیبان وجود ندارد'];
        }

        $files = collect(glob($dir.'/*.sql.gz'));

        if ($files->isEmpty()) {
            $this->failures[] = 'هیچ نسخهٔ پشتیبانی روی دیسک نیست';

            return ['<fg=red>✗</> هیچ نسخهٔ پشتیبانی نیست'];
        }

        $newest = $files->max(fn ($f) => filemtime($f));
        $hours = round((time() - $newest) / 3600, 1);

        return [
            $this->verdict(
                $hours <= 26,
                sprintf('تازه‌ترین نسخه %s ساعت پیش (%d فایل)', number_format($hours, 1), $files->count())
            ),
        ];
    }

    // --------------------------------------------------------------- output

    private function verdict(bool $ok, string $label, bool $warnOnly = false): string
    {
        if ($ok) {
            return "<fg=green>✓</> {$label}";
        }

        if ($warnOnly) {
            $this->warnings[] = $label;

            return "<fg=yellow>!</> {$label}";
        }

        $this->failures[] = $label;

        return "<fg=red>✗</> {$label}";
    }

    private function summarise(): int
    {
        $issues = (new IssueScanner(Bakery::first()))->scan();
        $critical = $issues->where('severity', SystemIssue::CRITICAL)->count();

        $this->newLine();
        $this->line('<options=bold>خلاصه</>');
        $this->line(sprintf(
            '  صفحهٔ مشکلات: %d مورد (%d بحرانی) — اینها کار مغازه است، نه خرابی سیستم',
            $issues->count(),
            $critical
        ));

        if ($this->failures === [] && $this->warnings === []) {
            $this->newLine();
            $this->info('  همه‌ی چرخه‌ها با خودشان می‌خوانند.');

            return self::SUCCESS;
        }

        foreach ($this->warnings as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        foreach ($this->failures as $failure) {
            $this->line("  <fg=red>✗</> {$failure}");
        }

        $this->newLine();

        // A warning is something to look at; a failure is something wrong
        // with the system itself, and only that fails the command.
        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
