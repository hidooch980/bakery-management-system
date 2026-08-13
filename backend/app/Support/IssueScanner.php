<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DieselAllocation;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Looks over the shop's own records and reports what does not add up.
 *
 * Every check here is arithmetic on data the system already holds — a
 * balance that went below zero, bread that does not account for the flour
 * it consumed, money a seller has not handed over. Nothing is guessed at.
 *
 * A fix is only offered automatically when applying it adds a new,
 * explained record. Anything that would edit history, settle a debt, or
 * paper over a missing document is left for a person to decide, because
 * the error is usually a missing entry rather than a wrong number.
 */
class IssueScanner
{
    /** @return Collection<int, SystemIssue> */
    public function scan(): Collection
    {
        $issues = collect([
            ...$this->negativeStock(),
            ...$this->missingSettings(),
            ...$this->lowStock(),
            ...$this->quotaOverrun(),
            ...$this->negativeBankBalance(),
            ...$this->sellerAccounts(),
            ...$this->unsettledShortfalls(),
            ...$this->stalePending(),
            ...$this->longUnsettledSellers(),
            ...$this->tradingAtALoss(),
            ...$this->dieselRunningOut(),
            ...$this->wagesNeverRecorded(),
            ...$this->expensesMostlyUncategorised(),
        ]);

        // Worst first, so the page opens on what actually needs attention.
        $order = [
            SystemIssue::CRITICAL => 0,
            SystemIssue::WARNING => 1,
            SystemIssue::INFO => 2,
        ];

        return $issues->sortBy(fn (SystemIssue $i) => $order[$i->severity])->values();
    }

    /**
     * A stock balance below zero is impossible in the real world, so the
     * ledger is missing an inflow — almost always a purchase that was
     * never entered.
     */
    private function negativeStock(): array
    {
        $issues = [];

        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            $item = InventoryItem::ofKey($key);

            if ($item->balance >= 0) {
                continue;
            }

            $missing = abs($item->balance);

            $issues[] = new SystemIssue(
                key: "negative-stock-{$key}",
                severity: SystemIssue::CRITICAL,
                title: "موجودی {$item->name} منفی است",
                detail: 'دفتر انبار '.number_format($item->balance, 3).' '.$item->unit
                    .' نشان می‌دهد، که در واقعیت ممکن نیست.',
                cause: 'خروج ثبت شده اما ورودی متناظر آن ثبت نشده است —'
                    .' معمولاً فاکتور خریدی که وارد سیستم نشده.',
                suggestion: 'اگر خریدی انجام شده و ثبت نشده، آن را با تاریخ خودش ثبت کنید.'
                    .' اصلاح خودکار فقط کسری را صفر می‌کند و علت اصلی را برطرف نمی‌کند.',
                url: '/admin/inventory-items',
                urlLabel: 'ثبت ورودی انبار',
                autoFix: function () use ($item, $missing) {
                    $item->move(
                        'in',
                        $missing,
                        'manual',
                        auth()->id(),
                        null,
                        'اصلاح خودکار کسری موجودی — ثبت‌نشده باقی ماندن یک ورودی.'
                            .' علت اصلی باید جداگانه بررسی شود.',
                    );

                    return "{$item->name}: ".number_format($missing, 3).' '.$item->unit.' ثبت شد';
                },
                autoFixLabel: 'صفر کردن کسری با ثبت یک تراکنش اصلاحی',
            );
        }

