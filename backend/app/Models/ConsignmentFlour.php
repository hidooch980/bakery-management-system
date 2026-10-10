<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\RecordsAudit;
use App\Support\DoughFormula;
use App\Support\Qty;
use App\Support\StockLedger;
use App\Support\StockReversal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Flour borrowed from, or lent to, a neighbouring bakery.
 *
 * The sack physically moves, so the warehouse moves with it — and it does so
 * from the model rather than a controller, because the panel, the API and any
 * future caller all have to agree. Recording it in one place and the
 * warehouse in another is how the two end up disagreeing.
 */
class ConsignmentFlour extends Model
{
    use BelongsToBakery, RecordsAudit;

    // "Flour" is uncountable, so Laravel would guess `consignment_flour`.
    protected $table = 'consignment_flours';

    public const DIRECTIONS = [
        'borrowed' => 'دریافتی از همکار',
        'lent' => 'تحویلی به همکار',
    ];

    protected $fillable = [
        'user_id',
        'customer_id',
        'partner_name',
        'partner_phone',
        'direction',
        'bags',
        'amount_kg',
        'occurred_on',
        'date_is_approximate',
        'settled_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'bags' => 'decimal:2',
            'amount_kg' => 'decimal:3',
            'occurred_on' => 'date',
            'date_is_approximate' => 'boolean',
            'settled_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Sacks are what changes hands, so the weight is derived from them
        // and the sack size in settings — never entered beside them, where
        // the two could disagree. A record given only a weight keeps it.
        static::saving(function (self $record) {
            $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

            if ($record->bags !== null && $bagWeight > 0) {
                $record->amount_kg = round((float) $record->bags * $bagWeight, 3);
            } elseif ($record->bags === null && $bagWeight > 0) {
                $record->bags = round((float) $record->amount_kg / $bagWeight, 2);
            }
        });

        // A phone typed on a transfer belongs to the partner, not to the
        // transfer: the next amanat is a different row and the number has
        // to still be there. Written up to the partner record when that
        // record has none, and never over one already on file — the
        // partner page is where a number gets corrected.
        static::saved(function (self $record) {
            $partner = $record->partner;

            if ($partner && blank($partner->phone) && filled($record->partner_phone)) {
                $partner->forceFill(['phone' => $record->partner_phone])->save();
            }
        });

        // Borrowed flour arrives in the store; lent flour leaves it.
        static::created(fn (self $record) => $record->moveStock(
            $record->direction === 'borrowed' ? 'in' : 'out',
            $record->direction === 'borrowed' ? 'consignment_in' : 'consignment_out',
            $record->partner_label,
        ));

        // Anything that changes what this record says about the flour —
        // settling it, correcting the sacks, or correcting which way they
        // went — is answered by making the warehouse agree with the row
        // again.
        //
        // It used to answer only settling, so editing a record moved
        // nothing: the ledger squared and the flour was somewhere else.
        // That is why the twelve sacks recorded as twelve kilograms had
        // to be deleted and rewritten rather than corrected, and it is
        // the same shape as three other bugs in this project — see the
        // note on the sale whose count could be corrected while the
        // charge stayed put.
        static::updated(function (self $record) {
            if ($record->wasChanged(['settled_on', 'bags', 'amount_kg', 'direction'])) {
                $record->reconcileStock();
            }
        });

        // Deleting the record has to take its stock movement with it, or the
        // warehouse keeps flour that nothing on file accounts for.
        static::deleted(fn (self $record) => StockReversal::of($record, 'ابطال آرد امانی'));
    }

    /**
     * Posts whatever it takes to make the warehouse agree with this row.
     *
     * The effect a consignment should have on the store is a fact about
     * its current state, not about the history of edits that got it
     * there: flour borrowed and not yet given back is in the store,
     * flour lent and not yet returned is out of it, and a settled record
     * has had both halves and nets to nothing.
     *
     * What it has *actually* moved is read from the ledger rather than
     * recomputed, the same way StockReversal reads it — a record made
     * before the model moved stock at all has moved nothing, and
     * inventing its history would conjure flour out of the air.
     *
     * One correcting movement, for the difference. Nothing is rewritten,
     * so the ledger still says what happened and when.
     */
    public function reconcileStock(): void
    {
        // Out of the store is the positive direction here, which is why
        // lending is the one that counts up: `StockLedger` speaks the same
        // language for every model that shares this rule.
        // آنچه بخش‌بخش برگشته از این مقدار کم می‌شود (جدول برگشت‌ها).
        $shouldBeOut = ($this->direction === 'borrowed' ? -1 : 1) * $this->outstandingKg();

        StockLedger::reconcile(
            $this,
            InventoryItem::ofKey(InventoryItem::FLOUR),
            $shouldBeOut,
            'consignment_return',
            'اصلاح آرد امانی — '.$this->partner_label,
            $this->user_id,
        );
    }

