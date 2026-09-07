<?php

namespace App\Console\Commands;

use App\Models\StaffAdjustment;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\LateDeduction;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Writes the late deduction for a month that already happened.
 *
 * From now on the tariff keeps itself in step: a late day saved or
 * removed rewrites the month's figure. Months already recorded have no
 * deduction against them and are not given one on their own — quietly
 * docking somebody for a month they thought was closed is not a thing to
 * do as a side effect of a deploy.
 *
 * So it is a command, run deliberately. And because what it does is take
 * money off wages, `--dry-run` shows the actual rows: who, how much now,
 * how much after. A rehearsal that only says «this month» rehearses
 * nothing, and the person approving it would be approving a sentence
 * rather than a figure.
 */
class SyncLateDeductions extends Command
{
    protected $signature = 'late:sync-deductions
                            {--month= : هر روزی از آن ماه، مثل 1405-05-15 یا 2026-08-06 (پیش‌فرض: ماه جاری)}
                            {--list : فقط بگو کدام ماه‌ها تأخیر دارند}
                            {--dry-run : نشان بده چه تغییری می‌کند، بدون نوشتن}';

    protected $description = 'کسر تأخیر یک ماه را طبق تعرفه ثبت یا اصلاح می‌کند';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listMonths();
        }

        $month = $this->month();
        [$from, $until] = Jalali::monthRangeFor($month->copy());
        $label = AppCalendar::monthLabel($from);

        $rows = $this->preview($from, $until);

        if ($rows === []) {
            $this->info("ماه {$label}: هیچ تأخیری ثبت نشده. چیزی برای کسر نیست.");

            return self::SUCCESS;
        }

        $this->table(['کارمند', 'کسر فعلی', 'کسر پس از اجرا', 'وضعیت'], $rows);

        if ($this->option('dry-run')) {
            $this->line('اجرای آزمایشی — چیزی نوشته نشد.');
            $this->line('برای اعمال، همین دستور را بدون --dry-run اجرا کنید.');

            return self::SUCCESS;
        }

        if (! $this->confirm("کسر تأخیر ماه {$label} طبق جدول بالا اعمال شود؟", false)) {
            $this->line('کاری انجام نشد.');

            return self::SUCCESS;
        }

        $people = LateDeduction::syncMonth($month);

        $this->info("ماه {$label}: {$people} نفر بررسی شد.");

        return self::SUCCESS;
    }

    private function month(): Carbon
    {
        $given = $this->option('month');

        if (! $given) {
            return Carbon::now();
        }

        // A Jalali date is what somebody here would type. `parseFlexible`
        // takes either, so «1405-05-15» does not silently become a year
        // 1405 Gregorian date eight centuries ago.
        return Jalali::parseFlexible($given) ?? Carbon::parse($given);
    }

    /** Every month that has a late day, so the scope is visible before choosing. */
    private function listMonths(): int
    {
        $months = WorkStart::acrossBakeries()
            ->where('is_late', true)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => Jalali::monthRangeFor($date->copy())[0])
            ->unique(fn (Carbon $start) => $start->toDateString());

        if ($months->isEmpty()) {
            $this->info('هیچ تأخیری در هیچ ماهی ثبت نشده.');

            return self::SUCCESS;
        }

        $this->table(
            ['ماه', 'برای اجرا'],
            $months->map(fn (Carbon $start) => [
                AppCalendar::monthLabel($start),
                '--month='.$start->toDateString(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * What each person's row would become.
     *
     * Read-only: it sums the same records `LateDeduction` does rather
     * than calling it, because calling it would write.
     *
     * @return array<int, array<int, string>>
     */
    private function preview(Carbon $from, Carbon $until): array
    {
        $totals = WorkStart::acrossBakeries()
            ->where('is_late', true)
            ->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->whereNotNull('user_id')
            ->get(['user_id', 'penalty_amount'])
            ->groupBy('user_id')
            ->map(fn ($group) => (float) $group->sum('penalty_amount'));

        $rows = [];

        foreach ($totals as $userId => $after) {
            $existing = StaffAdjustment::acrossBakeries()
                ->where('user_id', $userId)
                ->where('source', LateDeduction::SOURCE)
                ->whereBetween('occurred_on', [$from->toDateString(), $until->toDateString()])
                ->first();

            $settled = $existing?->salary_payment_id !== null;

            $rows[] = [
                User::withoutGlobalScopes()->find($userId)?->name ?? "کارمند #{$userId}",
                $existing ? Money::format((float) $existing->amount) : '—',
                $settled
                    ? Money::format((float) $existing->amount)
                    : Money::format($after),
                $settled
                    ? 'فیش صادر شده — دست نمی‌خورد'
                    : ($existing ? 'اصلاح می‌شود' : 'تازه'),
            ];
        }

        return $rows;
    }
}
