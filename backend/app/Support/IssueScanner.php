<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\DieselAllocation;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Models\Loan;
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
            ...$this->loanInstalmentDue(),
            ...$this->flourOutWithPartners(),
            ...$this->dieselRunningOut(),
            ...$this->monthlyObligations(),
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
                magnitude: $missing,
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
            magnitude: (float) $missing->count(),
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
                // How far under the line, not the balance: a threshold
                // raised later must not read as the stock falling.
                magnitude: (float) $item->low_threshold - (float) $item->balance,
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
                magnitude: (float) $period->used_kg - (float) $period->allocated_kg,
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
                magnitude: abs((float) $account->balance),
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
                magnitude: $total,
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
                magnitude: (float) $days,
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
        // The shop's own month, the 5th to the 4th, because that is the
        // cycle the flour quota runs on and the one the report headlines.
        // The two must not answer «how did the month go» differently.
        [$from, $to] = Jalali::currentQuotaPeriod();

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
            key: 'trading-at-a-loss-'.$from->format('Y-m'),
            severity: SystemIssue::WARNING,
            title: 'این ماه بیش از درآمد خرج شده',
            detail: 'درآمد '.Money::format($income).' در برابر هزینه '
                .Money::format($expenses).' — اختلاف '.Money::format($loss).'.',
            cause: 'هزینه‌های ثبت‌شده و حقوق پرداختی از فروش ماه بیشتر است.',
            suggestion: 'اگر خرید عمده‌ای انجام شده طبیعی است؛ وگرنه'
                .' گزارش مالی را برای یافتن هزینه‌ی غیرمنتظره ببینید.',
            url: '/admin/reports',
            urlLabel: 'گزارش مالی',
            magnitude: $loss,
        )];
    }

    /**
     * Flour lent to a partner bakery and not yet returned.
     *
     * The shop lends and borrows sacks with the bakeries around it, which
     * is ordinary and not a debt in money — but the sacks are the shop's,
     * and the store is short by exactly what is out. On 2026-08-17 that was
     * 76 sacks across two partners, the oldest fifteen days old, and
     * nothing anywhere said so: the seller's cash gets chased, and flour
     * worth more than most of those balances did not.
     *
     * Counted per partner rather than per lending, because what the owner
     * needs is a name and a number, not four rows about the same person.
     *
     * A fortnight is the line. Sacks go back and forth within a week here
     * as a matter of course; past two weeks it has stopped being the
     * ordinary rhythm and become flour nobody is asking for.
     */
    private function flourOutWithPartners(): array
    {
        $limit = now()->subDays(14);

        $open = ConsignmentFlour::query()
            ->where('direction', 'lent')
            ->whereNull('settled_on')
            ->with('partner')
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        $issues = [];

        foreach ($open->groupBy(fn (ConsignmentFlour $c) => $c->customer_id ?? $c->partner_name) as $key => $lendings) {
            $oldest = $lendings->min(fn (ConsignmentFlour $c) => $c->occurred_on);

            if ($oldest->gt($limit)) {
                continue;
            }

            $bags = round($lendings->sum(fn (ConsignmentFlour $c) => (float) $c->bags), 1);
            $days = (int) $oldest->diffInDays(now());
            $who = $lendings->first()->partner_label ?: 'همکار بی‌نام';

            $issues[] = new SystemIssue(
                key: "consignment-open-{$key}",
                severity: SystemIssue::WARNING,
                title: "آرد امانی نزد {$who} برنگشته",
                detail: number_format($bags, 1).' کیسه در '.$lendings->count().' نوبت،'
                    ." قدیمی‌ترین {$days} روز پیش (".AppCalendar::date($oldest).').',
                cause: 'آرد به نانوایی همکار داده شده و هنوز پس نیامده است.',
                suggestion: 'اگر برگشته، در بخش آرد امانی تسویه‌اش کنید تا انبار درست شود؛'
                    .' وگرنه پیگیری کنید — این کیسه‌ها از موجودی شما کم شده‌اند.',
                url: '/admin/consignment-flours',
                urlLabel: 'آرد امانی',
                // Sacks, which is what the shop counts them in and what
                // grows if more go out to the same partner.
                magnitude: $bags,
            );
        }

        return $issues;
    }

    /**
     * A loan instalment that has come due, or is about to.
     *
     * The shop pays its machine loan in one transfer on the 10th of each
     * month, and until now nothing anywhere said so — the loan page knew
     * the date and nobody was looking at the loan page. A missed bank
     * instalment costs a penalty and, worse, is the kind of thing that is
     * noticed a month late.
     *
     * Warned about a week out rather than on the day, because the money
     * has to be in the account before the transfer, not after.
     */
    private function loanInstalmentDue(): array
    {
        $issues = [];
        $soon = now()->addDays(7);

        foreach (Loan::outstanding()->get() as $loan) {
            $due = $loan->next_due_on;

            if ($due === null || $due->gt($soon)) {
                continue;
            }

            $overdue = $loan->is_overdue;
            $days = (int) abs($due->diffInDays(now()));

            $issues[] = new SystemIssue(
                key: "loan-due-{$loan->id}",
                severity: $overdue ? SystemIssue::CRITICAL : SystemIssue::WARNING,
                title: $overdue
                    ? "قسط «{$loan->title}» عقب افتاده است"
                    : "قسط «{$loan->title}» نزدیک است",
                // Says the date has passed, not why. The check cannot tell
                // an unpaid instalment from a paid one nobody entered, and
                // claiming the second when it is the first sends the owner
                // looking for a bank entry that was never there.
                detail: 'قسط '.Money::format((float) $loan->instalment_amount)
                    .' سررسید '.$loan->next_due_on_display
                    .($overdue
                        ? " — {$days} روز از سررسید گذشته."
                        : " — {$days} روز مانده.")
                    .' تا امروز '.$loan->paid_formatted.' پرداخت شده،'
                    .' مانده‌ی وام '.$loan->remaining_formatted.'.',
                cause: $overdue
                    ? 'یا قسط پرداخت نشده، یا پرداخت شده و در سامانه ثبت نشده است.'
                    : 'موعد ماهانه‌ی این وام نزدیک شده است.',
                suggestion: $overdue
                    ? 'اگر پرداخت شده آن را ثبت کنید تا مانده‌ی وام درست بماند؛'
                        .' وگرنه پیش از جریمه پرداخت کنید.'
                    : 'پیش از سررسید مطمئن شوید موجودی حساب کافی است.',
                url: '/admin/loans',
                urlLabel: 'وام‌ها',
                // Days late. A loan a month overdue is a different problem
                // from one a day overdue, so an answer about the second
                // must not cover the first. Not yet due counts as zero.
                magnitude: $overdue ? (float) $days : 0.0,
            );
        }

        return $issues;
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
                key: "diesel-tank-empty-{$quota->id}",
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
                // Litres taken, which only climbs within a period. Not
                // used_percent: that is capped at 100, so it cannot tell
                // 'just over' from 'far over'.
                magnitude: (float) $quota->delivered_litres,
            )];
        }

        // Comfortable on quota: nothing more to say.
        if (! $quota->is_overdrawn && $quota->used_percent < 80) {
            return [];
        }

        return [new SystemIssue(
            key: "diesel-running-out-{$quota->id}",
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
            magnitude: (float) $quota->delivered_litres,
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
    /**
     * The two things this shop owes every month, and whether they are in.
     *
     * Wages at the end of the Jalali month, insurance before it —
     * «پایان هر ماه پرداخت حقوق», «پرداخت بیمه هم قبل پایان ماه هست».
     *
     * The old check fired the moment anything was sold, which meant it
     * shouted «حقوق این ماه ثبت نشده» as CRITICAL from the shop's first
     * day of trading — twenty-two days before its first payday had even
     * arrived. That is not a warning, it is noise wearing a warning's
     * colour, and it is exactly what teaches an owner to stop reading the
     * page.
     *
     * So: nothing is said until the thing is actually due. Insurance is
     * chased in the last five days of the month, wages once the month has
     * closed on them. Both go quiet the moment a payment is recorded,
     * whether as a payslip or as an expense in its own category — this
     * shop has paid wages both ways.
     */
    /**
     * The two things this shop owes every month, and whether they are in.
     *
     * Wages at the end of the Jalali month, insurance before it —
     * «پایان هر ماه پرداخت حقوق», «پرداخت بیمه هم قبل پایان ماه هست».
     *
     * The old check fired the moment anything was sold, which meant it
     * shouted «حقوق این ماه ثبت نشده» as CRITICAL from the shop's first
     * day of trading, three weeks before its first payday arrived. That is
     * noise wearing a warning's colour, and it is what teaches an owner to
     * stop reading the page.
     *
     * Two months are looked at, and that is the whole trick. Chasing only
     * the current one cannot work: a payment due at month end is never
     * late while the month is running, and the moment it ends the calendar
     * rolls and the countdown starts again at thirty. Wages would have
     * gone unmentioned forever. So the month in progress is checked for
     * what is coming, and the one just gone for what never arrived.
     */
    private function monthlyObligations(): array
    {
        [$thisStart, $thisEnd] = Jalali::currentMonthRange();
        [$lastStart, $lastEnd] = Jalali::monthRangeFor($thisStart->copy()->subDay());

        $daysLeft = (int) now()->startOfDay()->diffInDays($thisEnd->copy()->startOfDay(), false);

        return [
            // The month just gone, if it closed without either payment. A
            // shop that was not trading then owes nothing for it, which
            // the sales guard inside handles.
            ...$this->obligation('insurance', 'حق بیمه', $lastStart, $lastEnd, '/admin/expenses'),
            ...$this->obligation('wages', 'حقوق کارکنان', $lastStart, $lastEnd, '/admin/salary-payments'),

            // And the month in progress, once its own due date is near.
            // Insurance is paid before the month closes, so five days out
            // is while there is still time to pay it; wages fall on the
            // last day itself.
            ...($daysLeft <= 5
                ? $this->obligation('insurance', 'حق بیمه', $thisStart, $thisEnd, '/admin/expenses', $daysLeft)
                : []),
            ...($daysLeft <= 0
                ? $this->obligation('wages', 'حقوق کارکنان', $thisStart, $thisEnd, '/admin/salary-payments', $daysLeft)
                : []),
        ];
    }

    /**
     * One monthly obligation for one month.
     *
     * [$daysLeft] given means the month is still running and this is a
     * heads-up; absent means the month has closed and the payment never
     * came, which is the serious one.
     *
     * @return array<int, SystemIssue>
     */
    private function obligation(
        string $key,
        string $label,
        $from,
        $to,
        string $url,
        ?int $daysLeft = null,
    ): array {
        // Nothing traded in that month: nothing to conclude about what it
        // owes. This is what keeps a shop's first weeks quiet.
        if (Sale::whereBetween('created_at', [$from, $to])->count() === 0) {
            return [];
        }

        $category = $key === 'wages' ? 'salary' : 'insurance';

        $recorded = Expense::where('category', $category)
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->exists();

        // Wages have a ledger of their own as well as an expense category,
        // and this shop has used both.
        if ($key === 'wages' && ! $recorded) {
            $recorded = SalaryPayment::paid()
                ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
                ->exists();
        }

        if ($recorded) {
            return [];
        }

        $overdue = $daysLeft === null;
        $month = Jalali::monthLabel($from);

        return [new SystemIssue(
            key: "monthly-{$key}-".$from->format('Y-m'),
            severity: $overdue ? SystemIssue::CRITICAL : SystemIssue::WARNING,
            title: $overdue
                ? "{$label} {$month} پرداخت نشده"
                : "{$label} این ماه نزدیک است",
            detail: $overdue
                ? "ماه {$month} تمام شده و {$label} آن در سامانه ثبت نشده است."
                : "{$daysLeft} روز تا پایان ماه مانده و {$label} هنوز ثبت نشده است.",
            cause: $overdue
                ? 'یا پرداخت نشده، یا پرداخت شده و وارد سامانه نشده است.'
                : 'موعد ماهانه‌ی این پرداخت نزدیک شده است.',
            suggestion: $overdue
                ? 'تا وقتی ثبت نشود، سود گزارش‌شده به همان اندازه بیشتر از واقعیت است.'
                : 'پیش از پایان ماه پرداخت و ثبتش کنید.',
            url: $url,
            urlLabel: $label,
            // Days past the month's end, so an answer about a payment a day
            // late does not cover the same one a month late.
            magnitude: $overdue
                ? (float) max(0, (int) $to->copy()->startOfDay()->diffInDays(now()->startOfDay()))
                : 0.0,
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
            magnitude: (float) $share,
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
            magnitude: (float) $sales->sum('shortfall_count'),
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
                magnitude: (float) $dough,
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
                magnitude: (float) $chane,
            );
        }

        return $issues;
    }
}