    /** Moves the warehouse by this record's weight, tagged back to it. */
    protected function moveStock(string $direction, string $reason, ?string $note = null, ?float $quantity = null): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move(
            $direction,
            $quantity ?? (float) $this->amount_kg,
            $reason,
            $this->user_id,
            $this,
            $note,
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** برگشت‌های بخشیِ این ردیف، به ترتیب تاریخ. */
    public function returns()
    {
        return $this->hasMany(ConsignmentFlourReturn::class)->orderBy('returned_on')->orderBy('id');
    }

    /** کیسه‌هایی که با «ثبت برگشت» پس آمده‌اند. */
    public function returnedBags(): float
    {
        $returns = $this->relationLoaded('returns') ? $this->returns : $this->returns()->get();

        return round((float) $returns->sum(fn (ConsignmentFlourReturn $r) => (float) $r->bags), 2);
    }

    /**
     * کیسه‌هایی که هنوز برنگشته‌اند.
     *
     * ردیفِ تسویه‌شده صفر است، چه با برگشت‌های بخشی تمام شده باشد و چه
     * با دکمهٔ قدیمی «ثبت تسویه» (که باقی‌مانده را یک‌جا برمی‌گرداند).
     */
    public function outstandingBags(): float
    {
        if ($this->settled_on !== null) {
            return 0.0;
        }

        return max(round((float) $this->bags - $this->returnedBags(), 2), 0.0);
    }

    /** وزنِ همان کیسه‌های برنگشته، به نسبتِ وزن ثبت‌شدهٔ ردیف. */
    public function outstandingKg(): float
    {
        $bags = (float) $this->bags;

        if ($this->settled_on !== null) {
            return 0.0;
        }

        if ($bags <= 0) {
            return (float) $this->amount_kg;
        }

        return round((float) $this->amount_kg * $this->outstandingBags() / $bags, 3);
    }

    /**
     * ثبت برگشتِ بخشی (یا همهٔ باقی‌مانده).
     *
     * اگر با این برگشت چیزی نماند، ردیف در همان تاریخ تسویه‌شده می‌شود؛
     * وگرنه فقط انبار به اندازهٔ همین برگشت جابه‌جا می‌شود.
     */
    public function recordReturn(float $bags, $returnedOn = null, ?string $note = null, ?int $userId = null): ConsignmentFlourReturn
    {
        $bags = round($bags, 2);
        $left = $this->outstandingBags();

        if ($bags <= 0) {
            throw new \InvalidArgumentException('تعداد کیسهٔ برگشتی باید بیشتر از صفر باشد.');
        }

        if ($bags > $left + 0.001) {
            throw new \InvalidArgumentException('بیشتر از باقی‌مانده ('.$left.' کیسه) نمی‌شود برگشت زد.');
        }

        $returnedOn = $returnedOn ? Carbon::parse($returnedOn) : now();

        $return = $this->returns()->create([
            'bags' => $bags,
            'returned_on' => $returnedOn->toDateString(),
            'note' => $note,
            'user_id' => $userId ?? auth()->id(),
        ]);

        $this->unsetRelation('returns');

        if ($left - $bags < 0.001) {
            // updated() همین ردیف انبار را هم‌حساب می‌کند.
            $this->update(['settled_on' => $returnedOn->toDateString()]);
        } else {
            $this->reconcileStock();
        }

        return $return;
    }

    /**
     * بعد از حذف یک برگشت: اگر تسویهٔ ردیف از همین برگشت آمده بود (همان
     * تاریخ)، ردیف دوباره باز می‌شود؛ در هر حال انبار هم‌حساب می‌شود.
     */
    public function afterReturnRemoved(?ConsignmentFlourReturn $removed = null): void
    {
        $this->unsetRelation('returns');

        if ($this->settled_on !== null && $removed !== null
            && $this->settled_on->toDateString() === $removed->returned_on?->toDateString()) {
            $this->update(['settled_on' => null]);

            return;
        }

        $this->reconcileStock();
    }

    public function partner()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** The defined partner's name, or the free-text one for older rows. */
    public function getPartnerLabelAttribute(): string
    {
        return $this->partner?->name ?? (string) $this->partner_name;
    }

    /**
     * "۵ کیسه • ۲۰۰ کیلوگرم" — the sack count leads because that is what
     * was counted at the door; the weight follows for the books.
     */
    public function getQuantityLabelAttribute(): string
    {
        // آرد فقط به کیسه.
        if ($this->bags === null) {
            return Qty::flourBags((float) $this->amount_kg);
        }

        return rtrim(rtrim(number_format((float) $this->bags, 2), '0'), '.').' کیسه';
    }

    public function scopeOutstanding($query)
    {
        return $query->whereNull('settled_on');
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->settled_on !== null;
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }

    /**
     * How this row names itself in the trail.
     *
     * The log outlives the record: once the row is gone its id points at
     * nothing, and this sentence is all that is left to argue from.
     */
    public function auditSubject(): ?string
    {
        return 'آرد امانی '.$this->partner_label.' — '.$this->bags.' کیسه';
    }
}
