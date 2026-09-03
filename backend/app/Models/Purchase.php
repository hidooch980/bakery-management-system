<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use App\Models\Concerns\PostsToBankAccount;
use App\Models\Concerns\RecordsAudit;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\StockLedger;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery, as one record.
 *
 * A lorry of flour used to become three rows that knew nothing of each
 * other: an expense for the money, an inventory movement for the sacks,
 * and a dated price for the rate. Any one could be forgotten, none said
 * who delivered it, and the shop's oldest question — «به کارخانه چقدر
 * بدهکاریم» — had no answer anywhere in the system.
 *
 * Here the lines are the invoice. The warehouse follows them, the bank
 * follows what was handed over, and the difference is a debt with a name
 * on it.
 *
 * Three rules this project has already paid for are kept deliberately:
 *
 *  - The total is derived from the lines, never typed beside them, so the
 *    parts and the sum cannot disagree — the shape of every ten-times
 *    error this shop has carried.
 *  - The warehouse is reconciled from the record's *current* state rather
 *    than patched on create, so correcting a line moves the flour. Four
 *    bugs of the opposite shape are documented on StockLedger, and one of
 *    them cost 400 kg.
 *  - The money moves through PostsToBankAccount, which rebuilds the
 *    posting on every save. Three payments in one day once recorded their
 *    cost and moved no balance.
 */
class Purchase extends Model
{
    use BelongsToBakery, PostsToBankAccount, RecordsAudit;

    protected $fillable = [
        'supplier_id',
        'user_id',
        'invoice_no',
        'purchased_on',
        'paid_amount',
        'bank_account_id',
        'note',
    ];

