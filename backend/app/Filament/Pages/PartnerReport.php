<?php

namespace App\Filament\Pages;

use App\Models\ConsignmentFlour;
use App\Models\FlourPrice;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Money;
use App\Support\PartnerLedger;
use App\Support\PartnerPosition;
use App\Support\Qty;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Where the shop stands with each partner bakery, and every transfer
 * behind that figure.
 *
 * On 2026-09-03 the shop's store held 2,840 kg of flour while 4,160 kg
 * net was sitting with four partner bakeries — more flour out on loan
 * than in the building — and no screen anywhere said so. The issue centre
 * named two of the four; the consignment list showed six rows with no
 * notion that two of them were the same partner in opposite directions;
 * and nothing at all totalled it.
 *
 * The account, then, and its dealings under it: click a partner and the
 * transfers that make up his figure open beneath him. A total the owner
 * cannot take apart is a total he has to trust rather than check, and the
 * one thing this shop's history argues for is checking.
 *
 * Every number here comes from PartnerLedger, which is also what the
 * issue centre asks — one count, so the warning and the report cannot
 * quote him two different figures for the same sacks.
 */
class PartnerReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'گزارش‌ها';

    protected static ?string $navigationLabel = 'گزارش همکاران';

    protected static ?string $title = 'گزارش همکاران — آرد امانی';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.pages.partner-report';

    protected static ?string $slug = 'partner-report';

    /** The partner whose dealings are open, by PartnerLedger key. */
    public ?string $openPartner = null;

    /** @var Collection<int, PartnerPosition>|null */
    private ?Collection $cachedPositions = null;

    /** Clicking the same account again closes it. */
    public function toggle(string $key): void
    {
        $this->openPartner = $this->openPartner === $key ? null : $key;
    }

    /**
     * Read once per render. The view asks for the positions and then for
     * six totals derived from them, and a Livewire component is built
     * fresh on every request, so caching on the instance is both safe and
     * the difference between one query and seven.
     *
     * @return Collection<int, PartnerPosition>
     */
    public function positions(): Collection
    {
        return $this->cachedPositions ??= PartnerLedger::positions();
    }

    /**
     * Every transfer with one partner — settled ones too.
     *
     * The open rows are the debt; the settled ones are the reason to
     * believe the debt will come back, or the reason not to. A partner
     * who has returned every sack for a year is a different conversation
     * from one who has never returned any, and the position alone cannot
     * tell them apart.
     */
    public function dealings(PartnerPosition $partner): Collection
    {
        return ConsignmentFlour::query()
            ->when(
                $partner->customerId !== null,
                fn ($q) => $q->where('customer_id', $partner->customerId),
                fn ($q) => $q->whereNull('customer_id')->where('partner_name', $partner->key),
            )
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    /** Sacks out, before anything is set against them. */
    public function totalLent(): float
    {
        return round($this->positions()->sum(fn (PartnerPosition $p) => $p->bagsLent), 2);
    }

    /** Sacks of other bakeries' flour sitting in this shop's store. */
    public function totalBorrowed(): float
    {
        return round($this->positions()->sum(fn (PartnerPosition $p) => $p->bagsBorrowed), 2);
    }

    /** What is genuinely owed to the shop, netting each partner separately. */
    public function netOwedToShop(): float
    {
        return round($this->positions()
            ->filter(fn (PartnerPosition $p) => $p->shopIsOwed())
            ->sum(fn (PartnerPosition $p) => $p->netBags()), 2);
    }

    /** What the shop owes, likewise per partner. */
    public function netOwedByShop(): float
    {
        return round(abs($this->positions()
            ->filter(fn (PartnerPosition $p) => $p->shopOwes())
            ->sum(fn (PartnerPosition $p) => $p->netBags())), 2);
    }

    public function overdueCount(): int
    {
        return $this->positions()->filter(fn (PartnerPosition $p) => $p->isOverdue())->count();
    }

    /** How many of these partners the shop has no way of telephoning. */
    public function withoutPhoneCount(): int
    {
        return $this->positions()->filter(fn (PartnerPosition $p) => blank($p->phone))->count();
    }

    public function bags(float $value): string
    {
        return Qty::format($value, 1).' کیسه';
    }

    public function kg(float $bags): string
    {
        return Qty::format($bags * DoughFormula::fromBakery()->bagWeightKg, 0).' کیلوگرم';
    }

    /**
     * What those sacks are worth on the shop's own books.
     *
     * The recorded price is the quota price, which is a fraction of what
     * replacing the flour on the open market would cost — so this figure
     * understates the loss if the sacks never come back. Null when the
     * shop has never recorded a price, because a zero here would read as
     * «worth nothing» rather than «not known».
     */
    public function money(float $bags): ?string
    {
        $perKg = FlourPrice::current();

        if ($perKg === null) {
            return null;
        }

        return Money::format($bags * DoughFormula::fromBakery()->bagWeightKg * $perKg);
    }

    public function date($value): string
    {
        return AppCalendar::date($value);
    }
}
