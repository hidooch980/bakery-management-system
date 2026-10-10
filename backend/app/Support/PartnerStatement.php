<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use App\Models\ConsignmentFlourReturn;
use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * گردش ریز آرد امانیِ یک همکار — به کیسه، بدون کیلو و بدون پول.
 *
 * هر ردیف آرد امانی یک رویداد «دادیم» یا «گرفتیم» در تاریخ خودش می‌سازد؛
 * هر برگشت بخشی یک رویداد «پس گرفتیم» یا «پس دادیم» در تاریخ برگشت؛ و
 * اگر ردیف تسویه شده و چیزی از آن هنوز برگشت نخورده، باقی‌مانده یک
 * رویداد برگشت در تاریخ تسویه است (ردیف‌های قدیمی همین‌طور «برگشت کامل»
 * خوانده می‌شوند).
 *
 * مانده مثبت یعنی طلب ما (آرد ما نزد همکار است)، منفی یعنی بدهی ما.
 *
 * ترتیب در یک روز، همان‌که صاحب مغازه در طرح تأیید کرد: اول برگشت‌ها، و
 * میان آن‌ها اول آن‌که مانده را بالا می‌برد؛ بعد ثبت‌های تازه. وگرنه
 * مانده وسط روز بی‌دلیل منفی دیده می‌شود.
 */
class PartnerStatement
{
    public const LENT = 'lent';

    public const BORROWED = 'borrowed';

    public const RETURNED_TO_US = 'returned_to_us';

    public const RETURNED_BY_US = 'returned_by_us';

    public const LABELS = [
        self::LENT => 'دادیم',
        self::BORROWED => 'گرفتیم',
        self::RETURNED_TO_US => 'پس گرفتیم',
        self::RETURNED_BY_US => 'پس دادیم',
    ];

    /**
     * @param  Collection<int, array>  $rows
     */
    public function __construct(
        public readonly Customer $partner,
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly float $opening,
        public readonly Collection $rows,
        public readonly float $closing,
        public readonly float $current,
    ) {}

    public static function for(Customer $partner, ?Carbon $from = null, ?Carbon $to = null): self
    {
        $all = self::events($partner);

        $from = $from?->copy()->startOfDay();
        $to = $to?->copy()->endOfDay();

        $opening = 0.0;
        $rows = collect();
        $balance = 0.0;

        foreach ($all as $event) {
            $balance = round($balance + $event['delta'], 2);
            $event['balance'] = $balance;

            if ($from && $event['date']->lt($from)) {
                $opening = $balance;

                continue;
            }

            if ($to && $event['date']->gt($to)) {
                continue;
            }

            $rows->push($event);
        }

        $closing = $rows->isEmpty() ? $opening : (float) $rows->last()['balance'];

        return new self($partner, $from, $to, $opening, $rows->values(), $closing, $balance);
    }

    /** ماندهٔ امروزِ یک همکار، به کیسه. */
    public static function balanceOf(Customer $partner): float
    {
        return round((float) self::events($partner)->sum('delta'), 2);
    }