        return $issues;
    }

    /**
     * Several screens quietly stop working when a formula weight or the
     * bread price is missing, so an unset value is worth reporting before
     * someone wonders why a figure reads as a dash.
     */
    private function missingSettings(): array
    {
        $bakery = CurrentBakery::get();

        if (! $bakery) {
            return [];
        }

        $missing = collect([
            'normal_chane_weight_kg' => 'وزن چانه عادی',
            'nanino_chane_weight_kg' => 'وزن چانه نانینو',
            'bread_price' => 'قیمت نان',
        ])->filter(fn ($label, $field) => ! $bakery->{$field});

        if ($missing->isEmpty()) {
            return [];
        }

        return [new SystemIssue(
            key: 'missing-settings',
            severity: SystemIssue::WARNING,
            title: 'تنظیمات نانوایی ناقص است',
            detail: 'ثبت نشده: '.$missing->values()->implode('، ').'.',
            cause: 'این مقادیر هنگام راه‌اندازی وارد نشده‌اند.',
            suggestion: 'بدون این‌ها محاسبه چانه، مقایسه نانینو و اختلاف مالی'
                .' قابل انجام نیست و به‌جای عدد، خط تیره نمایش داده می‌شود.',
            url: '/admin/manage-bakery',
            urlLabel: 'تکمیل اطلاعات نانوایی',
        )];
    }

    private function lowStock(): array
    {
        $issues = [];

        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            $item = InventoryItem::ofKey($key);

            // Negative stock is already reported, and more seriously.
            if (! $item->is_low || $item->balance < 0) {
                continue;
            }

            $issues[] = new SystemIssue(
                key: "low-stock-{$key}",
                severity: SystemIssue::WARNING,
                title: "موجودی {$item->name} به حد هشدار رسیده",
                detail: number_format($item->balance, 2).' '.$item->unit
                    .' باقی مانده (حد هشدار: '.number_format((float) $item->low_threshold, 2).').',
                cause: 'مصرف از تأمین جلو زده است.',
                suggestion: 'برای جلوگیری از توقف تولید، تأمین کنید.',
                url: '/admin/inventory-items',
                urlLabel: 'مشاهده انبار',
            );
        }

        return $issues;
    }

    private function quotaOverrun(): array
    {
        $allocation = FlourAllocation::forJalaliMonthOf(now());

        if (! $allocation) {
            return [];
        }

        $issues = [];

        foreach ($allocation->periods as $period) {
            if (! $period->is_over) {
                continue;
            }

            $issues[] = new SystemIssue(
                key: "quota-over-{$period->id}",
                severity: SystemIssue::WARNING,
                title: "مصرف {$period->label} از سهمیه گذشته است",
                detail: number_format($period->used_kg, 1).' کیلوگرم مصرف در برابر '
                    .number_format((float) $period->allocated_kg, 1).' کیلوگرم سهمیه.',
                cause: 'مصرف بیش از برنامه، یا آردی که خارج از تولید از انبار رفته.',
                suggestion: 'اگر آرد امانی یا سنوات دارید ثبت کنید تا تراز درست شود.',
                url: '/admin/flour-allocations',
                urlLabel: 'مدیریت سهمیه',
            );
        }

        return $issues;
    }

    /**
     * A bank or cash account showing less than nothing. Unlike stock this
     * is not impossible — an account can be overdrawn — but in a shop it
     * nearly always means a deposit was never entered.
     *
     * No automatic fix: inventing a deposit would hide the missing one.
     */
    private function negativeBankBalance(): array
    {
        $issues = [];

        foreach (BankAccount::all() as $account) {
            if ($account->balance >= 0) {
                continue;
            }

            $issues[] = new SystemIssue(
                key: "negative-bank-{$account->id}",
                severity: SystemIssue::WARNING,
                title: "موجودی «{$account->title}» منفی است",
                detail: 'مانده این حساب '.Money::format($account->balance).' است.',
                cause: 'برداشتی ثبت شده اما واریز متناظرش وارد نشده،'
                    .' یا مبلغی اشتباه ثبت شده است.',
                suggestion: 'گردش حساب را با دفتر واقعی مقایسه کنید.'
                    .' اگر واریزی جا مانده، آن را با تاریخ خودش ثبت کنید.',
                url: '/admin/bank-accounts',
                urlLabel: 'مشاهده حساب‌ها',
            );
        }

        return $issues;
    }

    /** Money a seller is still holding, or a gap they have not answered for. */
    private function sellerAccounts(): array
    {
        $sellers = User::query()->ofCurrentBakery()
            ->whereHas('sales', fn ($q) => $q->sellerAccountOutstanding())
            ->get();

        $issues = [];

        foreach ($sellers as $seller) {
            $sales = Sale::query()
                ->where('user_id', $seller->id)
                ->sellerAccountOutstanding()
                ->get();

            $total = round($sales->sum(fn (Sale $s) => $s->seller_account_amount), 2);
            $difference = round($sales->sum(fn (Sale $s) => (float) $s->amount_difference), 2);

            $issues[] = new SystemIssue(
                key: "seller-account-{$seller->id}",
                severity: $difference != 0 ? SystemIssue::CRITICAL : SystemIssue::INFO,
                title: "حساب {$seller->name} تسویه نشده",
                detail: 'جمع '.Money::format($total).' در '.$sales->count().' فقره'
                    .($difference != 0
                        ? '، شامل اختلاف مالی '.Money::format($difference)
                        : '.'),
                cause: $difference != 0
                    ? 'مبلغ ثبت‌شده با تعداد نان فروخته‌شده نمی‌خواند.'
                    : 'پول نقد هنوز تحویل نشده است.',
                suggestion: $difference != 0
                    ? 'پیش از تسویه، علت اختلاف را با فروشنده روشن کنید.'
                    : 'پس از دریافت وجه، حساب را تسویه کنید.',
                url: '/admin/sales',
                urlLabel: 'تسویه حساب فروشنده',
            );
        }

        return $issues;
    }

    /**
     * A seller carrying the shop's money for longer than a week.
     *
     * The plain unsettled-account notice above says what is owed; this says
     * how long it has been owed, which is the part that turns an ordinary
     * balance into a problem. A day or two is the normal rhythm of the
     * shop — a fortnight is money nobody is chasing.
     */
    private function longUnsettledSellers(): array
    {
        // The shop's rule is settle by month end, so anything still open
        // from a month already finished is late by the shop's own standard
        // rather than by a number this scanner invented. A week is kept as
        // the softer warning inside the current month.
        [$monthStart] = Jalali::currentMonthRange();
        $limit = now()->subDays(7);

        $sellers = User::query()->ofCurrentBakery()
            ->whereHas('sales', fn ($q) => $q->sellerAccountOutstanding())
            ->get();

        $issues = [];

        foreach ($sellers as $seller) {
            $sales = Sale::query()
                ->where('user_id', $seller->id)
                ->sellerAccountOutstanding()
                ->oldest('created_at')
                ->get();

            $oldest = $sales->first();

            if (! $oldest) {
                continue;
            }

            // Inside the current month a week is the threshold; across a
            // month end there is none, because the month end was the
            // deadline. A sale on the 29th is late on the 1st.
            if ($oldest->created_at->gte($monthStart) && $oldest->created_at->gt($limit)) {
                continue;
            }

            $days = (int) $oldest->created_at->diffInDays(now());
            $total = round($sales->sum(fn (Sale $s) => $s->seller_account_amount), 2);
            $owed = SellerSettlement::outstandingFor($seller);

            // Carried past a month end the shop said it would settle by.
            $fromLastMonth = $oldest->created_at->lt($monthStart);

            $issues[] = new SystemIssue(
                key: "seller-account-stale-{$seller->id}",
                severity: $fromLastMonth || $days >= 14
                    ? SystemIssue::CRITICAL
                    : SystemIssue::WARNING,
                title: $fromLastMonth
                    ? "حساب {$seller->name} از ماه گذشته تسویه نشده"
                    : "حساب {$seller->name} {$days} روز است تسویه نشده",
                detail: 'قدیمی‌ترین بدهی از '.AppCalendar::date($oldest->created_at)
                    .' مانده — '.number_format($owed['loaves']).' نان، '
                    .Money::format($total).'.',
                cause: 'پول فروش نزد فروشنده مانده و به صندوق نرسیده است.',
                suggestion: $fromLastMonth
                    ? 'قرار بود تا پایان ماه تسویه شود؛ حساب ماه گذشته هنوز باز است.'
                    : ($days >= 14
                        ? 'همین امروز پیگیری کنید؛ دو هفته برای پول نقد نزد یک نفر زیاد است.'
                        : 'از فروشنده بخواهید تا پایان ماه تسویه کند.'),
                url: '/admin/sales',
                urlLabel: 'تسویه حساب فروشنده',
            );
        }

        return $issues;
    }

    /**
     * The shop spending more than it takes, this Jalali month.
     *
     * Read from the ledger rather than counted here, so it cannot disagree
     * with the financial report. Only raised once there is enough of the
     * month behind it to mean anything: a loss on the second of the month
     * is usually just a delivery paid for before the bread it becomes.
     */
    private function tradingAtALoss(): array
    {
        [$from, $to] = Jalali::currentMonthRange();

        // A week in, so a single large purchase at the start of the month
        // does not raise an alarm every time. Measured from the start of
        // the month forwards: the other way round Carbon returns a negative
        // and the check never fires at all.
        if ($from->copy()->startOfDay()->diffInDays(now()) < 7) {
            return [];
        }

        $income = Ledger::totalIncome($from, $to);
        $expenses = Ledger::totalExpenses($from, $to);
        $loss = round($expenses - $income, 2);

        if ($loss <= 0) {
            return [];
        }

        return [new SystemIssue(
            key: 'trading-at-a-loss',
            severity: SystemIssue::WARNING,
            title: 'این ماه بیش از درآمد خرج شده',
            detail: 'درآمد '.Money::format($income).' در برابر هزینه '
                .Money::format($expenses).' — اختلاف '.Money::format($loss).'.',
            cause: 'هزینه‌های ثبت‌شده و حقوق پرداختی از فروش ماه بیشتر است.',
            suggestion: 'اگر خرید عمده‌ای انجام شده طبیعی است؛ وگرنه'
                .' گزارش مالی را برای یافتن هزینه‌ی غیرمنتظره ببینید.',
            url: '/admin/reports',
            urlLabel: 'گزارش مالی',
        )];
    }

    /**
     * The month's diesel quota nearly gone, or already overdrawn.
     *
     * An oven that runs dry mid-bake loses the batch in it and the batch
     * behind it, so this is worth saying while there is still time to order
     * rather than at the end of the month with the rest of the figures.
     */
    private function dieselRunningOut(): array
    {
        $quota = DieselAllocation::current();

        if (! $quota) {
            return [];
        }

        $remaining = $quota->remaining_litres;

        // An empty tank stops the oven; an exhausted quota only stops the
        // next delivery. The first is worth saying even in a month with
        // quota left to draw.
        if ($quota->is_tank_empty && $quota->delivered_litres > 0) {
            return [new SystemIssue(
                key: 'diesel-tank-empty',
                severity: SystemIssue::CRITICAL,
                title: 'سوخت تحویلی این ماه مصرف شده',
                detail: number_format($quota->delivered_litres, 0).' لیتر تحویل گرفته‌اید و '
                    .number_format($quota->consumed_litres, 0).' لیتر بابت '
                    .number_format($quota->bags_baked, 0).' کیسه پخت مصرف شده.',
                cause: 'مصرف تخمینی بر پایه‌ی '
                    .rtrim(rtrim(number_format((float) ($quota->litres_per_bag ?? 0), 2), '0'), '.')
                    .' لیتر برای هر کیسه آرد است.',
                suggestion: $quota->remaining_litres > 0
                    ? number_format($quota->remaining_litres, 0)
                        .' لیتر از سهمیه‌ی ماه باقی است — تحویل بعدی را هماهنگ کنید.'
                    : 'سهمیه‌ی ماه هم تمام شده؛ برای ادامه‌ی کار سوخت آزاد لازم است.',
                url: '/admin/diesel-deliveries',
                urlLabel: 'تحویل گازوئیل',
            )];
        }

        // Comfortable on quota: nothing more to say.
        if (! $quota->is_overdrawn && $quota->used_percent < 80) {
            return [];
        }

        return [new SystemIssue(
            key: 'diesel-running-out',
            severity: $quota->is_overdrawn ? SystemIssue::CRITICAL : SystemIssue::WARNING,
            title: $quota->is_overdrawn
                ? 'سهمیه گازوئیل این ماه تمام شده'
                : 'سهمیه گازوئیل رو به اتمام است',
            detail: $quota->is_overdrawn
                ? number_format(abs($remaining), 0).' لیتر بیش از سهمیه تحویل گرفته شده.'
                : number_format($remaining, 0).' لیتر مانده — '
                    .$quota->used_percent.'٪ سهمیه مصرف شده.',
            cause: 'تحویل‌های ثبت‌شده به سقف سهمیه ماه رسیده است.',
            suggestion: $quota->is_overdrawn
                ? 'برای ادامه‌ی کار باید سوخت آزاد تهیه شود یا سهمیه اضافه گرفته شود.'
                : 'پیش از تمام شدن، تحویل بعدی را هماهنگ کنید.',
            url: '/admin/diesel-allocations',
            urlLabel: 'سهمیه گازوئیل',
        )];
    }

    /**
     * Bread sold all month and not one wage recorded anywhere.
     *
     * Wages are the largest running cost a bakery has. When none are
     * recorded, every profit figure the panel prints is overstated by the
     * whole payroll, and it is overstated silently — the reports have no
     * way to know the difference between a month with no wages and a
     * month whose wages were never entered.
     */
    private function wagesNeverRecorded(): array
    {
        $from = now()->startOfMonth();

        $sales = Sale::where('created_at', '>=', $from)->count();

        // Nothing sold yet this month: nothing to conclude.
        if ($sales === 0) {
            return [];
        }

        $paid = SalaryPayment::paid()
            ->where('paid_on', '>=', $from->toDateString())
            ->count();

        // Not every shop runs a payroll: this one pays wages as advances
        // entered straight on the expense sheet. Counting only payslips
        // called that a missing payroll and cried wolf every month.
        $asExpense = Expense::where('category', 'salary')
            ->where('spent_on', '>=', $from->toDateString())
            ->count();

        if ($paid > 0 || $asExpense > 0) {
            return [];
        }

        $everPaid = SalaryPayment::paid()->count() > 0
            || Expense::where('category', 'salary')->exists();

        return [new SystemIssue(
            key: 'wages-never-recorded',
            severity: SystemIssue::CRITICAL,
            title: 'حقوق این ماه ثبت نشده است',
            detail: $everPaid
                ? 'در این ماه '.number_format($sales).' فروش ثبت شده اما هیچ پرداخت حقوقی ثبت نشده است.'
                : 'تا امروز هیچ پرداخت حقوقی در سامانه ثبت نشده است، در حالی که '
                    .number_format($sales).' فروش این ماه ثبت شده.',
            cause: 'حقوق کارکنان خارج از سامانه پرداخت شده و نه در بخش حقوق و نه در هزینه‌ها وارد نشده است.',
            suggestion: 'تا زمانی که حقوق ثبت نشود، سود گزارش‌شده به اندازه‌ی کل حقوق بیشتر از واقعیت است.'
                .' پرداخت‌ها را در بخش حقوق، یا به‌عنوان هزینه‌ی دسته‌ی «حقوق کارکنان»، وارد کنید.',
            url: '/admin/salary-payments',
            urlLabel: 'حقوق',
        )];
    }

    /**
     * Expenses piled into the catch-all category.
     *
     * "Other" is where an expense goes when nobody chose a category, and
     * an expense report made mostly of it cannot answer what the money
     * went on — which is the only question the report exists to answer.
     */
    private function expensesMostlyUncategorised(): array
    {
        $from = now()->subDays(90)->toDateString();

        $total = (float) Expense::where('spent_on', '>=', $from)->sum('amount');

        if ($total <= 0) {
            return [];
        }

        $other = (float) Expense::where('spent_on', '>=', $from)
            ->where('category', 'other')
            ->sum('amount');

        $share = (int) round($other / $total * 100);

        if ($share < 50) {
            return [];
        }

        return [new SystemIssue(
            key: 'expenses-mostly-other',
            severity: SystemIssue::WARNING,
            title: 'بیشتر هزینه‌ها در دسته‌ی «سایر» ثبت شده',
            detail: $share.'٪ از هزینه‌های سه ماه گذشته ('
                .Money::format($other).') بدون دسته‌بندی ثبت شده است.',
            cause: 'هنگام ثبت هزینه، دسته‌ی مناسب انتخاب نشده است.',
            suggestion: 'دسته‌ی این هزینه‌ها را اصلاح کنید تا گزارش هزینه‌ها معنا پیدا کند.',
            url: '/admin/expenses',
            urlLabel: 'هزینه‌ها',
        )];
    }

    private function unsettledShortfalls(): array
    {
        $sales = Sale::query()->shortfallOutstanding()->get();

        if ($sales->isEmpty()) {
            return [];
        }

        return [new SystemIssue(
            key: 'unsettled-shortfalls',
            severity: SystemIssue::WARNING,
            title: 'کسری نان تسویه‌نشده وجود دارد',
            detail: $sales->count().' فروش با مجموع '
                .number_format((int) $sales->sum('shortfall_count')).' نان کسری'
                .' ('.Money::format((float) $sales->sum('shortfall_amount')).').',
            cause: 'تعداد نان ثبت‌شده در فروش، از تعداد چانه آن دسته کمتر بوده است.',
            suggestion: 'اگر کسری توجیه دارد (ضایعات یا نان مصرفی) تسویه کنید،'
                .' وگرنه از فروشنده پیگیری شود.',
            url: '/admin/sales',
            urlLabel: 'بررسی کسری‌ها',
        )];
    }

    /**
     * Dough kneaded days ago and never shaped, or chane never sold. Both
     * are perishable, so a stale record usually means a step was skipped
     * rather than that the goods are still sitting there.
     */
    private function stalePending(): array
    {
        $issues = [];
        $cutoff = now()->subDay();

        $dough = DoughEntry::pending()->where('created_at', '<', $cutoff)->count();

        if ($dough > 0) {
            $issues[] = new SystemIssue(
                key: 'stale-dough',
                severity: SystemIssue::WARNING,
                title: 'خمیر ثبت‌شده بدون چانه مانده است',
                detail: "{$dough} دسته خمیر بیش از یک روز است که چانه‌گیری نشده.",
                cause: 'چانه‌گیری انجام شده اما ثبت نشده، یا خمیر ضایع شده است.',
                suggestion: 'خمیر ماندگار نیست؛ اگر چانه گرفته شده آن را ثبت کنید'
                    .' تا موجودی خمیر انبار درست بماند.',
                url: '/admin/dough-entries',
                urlLabel: 'مشاهده خمیرها',
            );
        }

        $chane = ChaneEntry::pending()->where('created_at', '<', $cutoff)->count();

        if ($chane > 0) {
            $issues[] = new SystemIssue(
                key: 'stale-chane',
                severity: SystemIssue::WARNING,
                title: 'چانه فروخته‌نشده مانده است',
                detail: "{$chane} دسته چانه بیش از یک روز است که فروش آن ثبت نشده.",
                cause: 'فروش انجام شده اما ثبت نشده است.',
                suggestion: 'فروش ثبت‌نشده باعث می‌شود درآمد روز کمتر از واقع دیده شود.',
                url: '/admin/sales',
                urlLabel: 'ثبت فروش',
            );
        }

        return $issues;
    }
}
