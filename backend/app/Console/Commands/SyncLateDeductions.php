<?php

namespace App\Console\Commands;

use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\LateDeduction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Writes the late deduction for a month that already happened.
 *
 * From now on the tariff keeps itself in step: a late day saved or
 * removed rewrites the month's figure. Months already recorded have no
 * deduction against them, and they are not given one on their own —
 * quietly docking somebody for a month they thought was settled is not a
 * thing to do as a side effect of a deploy.
 *
 * So it is a command, run deliberately, one month at a time, and it says
 * what it did.
 */
class SyncLateDeductions extends Command
{
    protected $signature = 'late:sync-deductions
                            {--month= : هر روزی از آن ماه، مثل 1405-06-15 (پیش‌فرض: ماه جاری)}
                            {--dry-run : فقط بگو چه می‌شود}';

    protected $description = 'کسر تأخیر یک ماه را طبق تعرفه ثبت یا اصلاح می‌کند';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::parse($this->option('month'))
            : Carbon::now();

        [$from] = Jalali::monthRangeFor($month->copy());
        $label = AppCalendar::monthLabel($from);

        if ($this->option('dry-run')) {
            // The count, not the amounts: working those out means running
            // the same arithmetic twice, and two versions of one number
            // is how they come to disagree.
            $this->info("اجرای آزمایشی — چیزی نوشته نشد. ماه: {$label}");
            $this->line('برای اعمال، بدون --dry-run اجرا کنید.');

            return self::SUCCESS;
        }

        $people = LateDeduction::syncMonth($month);

        $this->info("ماه {$label}: کسر تأخیر برای {$people} نفر بررسی و ثبت شد.");
        $this->line('ماه‌هایی که فیش حقوقی‌شان صادر شده دست نخوردند.');

        return self::SUCCESS;
    }
}