    /**
     * همهٔ رویدادهای یک همکار، مرتب، بدون مانده.
     *
     * @return Collection<int, array>
     */
    public static function events(Customer $partner): Collection
    {
        $records = ConsignmentFlour::query()
            ->where('customer_id', $partner->getKey())
            ->with(['returns', 'user:id,name', 'returns.user:id,name'])
            ->get();

        $events = collect();

        foreach ($records as $record) {
            $lent = $record->direction === 'lent';
            $bags = round((float) $record->bags, 2);
            $what = '«'.self::LABELS[$lent ? self::LENT : self::BORROWED].' '.self::bags($bags)
                .' کیسه» مورخ '.AppCalendar::date($record->occurred_on);

            $events->push([
                'date' => $record->occurred_on->copy()->startOfDay(),
                'kind' => $lent ? self::LENT : self::BORROWED,
                'label' => self::LABELS[$lent ? self::LENT : self::BORROWED],
                'delta' => $lent ? $bags : -$bags,
                'note' => (string) $record->note,
                'approximate' => (bool) $record->date_is_approximate,
                'user' => $record->user?->name,
                'record_id' => $record->id,
                'is_return' => false,
                'seq' => 0,
            ]);

            $returned = 0.0;

            foreach ($record->returns as $return) {
                /** @var ConsignmentFlourReturn $return */
                $part = round((float) $return->bags, 2);
                $returned += $part;
                $whole = abs($returned - $bags) < 0.001 && $record->returns->count() === 1;

                $events->push(self::returnEvent(
                    $record,
                    $return->returned_on,
                    $part,
                    trim(($whole ? 'برگشت کامل ' : 'برگشت بخشی از ').$what
                        .($return->note ? ' — '.$return->note : '')),
                    $return->user?->name,
                    $return->id,
                ));
            }

            $rest = round($bags - $returned, 2);

            if ($record->settled_on !== null && $rest > 0.001) {
                $events->push(self::returnEvent(
                    $record,
                    $record->settled_on,
                    $rest,
                    ($returned > 0 ? 'برگشت باقی‌ماندهٔ ' : 'برگشت کامل ').$what,
                    null,
                    PHP_INT_MAX,
                ));
            }
        }

        return $events
            ->sort(function (array $a, array $b) {
                return [$a['date']->timestamp, $b['is_return'], $b['delta'], $a['record_id'], $a['seq']]
                    <=> [$b['date']->timestamp, $a['is_return'], $a['delta'], $b['record_id'], $b['seq']];
            })
            ->values();
    }

    private static function returnEvent(ConsignmentFlour $record, $date, float $bags, string $note, ?string $user, int $seq): array
    {
        $lent = $record->direction === 'lent';

        return [
            'date' => Carbon::parse($date)->startOfDay(),
            'kind' => $lent ? self::RETURNED_TO_US : self::RETURNED_BY_US,
            'label' => self::LABELS[$lent ? self::RETURNED_TO_US : self::RETURNED_BY_US],
            'delta' => $lent ? -$bags : $bags,
            'note' => $note,
            'approximate' => false,
            'user' => $user,
            'record_id' => $record->id,
            'is_return' => true,
            'seq' => $seq,
        ];
    }

    /** جمع هر نوع در همین بازه، به کیسه (همیشه مثبت). */
    public function total(string $kind): float
    {
        return round((float) $this->rows->where('kind', $kind)->sum(fn ($r) => abs($r['delta'])), 2);
    }

    public function totalReturns(): float
    {
        return round($this->total(self::RETURNED_TO_US) + $this->total(self::RETURNED_BY_US), 2);
    }

    public function lastActivity(): ?Carbon
    {
        return self::events($this->partner)->max('date');
    }

    /**
     * سرخط مانده: «طلب ما: ۱۰ کیسه»، «بدهی ما: ۳ کیسه» یا «تسویه».
     *
     * @return array{label: string, tone: string}
     */
    public static function headline(float $balance): array
    {
        if ($balance > 0.001) {
            return ['label' => 'طلب ما: '.self::bags($balance).' کیسه', 'tone' => 'owed'];
        }

        if ($balance < -0.001) {
            return ['label' => 'بدهی ما: '.self::bags($balance).' کیسه', 'tone' => 'owes'];
        }

        return ['label' => 'تسویه', 'tone' => 'settled'];
    }

