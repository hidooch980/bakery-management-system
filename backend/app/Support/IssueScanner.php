<?php

namespace App\Support;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
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
            ...$this->productionShortfall(),
            ...$this->quotaOverrun(),
            ...$this->negativeBankBalance(),
            ...$this->sellerAccounts(),
            ...$this->unsettledShortfalls(),
            ...$this->stalePending(),
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
        $bakery = Bakery::first();

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

    /**
     * Bread that does not account for the flour a period burned through.
     * Producing less is the telling direction: the flour left the store
     * but nothing came back for it.
     */
    private function productionShortfall(): array
    {
        $allocation = FlourAllocation::forJalaliMonthOf(now());

        if (! $allocation) {
            return [];
        }

        $issues = [];

        foreach ($allocation->periods as $period) {
            if ($period->nanino_production_status !== 'short') {
                continue;
            }

            $issues[] = new SystemIssue(
                key: "production-short-{$period->id}",
                severity: SystemIssue::CRITICAL,
                title: "تولید {$period->label} کمتر از آرد مصرفی است",
                detail: number_format($period->nanino_chane_count).' نان تولید شده،'
                    .' اما آرد مصرفی این دوره باید '
                    .number_format($period->expected_nanino_count).' نان می‌داد'
                    .' ('.number_format(abs($period->nanino_production_gap)).' نان کمتر).',
                cause: 'چانه‌ای ثبت نشده، ضایعات ثبت‌نشده، یا آردی که بدون تولید از انبار خارج شده.',
                suggestion: 'ثبت‌های تولید این بازه را بررسی کنید. این اختلاف نباید'
                    .' بدون توضیح بماند، چون نشانه آرد از دست رفته است.',
                url: '/admin/chane-entries',
                urlLabel: 'بررسی ثبت‌های چانه',
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

        foreach (\App\Models\BankAccount::all() as $account) {
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
        $sellers = User::query()
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
