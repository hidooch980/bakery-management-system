<?php

namespace App\Support;

use App\Models\ConsignmentFlour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One partner bakery's open consignment position — what is out, what came
 * the other way, and what is genuinely owed.
 *
 * Sacks, throughout. Sacks are what changes hands at the door and what
 * either party would say out loud; the weight is for the books and is
 * derived from the shop's sack size wherever it is needed.
 */
class PartnerPosition
{
    public function __construct(
        /** Partner id where one is defined, else the free-text name. */
        public readonly string $key,
        public readonly ?int $customerId,
        public readonly string $name,
        public readonly ?string $phone,
        /** Sacks of the shop's flour sitting with this partner. */
        public readonly float $bagsLent,
        /** Sacks of theirs sitting in the shop's store. */
        public readonly float $bagsBorrowed,
        public readonly int $lendingCount,
        public readonly int $borrowingCount,
        public readonly ?Carbon $oldestLentOn,
        /** True when a handover date on file is the day it was typed in. */
        public readonly bool $dateIsApproximate,
        /** @var Collection<int, ConsignmentFlour> */
        public readonly Collection $records,
    ) {}

    /**
     * Sacks owed to the shop once what it borrowed is set against what it
     * lent. Negative means the shop is the one who owes.
     */
    public function netBags(): float
    {
        return round($this->bagsLent - $this->bagsBorrowed, 2);
    }

    public function netKg(): float
    {
        return round($this->netBags() * DoughFormula::fromBakery()->bagWeightKg, 3);
    }

    public function shopIsOwed(): bool
    {
        return $this->netBags() > 0.001;
    }

    public function shopOwes(): bool
    {
        return $this->netBags() < -0.001;
    }

    /** Days since the oldest sack went out, or null with nothing out. */
    public function daysOut(): ?int
    {
        return $this->oldestLentOn ? (int) $this->oldestLentOn->diffInDays(now()) : null;
    }

    /**
     * Whether this is flour to chase rather than the ordinary back and
     * forth of a week.
     *
     * A partner whose date is a guess is chased at once. Waiting a
     * fortnight from a date that means nothing is how the oldest debt in
     * the shop stays quiet the longest — see the migration that added the
     * flag.
     */
    public function isOverdue(): bool
    {
        if (! $this->shopIsOwed() || $this->oldestLentOn === null) {
            return false;
        }

        if ($this->dateIsApproximate) {
            return true;
        }

        return $this->oldestLentOn->lte(now()->subDays(PartnerLedger::CHASE_AFTER_DAYS));
    }

    /** «۵۶ کیسه در ۲ نوبت» — how the sacks left, for the owner to check. */
    public function lentLabel(): string
    {
        return Qty::format($this->bagsLent, 1).' کیسه در '.$this->lendingCount.' نوبت';
    }

    /**
     * The age, said the way it is known: a day when there is one, and an
     * admission when there is not.
     */
    public function ageLabel(): string
    {
        if ($this->oldestLentOn === null) {
            return '—';
        }

        if ($this->dateIsApproximate) {
            return 'تاریخ تحویل نامعلوم — دست‌کم از '
                .AppCalendar::date($this->oldestLentOn);
        }

        return 'قدیمی‌ترین '.$this->daysOut().' روز پیش ('
            .AppCalendar::date($this->oldestLentOn).')';
    }

    /** What the netting did, said only when it actually changed the figure. */
    public function offsetLabel(): ?string
    {
        if ($this->bagsBorrowed <= 0.001) {
            return null;
        }

        return $this->lentLabel().' تحویلی، منهای '
            .Qty::format($this->bagsBorrowed, 1).' کیسه دریافتی از همین همکار';
    }
}