    /** «۱۲»، «۴۶.۵» — بی‌صفرِ اضافه و بی‌علامت. */
    public static function bags(float $value): string
    {
        $text = rtrim(rtrim(number_format(abs($value), 2, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /** با علامت: «+۱۲»، «−۸»، «0». */
    public static function signed(float $value): string
    {
        if (abs($value) < 0.001) {
            return '0';
        }

        return ($value > 0 ? '+' : '−').self::bags($value);
    }

    /**
     * جمع طلب و بدهی همهٔ همکاران، برای ویجت داشبورد و صفحهٔ خانهٔ اپ.
     *
     * @return array{owed_to_us: float, we_owe: float, net: float, partners_owing: int, partners_owed: int}
     */
    public static function totals(): array
    {
        $balances = Customer::query()->partners()->get()
            ->map(fn (Customer $c) => self::balanceOf($c));

        $owed = round((float) $balances->filter(fn ($b) => $b > 0.001)->sum(), 2);
        $owe = round((float) -$balances->filter(fn ($b) => $b < -0.001)->sum(), 2);

        return [
            'owed_to_us' => $owed,
            'we_owe' => $owe,
            'net' => round($owed - $owe, 2),
            'partners_owing' => $balances->filter(fn ($b) => $b > 0.001)->count(),
            'partners_owed' => $balances->filter(fn ($b) => $b < -0.001)->count(),
        ];
    }

    /**
     * خروجی CSV با BOM، تا اکسل فارسی را درست باز کند.
     */
    public function toCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['پروندهٔ همکار', $this->partner->name]);
        fputcsv($out, ['از تاریخ', $this->from ? AppCalendar::date($this->from) : '—', 'تا تاریخ', $this->to ? AppCalendar::date($this->to) : '—']);
        fputcsv($out, []);
        fputcsv($out, ['تاریخ', 'نوع', 'کیسه', 'شرح', 'تاریخ تقریبی', 'ثبت‌کننده', 'مانده پس از ردیف (کیسه)']);
        fputcsv($out, ['', 'ماندهٔ اول دوره', '', '', '', '', self::signed($this->opening)]);

        foreach ($this->rows as $row) {
            fputcsv($out, [
                AppCalendar::date($row['date']),
                $row['label'],
                self::signed($row['delta']),
                $row['note'],
                $row['approximate'] ? 'بله' : '',
                $row['user'] ?? '',
                self::signed($row['balance']),
            ]);
        }

        fputcsv($out, []);
        fputcsv($out, ['جمع دادیم', self::bags($this->total(self::LENT))]);
        fputcsv($out, ['جمع گرفتیم', self::bags($this->total(self::BORROWED))]);
        fputcsv($out, ['جمع پس گرفتیم', self::bags($this->total(self::RETURNED_TO_US))]);
        fputcsv($out, ['جمع پس دادیم', self::bags($this->total(self::RETURNED_BY_US))]);
        fputcsv($out, ['ماندهٔ پایان دوره', self::headline($this->closing)['label']]);
        fputcsv($out, ['ماندهٔ فعلی', self::headline($this->current)['label']]);

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** برای API و اپ. */
    public function toArray(): array
    {
        return [
            'partner' => [
                'id' => $this->partner->id,
                'name' => $this->partner->name,
                'phone' => $this->partner->phone,
            ],
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'from_display' => $this->from ? AppCalendar::date($this->from) : null,
            'to_display' => $this->to ? AppCalendar::date($this->to) : null,
            'opening_bags' => $this->opening,
            'closing_bags' => $this->closing,
            'current_bags' => $this->current,
            'headline' => self::headline($this->current),
            'totals' => [
                'lent_bags' => $this->total(self::LENT),
                'borrowed_bags' => $this->total(self::BORROWED),
                'returned_to_us_bags' => $this->total(self::RETURNED_TO_US),
                'returned_by_us_bags' => $this->total(self::RETURNED_BY_US),
                'returns_bags' => $this->totalReturns(),
            ],
            'rows' => $this->rows->map(fn (array $row) => [
                'date' => $row['date']->toDateString(),
                'date_display' => AppCalendar::date($row['date']),
                'kind' => $row['kind'],
                'label' => $row['label'],
                'bags' => $row['delta'],
                'note' => $row['note'],
                'approximate' => $row['approximate'],
                'user' => $row['user'],
                'record_id' => $row['record_id'],
                'balance_bags' => $row['balance'],
            ])->values()->all(),
        ];
    }
}
