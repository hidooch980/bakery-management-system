<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use App\Support\PartnerLedger;
use App\Support\PartnerNetting;
use App\Support\PartnerPosition;
use App\Support\PartnerStatement;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsignmentFlourController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        // «باز» یعنی بعد از خالص شدنِ خودکار با ثبت‌های طرف مقابل.
        $open = PartnerNetting::open(ConsignmentFlour::query()->with('returns')->get());

        $records = ConsignmentFlour::with(['user:id,name', 'partner:id,name', 'returns'])
            ->when($request->query('direction'), fn ($q, $d) => $q->where('direction', $d))
            ->when($request->boolean('outstanding_only'), fn ($q) => $q->whereIn(
                'id',
                array_keys(array_filter($open, fn (float $bags) => $bags > 0.001)) ?: [0],
            ))
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(20)
            ->through(fn (ConsignmentFlour $c) => $this->payload($c, $open[$c->id] ?? 0.0));

        return $this->success($records);
    }

    /**
     * The same flour, gathered by the person holding it.
     *
     * «همه اسما باشه»: همهٔ همکاران، حتی آن‌که حسابش صاف است یا هنوز ثبتی
     * ندارد — به ترتیب مانده (طلب ما بالا) و بعد نام. مانده‌ها خودکارند:
     * هر ثبتِ طرف مقابل از قدیمی‌ترین ماندهٔ باز کم می‌شود (PartnerNetting).
     */
    public function partners(): JsonResponse
    {
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        $rows = PartnerLedger::all()->map(function (PartnerPosition $p) use ($bagWeight) {
            // The oldest still-open row is the one worth chasing, so the
            // age of the account is the age of that row and not an
            // average.
            $oldest = $p->oldestOpenOn();

            return [
                'partner_id' => $p->customerId,
                'partner_name' => $p->name,
                'lent_kg' => round($p->bagsLent * $bagWeight, 3),
                'borrowed_kg' => round($p->bagsBorrowed * $bagWeight, 3),
                'net_kg' => round($p->netBags() * $bagWeight, 3),
                'lent_bags' => $p->bagsLent,
                'borrowed_bags' => $p->bagsBorrowed,
                'net_bags' => $p->netBags(),
                'headline' => PartnerStatement::headline($p->netBags()),
                'is_settled' => abs($p->netBags()) <= 0.001,
                'entries' => $p->entries,
                'since' => $oldest?->toDateString(),
                'since_display' => $oldest ? AppCalendar::date($oldest) : null,
                // Whole days, so «امروز» is 0 rather than a fraction.
                'days' => $oldest
                    ? (int) $oldest->copy()->startOfDay()->diffInDays(now()->startOfDay())
                    : null,
            ];
        })->values();

        return $this->success($rows);
    }

    /** Net position: how much flour we owe partners, and they owe us. */
    public function balance(): JsonResponse
    {
        $positions = PartnerLedger::all();
        $lentBags = round((float) $positions->sum(fn (PartnerPosition $p) => $p->bagsLent), 2);
        $borrowedBags = round((float) $positions->sum(fn (PartnerPosition $p) => $p->bagsBorrowed), 2);

        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        return $this->success([
            'borrowed_kg' => round($borrowedBags * $bagWeight, 3),
            'lent_kg' => round($lentBags * $bagWeight, 3),
            // Positive means partners owe us; negative means we owe them.
            'net_kg' => round(($lentBags - $borrowedBags) * $bagWeight, 3),
            'borrowed_bags' => $borrowedBags,
            'lent_bags' => $lentBags,
            'net_bags' => round($lentBags - $borrowedBags, 2),
            'bag_weight_kg' => $bagWeight,
        ] + self::partnerTotals());
    }

    /**
     * جمع طلب و بدهی، همکار به همکار خالص‌شده — همان سه عددِ ویجت
     * داشبورد پنل، برای صفحهٔ خانهٔ اپ.
     */
    private static function partnerTotals(): array
    {
        $totals = PartnerStatement::totals();

        return [
            'owed_to_us_bags' => $totals['owed_to_us'],
            'we_owe_bags' => $totals['we_owe'],
            'partners_net_bags' => $totals['net'],
            'partners_owing' => $totals['partners_owing'],
            'partners_owed' => $totals['partners_owed'],
            'headline' => PartnerStatement::headline($totals['net']),
        ];
    }

    /**
     * گردش ریز یک همکار، به کیسه. «from» و «to» اختیاری‌اند و شمسی یا
     * میلادی هر دو پذیرفته می‌شوند.
     */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->type !== Customer::PARTNER_TYPE) {
            return $this->error('این طرف‌حساب همکار نیست.', 404);
        }

        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:20'],
            'to' => ['nullable', 'string', 'max:20'],
        ]);

        $statement = PartnerStatement::for(
            $customer,
            Jalali::parseFlexible($data['from'] ?? null),
            Jalali::parseFlexible($data['to'] ?? null),
        );

        return $this->success($statement->toArray());
    }

    /**
     * ثبت برگشت بخشی — فقط برای نسخه‌های قدیمی اپ نگه داشته شده. اپ و پنل
     * دیگر چنین دکمه‌ای ندارند: ثبتِ عادیِ دادیم/گرفتیم خودش خالص می‌شود.
     */
    public function storeReturn(Request $request, ConsignmentFlour $consignment): JsonResponse
    {
        $data = $request->validate([
            'bags' => ['required', 'numeric', 'min:0.01'],
            'returned_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($consignment->is_settled) {
            return $this->error('این مورد قبلاً تسویه شده است.', 409);
        }

        try {
            DB::transaction(fn () => $consignment->recordReturn(
                (float) $data['bags'],
                Jalali::parseFlexible($data['returned_on'] ?? null) ?? now(),
                $data['note'] ?? null,
                $request->user()->id,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->payload($consignment->fresh()), 'برگشت ثبت شد.', 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Either a defined partner, or a one-off name.
            'customer_id' => ['nullable', 'exists:customers,id'],
            'partner_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'partner_phone' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'in:borrowed,lent'],
            // Sacks are what changes hands. A weight is still accepted for
            // anything that was genuinely weighed out rather than counted.
            'bags' => ['required_without:amount_kg', 'nullable', 'numeric', 'min:0.01'],
            'amount_kg' => ['required_without:bags', 'nullable', 'numeric', 'min:0.001'],
            'occurred_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DB::transaction(function () use ($data, $request) {
            $record = ConsignmentFlour::create([
                'user_id' => $request->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'partner_name' => $data['partner_name'] ?? null,
                'partner_phone' => $data['partner_phone'] ?? null,
                'direction' => $data['direction'],
                'bags' => $data['bags'] ?? null,
                'amount_kg' => $data['amount_kg'] ?? 0,
                'occurred_on' => Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now(),
                'note' => $data['note'] ?? null,
            ]);

            // The warehouse movement is the model's own doing, so the panel
            // and any other caller get it too — see ConsignmentFlour::booted().

            return $record;
        });

        return $this->success($this->payload($record), 'آرد امانی ثبت شد.', 201);
    }

    /**
     * «تسویه شد» — فقط برای نسخه‌های قدیمی اپ که هنوز این دکمه را دارند.
     * اپ و پنل جدید دکمهٔ تسویه ندارند؛ مانده‌ها خودکارند.
     */
    public function settle(ConsignmentFlour $consignment): JsonResponse
    {
        if ($consignment->is_settled) {
            return $this->error('این مورد قبلاً تسویه شده است.', 409);
        }

        $consignment->update(['settled_on' => now()]);

        return $this->success($this->payload($consignment->fresh()), 'تسویه ثبت شد.');
    }

    public function destroy(ConsignmentFlour $consignment): JsonResponse
    {
        $consignment->delete();

        return $this->success(null, 'رکورد حذف شد.');
    }

    private function payload(ConsignmentFlour $record, ?float $open = null): array
    {
        $open ??= PartnerNetting::openOf($record);

        return [
            'id' => $record->id,
            'partner_id' => $record->customer_id,
            'partner_name' => $record->partner_label,
            'partner_phone' => $record->partner_phone,
            'direction' => $record->direction,
            'direction_label' => $record->direction_label,
            'bags' => (float) $record->bags,
            'amount_kg' => (float) $record->amount_kg,
            'quantity_label' => $record->quantity_label,
            'occurred_on' => $record->occurred_on?->toDateString(),
            'occurred_on_display' => AppCalendar::date($record->occurred_on),
            'settled_on_display' => AppCalendar::date($record->settled_on),
            // بسته = خالص شده (خودکار) یا تسویهٔ قدیمی. نسخه‌های قدیمی اپ
            // دکمهٔ «تسویه شد» را فقط برای ردیف‌های باز نشان می‌دهند.
            'is_settled' => $open <= 0.001,
            'returned_bags' => $record->returnedBags(),
            'outstanding_bags' => round($open, 2),
            'note' => $record->note,
        ];
    }
}