    /**
     * `amount` is absent from the fillable list on purpose: it is the sum
     * of the lines, written by refreshTotals() and by nothing else.
     */
    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // The lines are removed by the database's own cascade, which fires
        // no model events — so the stock they brought in has to be given
        // back here, while the lines can still be read.
        static::deleting(fn (self $purchase) => $purchase->reverseStock());
    }

    // --------------------------------------------------------- relations

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    // --------------------------------------------------------- the total

    /**
     * Re-reads the lines and makes the record agree with them: the invoice
     * total, and the warehouse.
     *
     * Called after the lines are written rather than from a model hook,
     * because on a new purchase the lines cannot exist until the row they
     * hang off does.
     */
    public function refreshTotals(): void
    {
        $amount = round((float) $this->items()->sum('amount'), 2);

        if (abs((float) $this->amount - $amount) >= 0.01) {
            $this->amount = $amount;
            // A full save rather than a quiet one: the total is a money
            // figure, and a money figure that changes has to say who
            // changed it.
            $this->save();
        }

        $this->reconcileStock();
        $this->recordFlourPrice();
    }

    // --------------------------------------------------------- the price

    /**
     * A flour line states what the shop is paying per kilo, which is
     * exactly what the cost of goods needs to charge a bake.
     *
     * That figure used to be typed twice — once on the expense and once on
     * its own screen — and the copy that was forgotten is the one the
     * profit statement reads. Here the invoice sets it.
     *
     * Three rules, and each is there for a reason:
     *
     *  - Only the flour lines, averaged by weight. An invoice with flour
     *    at two rates paid one blended price for the load, and that is
     *    what the bread cost.
     *  - A rate this invoice set before is updated, so adding a second
     *    flour line re-averages instead of leaving the first line's rate
     *    standing.
     *  - A price already on file for that day that no invoice set is left
     *    alone. The owner typed it, and a delivery is not grounds for
     *    overruling him.
     */
    private function recordFlourPrice(): void
    {
        $flour = InventoryItem::where('key', InventoryItem::FLOUR)->first();

        if (! $flour || $this->purchased_on === null) {
            return;
        }

        $lines = $this->items()->where('inventory_item_id', $flour->getKey())->get();

        $kg = (float) $lines->sum('quantity_kg');
        $spent = (float) $lines->sum('amount');

        if ($kg <= 0 || $spent <= 0) {
            return;
        }

        $rate = round($spent / $kg, 2);
        $day = $this->purchased_on->toDateString();

        $mine = FlourPrice::where('purchase_id', $this->getKey())->first();

        if ($mine) {
            $mine->update(['price_per_kg' => $rate, 'effective_from' => $day]);

            return;
        }

        $typedByHand = FlourPrice::whereDate('effective_from', $day)
            ->whereNull('purchase_id')
            ->exists();

        if ($typedByHand) {
            return;
        }

        FlourPrice::create([
            'purchase_id' => $this->getKey(),
            'price_per_kg' => $rate,
            'effective_from' => $day,
            'note' => 'از فاکتور خرید — '.$this->supplierLabel(),
        ]);
    }

    // --------------------------------------------------------- the goods

    /**
     * Posts whatever it takes to leave the warehouse agreeing with this
     * invoice, one correcting movement per good.
     *
     * What the record *should* have brought in is a fact about its lines
     * as they stand now. What it *has* brought in is read from the ledger
     * rather than recomputed, so a line edited today does not rewrite what
     * a sack did last week — it posts the difference and nothing else.
     */
    public function reconcileStock(): void
    {
        $wanted = $this->stockByItem();

        // Every good this invoice has *ever* moved, not only the ones on
        // it now. A line that has just been deleted is not in `$wanted`,
        // and reading only `$wanted` would leave its sacks in the store
        // with nothing on file accounting for them — the exact bug shape
        // StockLedger was written to end.
        foreach ($this->goodsTouched($wanted) as $itemId) {
            $item = InventoryItem::find($itemId);

            if (! $item) {
                continue;
            }

            // Out of the store is the positive direction StockLedger
            // speaks in, and a purchase goes the other way.
            $this->reconcileItem($item, -($wanted[$itemId] ?? 0.0));
        }
    }

    /** Gives back everything this invoice ever brought in. */
    public function reverseStock(): void
    {
        foreach ($this->goodsTouched($this->stockByItem()) as $itemId) {
            $item = InventoryItem::find($itemId);

            if ($item) {
                $this->reconcileItem($item, 0.0);
            }
        }
    }

    /**
     * The goods on this invoice now, plus any it has moved before.
     *
     * @param  array<int, float>  $wanted
     * @return list<int>
     */
    private function goodsTouched(array $wanted): array
    {
        $moved = InventoryMovement::query()
            ->where('source_type', static::class)
            ->where('source_id', $this->getKey())
            ->pluck('inventory_item_id')
            ->all();

        return array_values(array_unique(array_map(
            'intval',
            array_merge(array_keys($wanted), $moved),
        )));
    }

    /**
     * Kilograms this invoice says arrived, per stocked good.
     *
     * Lines with no good — freight, unloading, the mill's own commission —
     * are money and never reach the warehouse, so they are absent here and
     * present in the total.
     */
    private function stockByItem(): array
    {
        $byItem = [];

        foreach ($this->items()->whereNotNull('inventory_item_id')->get() as $line) {
            $id = (int) $line->inventory_item_id;
            $byItem[$id] = ($byItem[$id] ?? 0.0) + (float) $line->quantity_kg;
        }

        return array_map(fn ($kg) => round($kg, 3), $byItem);
    }

    private function reconcileItem(InventoryItem $item, float $shouldBeOut): void
    {
        // A correction that takes stock back out is not a purchase, and
        // labelling it one would put «خرید» on an outgoing line in the
        // ledger the owner reads.
        $isGivingBack = $shouldBeOut > StockLedger::netMoved($this, $item->getKey());

        StockLedger::reconcile(
            $this,
            $item,
            $shouldBeOut,
            $isGivingBack ? 'purchase_reversal' : 'purchase',
            'خرید — '.$this->supplierLabel(),
            $this->user_id,
        );
    }

    // ----------------------------------------------------- bank posting

    public function bankPostingAccountId(): ?int
    {
        return $this->bank_account_id;
    }

    /** What was handed over at the door; the rest is a debt, not a payment. */
    public function bankPostingAmount(): float
    {
        return (float) $this->paid_amount;
    }

    public function bankPostingReason(): string
    {
        return 'purchase';
    }

    public function bankPostingDate()
    {
        return $this->purchased_on ?? now();
    }

    // ---------------------------------------------------------- readings

    public function supplierLabel(): string
    {
        return $this->supplier?->name ?? 'تأمین‌کننده نامشخص';
    }

    /** Invoiced, less handed over at the door, less paid on account since. */
    public function getOutstandingAttribute(): float
    {
        return round(
            (float) $this->amount
            - (float) $this->paid_amount
            - (float) $this->payments()->sum('amount'),
            2
        );
    }

    public function getIsSettledAttribute(): bool
    {
        return $this->outstanding < 0.01;
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount);
    }

    public function getOutstandingFormattedAttribute(): string
    {
        return Money::format($this->outstanding);
    }

    public function getPurchasedOnJalaliAttribute(): ?string
    {
        return Jalali::date($this->purchased_on);
    }
}
