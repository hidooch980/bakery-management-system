<?php

namespace App\Support;

use App\Models\CashCount;
use App\Models\Sale;
use App\Models\SellerWarningRule;
use App\Models\StaffPenaltyRequest;
use App\Models\User;
use App\Models\WorkStart;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Checks the shop's active seller-warning rules against one day's records
 * and files a pending warning for each hit. The manager approves or
 * rejects it in the same list as a seller's own penalty requests. A rule
 * never docks pay, and never files the same warning twice for one day.
 */
class SellerWarningRules
{
    /**
     * @return list<array{rule: SellerWarningRule, seller: User, detail: string}>
     */
    public static function hits(Carbon $day): array
    {
        $day = $day->copy()->startOfDay();
        $rules = SellerWarningRule::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (SellerWarningRule $r) => $r->threshold !== null && (float) $r->threshold > 0);
        if ($rules->isEmpty()) {
            return [];
        }

        $sellers = User::query()->ofCurrentBakery()->role('seller')->where('is_active', true)->orderBy('id')->get();
        $hits = [];
        foreach ($rules as $rule) {
            foreach ($sellers as $seller) {
                $detail = self::check($rule, $seller, $day);
                if ($detail !== null) {
                    $hits[] = ['rule' => $rule, 'seller' => $seller, 'detail' => $detail];
                }
            }
        }

        return $hits;
    }

    /** One rule against one seller on one day: a sentence if it was broken, else null. */
    public static function check(SellerWarningRule $rule, User $seller, Carbon $day): ?string
    {
        $t = (float) $rule->threshold;
        $window = [$day->copy()->startOfDay(), $day->copy()->endOfDay()];

        switch ($rule->condition) {
            case 'debt_over':
                $balance = SellerSettlement::balanceFor($seller)['balance'];

                return $balance > $t ? 'بدهی '.Money::format($balance).' — سقف '.Money::format($t) : null;

            case 'late_settlement':
                $oldest = SellerSettlement::outstandingSales($seller)->reorder('created_at')->value('created_at');
                if ($oldest === null || SellerSettlement::balanceFor($seller)['balance'] <= 0) {
                    return null;
                }
                $days = (int) Carbon::parse($oldest)->startOfDay()->diffInDays($day);

                return $days > $t ? 'قدیمی‌ترین فروش تسویه‌نشده '.AppCalendar::date(Carbon::parse($oldest))
                    .' — '.$days.' روز (مهلت '.(int) $t.' روز)' : null;

            case 'till_shortfall':
                $bread = (float) Sale::query()->where('user_id', $seller->id)
                    ->whereBetween('created_at', $window)->sum('shortfall_amount');
                $drawer = CashCount::query()->where('user_id', $seller->id)
                    ->whereBetween('counted_at', $window)->get()
                    ->sum(fn (CashCount $c) => max(0, -$c->difference));
                $short = round($bread + $drawer, 2);

                return $short > $t ? 'کسری '.Money::format($short).' — سقف '.Money::format($t) : null;

            case 'late_shift':
                $late = (int) WorkStart::query()->where('user_id', $seller->id)
                    ->whereDate('date', $day->toDateString())->where('is_late', true)->max('late_minutes');

                return $late > $t ? $late.' دقیقه تأخیر (مجاز '.(int) $t.' دقیقه)' : null;

            case 'off_queue_bulk':
                $big = Sale::query()->where('user_id', $seller->id)->whereBetween('created_at', $window)
                    ->whereNotIn('payment_type', array_merge(Sale::BANKED_TYPES, Sale::GIVEAWAY_TYPES, Sale::SHORTFALL_TYPES))
                    ->where('bread_count', '>=', $t)->orderByDesc('bread_count')->first();

                return $big ? 'یک ثبت '.$big->bread_count.' نان بیرون از کارتخوان ('
                    .(Sale::PAYMENT_LABELS[$big->payment_type] ?? $big->payment_type).') — سقف '.(int) $t : null;

            case 'card_mismatch':
                $mine = (float) Sale::query()->where('user_id', $seller->id)->where('payment_type', 'card')
                    ->whereBetween('created_at', $window)->sum('amount');
                if ($mine <= 0) {
                    return null;
                }
                $reader = ShopChecks::cardReader($day);
                if ($reader['sms_count'] === 0) {
                    return null; // the bank has not said yet; nothing to compare
                }
                $gap = $reader['sales'] - $reader['deposits'];

                return $gap > $t ? 'فروش کارتخوان ثبت‌شده '.Money::format($reader['sales']).' ولی واریز بانک '
                    .Money::format($reader['deposits']).' (سهم این فروشنده '.Money::format($mine).')' : null;
        }

        return null;
    }

    /**
     * File a pending warning for each hit. Returns how many were new.
     */
    public static function file(Carbon $day): int
    {
        $made = 0;
        foreach (self::hits($day) as $hit) {
            $rule = $hit['rule'];
            $exists = StaffPenaltyRequest::query()
                ->where('seller_warning_rule_id', $rule->id)
                ->where('user_id', $hit['seller']->id)
                ->whereDate('occurred_on', $day->toDateString())
                ->exists();
            if ($exists) {
                continue;
            }
            try {
                StaffPenaltyRequest::create([
                    'user_id' => $hit['seller']->id,
                    'requested_by' => null,
                    'seller_warning_rule_id' => $rule->id,
                    'type' => StaffPenaltyRequest::WARNING,
                    'reason' => array_key_exists($rule->reason, StaffPenaltyRequest::REASONS) ? $rule->reason : 'rule',
                    'amount' => null,
                    'note' => mb_substr($rule->name.' — '.$hit['detail'], 0, 500),
                    'occurred_on' => $day->toDateString(),
                ]);
                $made++;
            } catch (UniqueConstraintViolationException) {
                // filed by a concurrent run
            }
        }

        return $made;
    }
}
