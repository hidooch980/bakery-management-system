<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use App\Models\ConsignmentFlourReturn;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\PartnerFlourChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * پیام فوری به همکار هنگام ورود و خروج آرد امانی.
 *
 * ثبت: هر تغییری که کیسه، جهت یا تاریخ را عوض کند (نه فقط توضیح) یک ردیف
 * «در انتظار» می‌سازد — فقط وقتی کلید همان نانوایی روشن است. پس روشن‌کردن
 * کلید برای ثبت‌های گذشته چیزی نمی‌فرستد.
 *
 * ارسال: وقتی دو دقیقه از آخرین تغییرِ یک همکار گذشت، همهٔ تغییرهای او در
 * یک پیام می‌رود. ثبتی که در همان دو دقیقه حذف شود چیزی نمی‌فرستد؛ ویرایش
 * در همان مدت فقط عدد نهایی را می‌فرستد؛ ویرایش یا حذفِ چیزی که قبلاً
 * گفته شده «اصلاحیه» می‌فرستد.
 */
class PartnerFlow
{
    public const WAIT_SECONDS = 120;

    // ---------------------------------------------------------------- ثبت

    public static function flourCreated(ConsignmentFlour $f): void
    {
        self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'created', null, self::flourSnap($f));
    }

    public static function flourUpdated(ConsignmentFlour $f): void
    {
        $before = self::flourSnap($f, original: true);
        $after = self::flourSnap($f);
        $oldCustomer = $f->getOriginal('customer_id');

        if ((int) $oldCustomer !== (int) $f->customer_id) {
            // ردیف به همکار دیگری منتقل شد: برای یکی حذف، برای دیگری ثبت تازه.
            self::record($f->bakery_id, $oldCustomer, 'flour', $f->id, 'deleted', $before, null);
            self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'created', null, $after);

            return;
        }

        if ($f->wasChanged(['bags', 'direction', 'occurred_on'])
            && ($before['bags'] !== $after['bags'] || $before['direction'] !== $after['direction'] || $before['date'] !== $after['date'])) {
            self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'updated', $before, $after);
        }

        if ($f->wasChanged('settled_on')) {
            $wasSettled = $f->getOriginal('settled_on') !== null;
            if (! $wasSettled && $f->settled_on !== null) {
                // باقی‌مانده‌ای که با «تسویه» یک‌جا برگشت؛ اگر برگشت‌ها همه را پوشانده باشند صفر است.
                $rest = max(round((float) $f->bags - (float) $f->returns()->sum('bags'), 2), 0.0);
                self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'settled', null,
                    $after + ['rest' => $rest, 'on' => $f->settled_on->toDateString()]);
            } elseif ($wasSettled && $f->settled_on === null) {
                self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'unsettled', null, $after);
            }
        }
    }

    public static function flourDeleted(ConsignmentFlour $f): void
    {
        self::record($f->bakery_id, $f->customer_id, 'flour', $f->id, 'deleted', self::flourSnap($f), null);
    }

    public static function returnCreated(ConsignmentFlourReturn $r): void
    {
        $parent = $r->consignment()->first();
        if ($parent) {
            self::record($r->bakery_id ?? $parent->bakery_id, $parent->customer_id, 'return', $r->id, 'created', null, self::returnSnap($r, $parent));
        }
    }

    public static function returnUpdated(ConsignmentFlourReturn $r): void
    {
        $parent = $r->consignment()->first();
        if (! $parent || ! $r->wasChanged(['bags', 'returned_on'])) {
            return;
        }
        $before = self::returnSnap($r, $parent, original: true);
        $after = self::returnSnap($r, $parent);
        if ($before['bags'] !== $after['bags'] || $before['date'] !== $after['date']) {
            self::record($r->bakery_id ?? $parent->bakery_id, $parent->customer_id, 'return', $r->id, 'updated', $before, $after);
        }
    }

    public static function returnDeleted(ConsignmentFlourReturn $r): void
    {
        $parent = $r->consignment()->first();
        if ($parent) {
            self::record($r->bakery_id ?? $parent->bakery_id, $parent->customer_id, 'return', $r->id, 'deleted', self::returnSnap($r, $parent), null);
        }
    }

    private static function record(?int $bakeryId, $customerId, string $type, int $id, string $kind, ?array $before, ?array $after): void
    {
        if ($bakeryId === null || $customerId === null) {
            return;
        }

        try {
            $on = (bool) NotificationSetting::query()->acrossBakeries()
                ->where('bakery_id', $bakeryId)->value('partner_flow_enabled');
            if (! $on) {
                return;
            }

            PartnerFlourChange::query()->acrossBakeries()->create([
                'bakery_id' => $bakeryId,
                'customer_id' => (int) $customerId,
                'subject_type' => $type,
                'subject_id' => $id,
                'kind' => $kind,
                'before' => $before,
                'after' => $after,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            // پیامی که ثبت نشد نباید ثبت آرد را خراب کند.
            report($e);
        }
    }

    private static function flourSnap(ConsignmentFlour $f, bool $original = false): array
    {
        $get = fn (string $k) => $original ? $f->getOriginal($k) : $f->getAttribute($k);

        return [
            'direction' => (string) $get('direction'),
            'bags' => round((float) $get('bags'), 2),
            'date' => self::day($get('occurred_on')),
        ];
    }

    private static function returnSnap(ConsignmentFlourReturn $r, ConsignmentFlour $parent, bool $original = false): array
    {
        $get = fn (string $k) => $original ? $r->getOriginal($k) : $r->getAttribute($k);

        return [
            'bags' => round((float) $get('bags'), 2),
            'date' => self::day($get('returned_on')),
            'parent' => self::flourSnap($parent),
        ];
    }

    private static function day($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse($value))->toDateString();
    }

    // ---------------------------------------------------------------- ارسال

    /**
     * همکارانی که دو دقیقه از آخرین تغییرشان گذشته، هر کدام یک پیام.
     * باید درون نانوایی جاری صدا زده شود (ForEachBakery).
     *
     * @return array<int, string> نتیجه برای هر همکار
     */
    public static function flush(?Carbon $now = null): array
    {
        $now ??= now();
        $results = [];

        if (! NotificationSetting::current()->partner_flow_enabled) {
            // کلید خاموش شد: آنچه مانده دیگر فرستاده نمی‌شود.
            PartnerFlourChange::query()->where('status', 'pending')->update(['status' => 'dropped']);

            return $results;
        }

        $ready = PartnerFlourChange::query()->where('status', 'pending')
            ->selectRaw('customer_id, max(created_at) as last_at')
            ->groupBy('customer_id')->get()
            ->filter(fn ($r) => Carbon::parse($r->last_at)->lte($now->copy()->subSeconds(self::WAIT_SECONDS)));

        foreach ($ready as $r) {
            $results[(int) $r->customer_id] = self::flushPartner((int) $r->customer_id, $now);
        }

        return $results;
    }

    private static function flushPartner(int $customerId, Carbon $now): string
    {
        // یک بار برداشتن: ردیف‌هایی که این اجرا «در حال ارسال» کرد، اجرای دیگری نمی‌گیرد.
        $batch = DB::transaction(function () use ($customerId) {
            $rows = PartnerFlourChange::query()->where('customer_id', $customerId)
                ->where('status', 'pending')->orderBy('id')->lockForUpdate()->get();
            PartnerFlourChange::query()->whereKey($rows->modelKeys())->update(['status' => 'sending']);

            return $rows;
        });

        if ($batch->isEmpty()) {
            return 'empty';
        }

        $partner = Customer::query()->find($customerId);
        [$lines, $short] = $partner ? self::lines($batch) : [[], []];

        if ($lines === []) {
            PartnerFlourChange::query()->whereKey($batch->modelKeys())->update(['status' => 'dropped']);

            return 'nothing';
        }

        $log = Notify::person('partner_flow', $partner, self::message($partner, $lines), true, null, self::smsMessage($partner, $short));
        PartnerFlourChange::query()->whereKey($batch->modelKeys())->update(['status' => 'sent', 'sent_at' => $now]);

        return $log->channel.' '.$log->status;
    }

    /** @return array{0: list<string>, 1: list<string>} سطرهای پیام بله و پیامک */
    public static function lines(Collection $batch): array
    {
        $lines = [];
        $short = [];

        foreach ($batch->groupBy(fn ($c) => $c->subject_type.':'.$c->subject_id) as $rows) {
            $first = $rows->first();
            $type = $first->subject_type;
            $kinds = $rows->pluck('kind');
            $told = PartnerFlourChange::query()
                ->where('subject_type', $type)->where('subject_id', $first->subject_id)
                ->where('status', 'sent')->exists();

            if ($kinds->contains('created')) {
                if ($kinds->contains('deleted')) {
                    continue; // ثبت و حذف در همان دو دقیقه: چیزی برای گفتن نیست.
                }
                $state = $rows->reverse()->first(fn ($c) => $c->after !== null)->after;
                [$l, $s] = self::movement($type, $state);
                $lines[] = $l;
                $short[] = $s;
            } else {
                $initial = $rows->first(fn ($c) => $c->before !== null)?->before;
                $deleted = $kinds->contains('deleted');
                $final = $deleted ? null : $rows->reverse()->first(fn ($c) => in_array($c->kind, ['updated'], true))?->after;

                if ($told && $initial !== null && ($deleted || ($final !== null && $final !== $initial))) {
                    $was = self::describe($type, $initial);
                    $line = $deleted
                        ? 'اصلاحیه: «'.$was.'» حذف شد.'
                        : 'اصلاحیه: «'.$was.'» به «'.self::describe($type, $final).'» تغییر کرد.';
                    $lines[] = $line;
                    $short[] = $line;
                }
            }

            // تسویه یک حرکت تازه است، حتی برای ردیفی که پیش از روشن‌شدن کلید ثبت شده بود.
            if ($type === 'flour' && ! $kinds->contains('deleted')) {
                $settles = $rows->whereIn('kind', ['settled', 'unsettled'])->values();
                $last = $settles->last();
                $settledBefore = $told && PartnerFlourChange::query()->where('subject_type', 'flour')
                    ->where('subject_id', $first->subject_id)->where('status', 'sent')
                    ->where('kind', 'settled')->exists();

                if ($last?->kind === 'settled' && $settles->first()->kind === 'settled' && $last->after['rest'] > 0.001) {
                    $s = $last->after;
                    $what = self::describe('flour', $s);
                    $verb = $s['direction'] === 'lent' ? 'برگشت از شما' : 'برگشت به شما';
                    $lines[] = $verb.': '.self::bags($s['rest']).' کیسه (تسویهٔ «'.$what.'»)';
                    $short[] = $verb.' (تسویه): '.self::bags($s['rest']).' کیسه';
                } elseif ($last?->kind === 'unsettled' && $settles->first()->kind === 'unsettled' && $settledBefore) {
                    $line = 'اصلاحیه: تسویهٔ «'.self::describe('flour', $last->after).'» برداشته شد.';
                    $lines[] = $line;
                    $short[] = $line;
                }
            }
        }

        return [$lines, $short];
    }

    /** @return array{0: string, 1: string} */
    private static function movement(string $type, array $s): array
    {
        $dated = $s['date'] && $s['date'] !== now()->toDateString() ? ' (مورخ '.AppCalendar::date($s['date']).')' : '';

        if ($type === 'flour') {
            $verb = $s['direction'] === 'lent' ? 'تحویل به شما' : 'دریافت از شما';
            $text = $verb.': '.self::bags($s['bags']).' کیسه'.$dated;

            return [$text, $text];
        }

        $p = $s['parent'];
        $verb = $p['direction'] === 'lent' ? 'برگشت از شما' : 'برگشت به شما';
        $text = $verb.': '.self::bags($s['bags']).' کیسه'.$dated;

        return [$text.' (بابت «'.self::describe('flour', $p).'»)', $text];
    }

    /** «تحویل به شما ۵ کیسه مورخ ۱۴۰۵/۰۸/۱۸» */
    private static function describe(string $type, array $s): string
    {
        if ($type === 'flour') {
            $verb = $s['direction'] === 'lent' ? 'تحویل به شما' : 'دریافت از شما';
        } else {
            $verb = ($s['parent']['direction'] ?? 'lent') === 'lent' ? 'برگشت از شما' : 'برگشت به شما';
        }

        return $verb.' '.self::bags($s['bags']).' کیسه مورخ '.AppCalendar::date($s['date']);
    }

    /** رقم فارسی؛ نقطهٔ اعشار میان دو رقم «٫» می‌شود، نقطهٔ پایان جمله نه. */
    private static function persian(string $text): string
    {
        $text = preg_replace('/(?<=\d)\.(?=\d)/u', '٫', $text) ?? $text;

        return strtr($text, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    private static function bags(float $v): string
    {
        return PartnerStatement::bags($v);
    }

    public static function message(Customer $partner, array $lines): string
    {
        return self::persian(implode("\n", [
            CurrentBakery::get()?->name.' — آرد امانی',
            'همکار: '.$partner->name,
            'تاریخ: '.AppCalendar::date(now()),
            ...$lines,
            'مانده: '.PartnerFlourReport::balanceText(PartnerStatement::balanceOf($partner)),
        ]));
    }

    public static function smsMessage(Customer $partner, array $short): string
    {
        $balance = PartnerStatement::balanceOf($partner);
        $bags = PartnerStatement::bags($balance);
        $tail = match (true) {
            $balance > 0.001 => 'شما '.$bags.' کیسه بدهکارید.',
            $balance < -0.001 => 'ما '.$bags.' کیسه به شما بدهکاریم.',
            default => 'حساب آرد تسویه است.',
        };

        return self::persian(implode("\n", [
            CurrentBakery::get()?->name,
            'آرد امانی '.AppCalendar::date(now()),
            $partner->name,
            ...$short,
            'مانده: '.$tail,
        ]));
    }
}
