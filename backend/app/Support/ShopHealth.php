<?php

namespace App\Support;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every cycle in the shop, asked whether it adds up.
 *
 * These checks were written inside `shop:health` and could only be read by
 * running the command over SSH — which meant the owner never saw them. He
 * asked me «چک کن» instead, and on 1405/06/07 a batch was corrected from
 * ten sacks to twenty with the flour never leaving the ledger, and four
 * days passed with every screen he had showing green.
 *
 * So the checks live here now and the command renders them, the same way
 * `ReportSeries` serves both the API and the panel. A cycle that answers
 * differently in two places is worse than a cycle nobody looks at.
 *
 * Read-only. Nothing here writes, so it is safe to run on every page load
 * and safe against production at any hour.
 *
 * It is deliberately not the issue centre. This asks whether the *system*
 * is telling the truth; the issue centre reports what the *shop* must act
 * on. A shop can be perfectly healthy here and still owe money.
 */
class ShopHealth
{
    /** The check passed. */
    public const OK = 'ok';

    /** Worth a look; does not mean the system is wrong. */
    public const WARN = 'warn';

    /** The system contradicts itself. */
    public const FAIL = 'fail';

    /** A figure with no verdict attached — context, not a result. */
    public const NOTE = 'note';

    /** @var array<string, list<array{severity: string, label: string}>> */
    private array $cycles = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $warnings = [];

    public static function inspect(): self
    {
        $health = new self;

        $health->cycles = [
            'زیرساخت' => $health->infrastructure(),
            'انبار آرد' => $health->flourLedger(),
            'دفتر همکاران' => $health->partnerRegister(),
            'چرخهٔ تولید' => $health->productionChain(),
            'چرخهٔ فروش' => $health->salesChain(),
            'پول و حساب‌ها' => $health->money(),
            'سهمیهٔ آرد' => $health->quota(),
            'پشتیبان‌گیری' => $health->backups(),
        ];

        return $health;
    }

    /** @return array<string, list<array{severity: string, label: string}>> */
    public function cycles(): array
    {
        return $this->cycles;
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Whether every cycle agreed with itself.
     *
     * A warning does not make the shop unhealthy — an unentered card
     * reader figure is a job, not a contradiction — so only failures
     * count here, and the sentence the panel leads with says «سالم» with
     * warnings still standing beneath it.
     */
    public function isSound(): bool
    {
        return $this->failures === [];
    }

    public function isSpotless(): bool
    {
        return $this->failures === [] && $this->warnings === [];
    }

    // ---------------------------------------------------------- cycles

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
     * The consignment page offers only customers typed «همکار / نانوایی»,
     * so a bakery saved as anything else does not appear in it at all,
     * and nothing on the screen says why. نانوایی ناهوت sat as «مدرسه»
     * from the day the customer list was typed in, and twenty sacks lent
     * to منصور پرکی went a month with no record of any kind.
     *
     * Only the name gives this away — a bakery is a bakery because it is
     * called one — so it misses a partner named without the word. Every
     * partner this shop has is named that way.
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
            $rows[] = [
                'severity' => self::NOTE,
                'label' => sprintf(
                    '    «%s» زیر «%s» — در فهرست آرد امانی دیده نمی‌شود',
                    trim($customer->name),
                    $customer->type_label
                ),
            ];
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

        $rows[] = ['severity' => self::NOTE, 'label' => "امروز: {$doughToday} ثبت خمیر، {$chaneToday} ثبت چانه"];

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
            $rows[] = ['severity' => self::NOTE, 'label' => 'حساب بانکی ثبت نشده'];
        }

        return $rows;
    }

    /** The quota, and whether anyone has checked it against the reader. */
    private function quota(): array
    {
        $rows = [];
        $allocation = FlourAllocation::with('periods')->orderByDesc('month_start')->first();

        if (! $allocation) {
            return [['severity' => self::NOTE, 'label' => 'سهمیه‌ای ثبت نشده']];
        }

        $period = $allocation->periodFor(now());

        $rows[] = [
            'severity' => self::NOTE,
            'label' => sprintf(
                '%s — %s کیسه، مصرف %s٪ تا امروز',
                $allocation->month_label,
                number_format((float) $allocation->total_bags),
                $period ? $period->usage_percent : 0
            ),
        ];

        $unchecked = $allocation->periods->reject->is_checked_against_reader->count();

        // This month's periods only, which is what the label now says. It
        // read «دوره‌ای که…» while counting one allocation, and the shop
        // had six unentered periods while the line said three.
        $rows[] = $this->verdict(
            $unchecked === 0,
            "دورهٔ این ماه که رقم کارتخوانش وارد نشده: {$unchecked}",
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

            return [['severity' => self::FAIL, 'label' => 'پوشهٔ پشتیبان وجود ندارد']];
        }

        $files = collect(glob($dir.'/*.sql.gz'));

        if ($files->isEmpty()) {
            $this->failures[] = 'هیچ نسخهٔ پشتیبانی روی دیسک نیست';

            return [['severity' => self::FAIL, 'label' => 'هیچ نسخهٔ پشتیبانی نیست']];
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

    /** @return array{severity: string, label: string} */
    private function verdict(bool $ok, string $label, bool $warnOnly = false): array
    {
        if ($ok) {
            return ['severity' => self::OK, 'label' => $label];
        }

        if ($warnOnly) {
            $this->warnings[] = $label;

            return ['severity' => self::WARN, 'label' => $label];
        }

        $this->failures[] = $label;

        return ['severity' => self::FAIL, 'label' => $label];
    }
}
