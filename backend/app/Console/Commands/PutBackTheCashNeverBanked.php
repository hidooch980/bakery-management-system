<?php

namespace App\Console\Commands;

use App\Models\Bakery;
use App\Support\CashNeverBanked;
use App\Support\CurrentBakery;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Writes back the cash the shop took and never recorded anywhere.
 *
 * Three holes were closed going forward: a handover settled from the phone
 * banked nothing at all, the panel asked for the cash figure and discarded
 * it, and a flour sale with no account named moved no money. The books
 * from before that are still short by all of it.
 *
 * A command rather than a migration, for the reason `late:sync-deductions`
 * already gives: rewriting a closed month's money as a side effect of a
 * deploy means the owner finds his drawer changed one morning with nothing
 * having asked him. `--dry-run` shows the figures first, because what is
 * being approved should be a number and not a sentence.
 *
 * It is safe to run twice. Every posting is tied to the row it came from
 * and rebuilt from it, so a second run finds nothing left to do.
 */
class PutBackTheCashNeverBanked extends Command
{
    protected $signature = 'cash:put-back
                            {--dry-run : نشان بده چه چیزی ثبت می‌شود، بدون نوشتن}';

    protected $description = 'پول نقدی که در گذشته ثبت نشده را به صندوق برمی‌گرداند';

    public function handle(): int
    {
        $bakeries = Bakery::query()->orderBy('id')->get();

        if ($bakeries->isEmpty()) {
            $this->info('نانوایی‌ای ثبت نشده.');

            return self::SUCCESS;
        }

        $anything = false;

        foreach ($bakeries as $bakery) {
            $anything = $this->report($bakery) || $anything;
        }

        if (! $anything) {
            $this->info('چیزی برای اصلاح نیست — همه‌چیز از قبل ثبت شده.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('اجرای آزمایشی — چیزی نوشته نشد.');
            $this->line('برای اعمال، همین دستور را بدون --dry-run اجرا کنید.');

            return self::SUCCESS;
        }

        if (! $this->confirm('طبق جدول بالا در دفترها ثبت شود؟', false)) {
            $this->line('کاری انجام نشد.');

            return self::SUCCESS;
        }

        foreach ($bakeries as $bakery) {
            $done = CashNeverBanked::repairFor($bakery);

            $this->info(
                $bakery->name.': '
                .$done['flour_sales'].' فروش آرد و '
                .$done['settlements'].' تسویه ثبت شد.'
            );
        }

        return self::SUCCESS;
    }

    /** Whether this shop has anything to put back. Prints either way. */
    private function report(Bakery $bakery): bool
    {
        $audit = CashNeverBanked::auditFor($bakery);

        $money = fn (float $toman) => CurrentBakery::for(
            $bakery->id,
            fn () => Money::format($toman),
        );

        $this->newLine();
        $this->line("<options=bold>{$bakery->name}</>");

        if (! $audit['has_till']) {
            // Naming one for them would be this system deciding the shop
            // has an account it has never been told about.
            $this->warn('صندوق نقدی تعریف نشده — تا وقتی حسابی با نشان «صندوق»'
                .' ساخته نشود، جایی برای ثبت این پول نیست.');

            return false;
        }

        $this->table(['چه چیزی', 'تعداد', 'مبلغ'], [
            [
                'فروش آرد نقدی بدون حساب',
                $audit['flour_sales'],
                $money($audit['flour_toman']),
            ],
            [
                'تسویهٔ نقدی فروشنده',
                $audit['settlements'],
                $money($audit['settlement_toman']),
            ],
        ]);

        // Named, never guessed at. A handover settled straight from the
        // panel or the phone stamped the sales and recorded nothing about
        // how the money arrived, so the figure exists nowhere to be put
        // back — and a drawer holding money nobody can point at a record
        // for is a worse answer than one that is short.
        if ($audit['unrecorded_toman'] > 0) {
            $this->newLine();
            $this->warn('«'.$money($audit['unrecorded_toman']).'» تسویه شده ولی'
                .' هیچ ردیفی نمی‌گوید چقدرش نقد بوده و چقدر کارتخوان.');
            $this->line('این مبلغ ثبت نمی‌شود — عددی که هیچ سندی ندارد،'
                .' حدس است نه اصلاح.');
        }

        return $audit['flour_sales'] > 0 || $audit['settlements'] > 0;
    }
}
