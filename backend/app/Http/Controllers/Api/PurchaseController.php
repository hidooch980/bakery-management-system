<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Deliveries, as invoices.
 *
 * The whole record is written in one transaction — the invoice, its lines,
 * the stock they bring in and the money that leaves. A delivery that
 * recorded its sacks and not its cost, or its cost and not its sacks, is
 * exactly the state this module exists to end, so there is no path here
 * that can produce half of one.
 */
class PurchaseController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::with(['supplier:id,name', 'user:id,name', 'items.item:id,key,name'])
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate(
                'purchased_on', '>=', Jalali::parseFlexible($d) ?? $d
            ))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate(
                'purchased_on', '<=', Jalali::parseFlexible($d) ?? $d
            ))
            ->latest('purchased_on')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Purchase $p) => $this->payload($p));

        return $this->success($purchases);
    }

    /**
     * What the person at the door has written down today.
     *
     * They may record a delivery and read back what they recorded, and
     * nothing else — not the account with the mill, not somebody else's
     * invoices. The same shape as every other «my history» here.
     */
    public function mine(Request $request): JsonResponse
    {
        $purchases = Purchase::with(['supplier:id,name', 'items.item:id,key,name'])
            ->where('user_id', $request->user()->id)
            ->latest('purchased_on')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Purchase $p) => $this->payload($p));

        return $this->success($purchases);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load(['supplier:id,name', 'user:id,name', 'items.item:id,key,name', 'payments']);

        return $this->success($this->payload($purchase));
    }

    /**
     * Everything the form needs to draw itself: who the shop buys from,
     * what it stocks and what one sack of each weighs, and which accounts
     * the money can come out of.
     *
     * One call rather than three, because the app opens this form standing
     * at a lorry with one bar of signal.
     */
    public function options(): JsonResponse
    {
        foreach (array_keys(InventoryItem::DEFAULTS) as $key) {
            InventoryItem::ofKey($key);
        }

        return $this->success([
            'suppliers' => Supplier::active()->orderBy('name')
                ->get(['id', 'name', 'kind'])
                ->map(fn (Supplier $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'kind' => $s->kind,
                ]),
            'items' => InventoryItem::all()->map(fn (InventoryItem $i) => [
                'id' => $i->id,
                'key' => $i->key,
                'name' => $i->name,
                'unit' => $i->unit,
                // Zero when the good has no fixed package, which is the
                // signal to ask for kilograms and refuse a sack count.
                'bag_weight_kg' => $i->bagWeightKg(),
            ]),
            'accounts' => BankAccount::where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'title', 'is_default'])
                ->map(fn (BankAccount $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'is_default' => $a->is_default,
                ]),
            'currency_label' => Money::label(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Either an existing supplier or a name to open one under. A
            // lorry at the door is not the moment to send somebody to a
            // different screen to add the mill first.
            'supplier_id' => ['required_without:supplier_name', 'nullable', 'exists:suppliers,id'],
            'supplier_name' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'invoice_no' => ['nullable', 'string', 'max:100'],
            'purchased_on' => ['nullable', 'string', 'max:20'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'paid_in_cash' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],

            'items' => ['required', 'array', 'min:1'],
            // A stocked good, or nothing at all for a line that is money
            // without goods — freight, unloading, the mill's commission.
            'items.*.item' => ['nullable', Rule::in(array_keys(InventoryItem::DEFAULTS))],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.bags' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invalid = $this->firstEmptyLine($data['items']);

        if ($invalid !== null) {
            return $this->error($invalid, 422);
        }

        $purchase = DB::transaction(function () use ($data, $request) {
            $supplierId = $data['supplier_id']
                ?? Supplier::firstOrCreate(['name' => trim($data['supplier_name'])])->id;

            $purchase = Purchase::create([
                'supplier_id' => $supplierId,
                'user_id' => $request->user()->id,
                'invoice_no' => $data['invoice_no'] ?? null,
                'purchased_on' => Jalali::parseFlexible($data['purchased_on'] ?? null) ?? now(),
                // Typed in whatever unit the shop is set to, stored in
                // Toman. A Rial shop that skipped this saved every cost
                // ten times over.
                'paid_amount' => Money::toToman($data['paid_amount'] ?? 0),
                'bank_account_id' => $this->accountFor($data),
                'note' => $data['note'] ?? null,
            ]);

            $this->writeLines($purchase, $data['items']);

            // The lines are in; the invoice total and the warehouse follow
            // from them. Both are the model's own doing, so the panel and
            // any other caller get exactly the same behaviour.
            $purchase->refreshTotals();

            return $purchase;
        });

        return $this->success(
            $this->payload($purchase->fresh(['supplier', 'items.item'])),
            'فاکتور خرید ثبت شد.',
            201
        );
    }

    /**
     * Corrects an invoice already filed.
     *
     * Lines given are the lines: the old ones go and these replace them,
     * because a correction is somebody reading the invoice again rather
     * than patching one row of it. The stock and the bank follow, which is
     * the whole point — this project has four bugs on record where a
     * record could be corrected and the goods it moved could not.
     */
    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['sometimes', 'exists:suppliers,id'],
            'invoice_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'purchased_on' => ['sometimes', 'string', 'max:20'],
            'paid_amount' => ['sometimes', 'numeric', 'min:0'],
            'bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],

            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item' => ['nullable', Rule::in(array_keys(InventoryItem::DEFAULTS))],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.bags' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['items'])) {
            $invalid = $this->firstEmptyLine($data['items']);

            if ($invalid !== null) {
                return $this->error($invalid, 422);
            }
        }

        DB::transaction(function () use ($data, $purchase) {
            $fields = collect($data)
                ->only(['supplier_id', 'invoice_no', 'bank_account_id', 'note'])
                ->all();

            if (array_key_exists('purchased_on', $data)) {
                $fields['purchased_on'] = Jalali::parseFlexible($data['purchased_on'])
                    ?? $purchase->purchased_on;
            }

            if (array_key_exists('paid_amount', $data)) {
                $fields['paid_amount'] = Money::toToman($data['paid_amount']);
            }

            if ($fields !== []) {
                $purchase->update($fields);
            }

            if (isset($data['items'])) {
                // Deleting through the relation fires each line's own
                // hooks, which is what keeps the totals honest — a
                // truncating query would leave the invoice claiming a sum
                // no line adds up to.
                $purchase->items()->get()->each->delete();
                $this->writeLines($purchase, $data['items']);
            }

            $purchase->refreshTotals();
        });

        return $this->success(
            $this->payload($purchase->fresh(['supplier', 'items.item', 'payments'])),
            'فاکتور خرید به‌روزرسانی شد.'
        );
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        if ($purchase->payments()->exists()) {
            return $this->error(
                'این فاکتور پرداخت ثبت‌شده دارد. اول پرداخت‌ها را حذف کنید.',
                409
            );
        }

        // The stock it brought in goes back and the bank posting goes with
        // it — see Purchase::booted() and PostsToBankAccount.
        $purchase->delete();

        return $this->success(null, 'فاکتور خرید حذف شد.');
    }

    /**
     * A line that names neither a good nor a title, or one that carries no
     * money at all, is not a line — and an invoice made of them would
     * total nothing while claiming a delivery happened.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function firstEmptyLine(array $items): ?string
    {
        foreach ($items as $index => $line) {
            $hasGoods = ! empty($line['item']);
            $hasTitle = filled($line['title'] ?? null);

            if (! $hasGoods && ! $hasTitle) {
                return 'ردیف '.($index + 1).' نه کالا دارد نه عنوان.';
            }

            $quantity = (float) ($line['bags'] ?? 0) + (float) ($line['quantity_kg'] ?? 0);
            $money = (float) ($line['amount'] ?? 0) + (float) ($line['unit_price'] ?? 0);

            if ($quantity <= 0 && $money <= 0) {
                return 'ردیف '.($index + 1).' نه مقدار دارد نه مبلغ.';
            }

            // A line with a rate and no quantity cannot produce a total,
            // and one with a quantity and no money is goods the shop did
            // not pay for — which is a consignment, not a purchase.
            if ($quantity > 0 && $money <= 0) {
                return 'ردیف '.($index + 1).' مبلغ ندارد.';
            }
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeLines(Purchase $purchase, array $items): void
    {
        foreach ($items as $line) {
            $item = ! empty($line['item']) ? InventoryItem::ofKey($line['item']) : null;

            $purchase->items()->create([
                'inventory_item_id' => $item?->id,
                'title' => $line['title'] ?? null,
                'bags' => $line['bags'] ?? null,
                'quantity_kg' => $line['quantity_kg'] ?? 0,
                'unit_price' => Money::toToman($line['unit_price'] ?? 0),
                'amount' => Money::toToman($line['amount'] ?? 0),
            ]);
        }
    }

    /**
     * Which account the money left, or null when it came out of the till.
     *
     * The same rule as an expense: the shop's own account is the
     * assumption, naming one overrides it, saying it was cash clears it.
     * An invoice paid nothing at the door names no account either, so a
     * zero payment posts nothing whichever way this answers.
     *
     * @param  array<string, mixed>  $data
     */
    private function accountFor(array $data): ?int
    {
        if (array_key_exists('bank_account_id', $data) && $data['bank_account_id'] !== null) {
            return (int) $data['bank_account_id'];
        }

        if (filter_var($data['paid_in_cash'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return BankAccount::defaultAccount()?->id;
    }

    private function payload(Purchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'supplier_id' => $purchase->supplier_id,
            'supplier_name' => $purchase->supplierLabel(),
            'invoice_no' => $purchase->invoice_no,
            'purchased_on' => $purchase->purchased_on?->toDateString(),
            'purchased_on_display' => AppCalendar::date($purchase->purchased_on),
            'amount' => Money::convert($purchase->amount),
            'amount_formatted' => $purchase->amount_formatted,
            'paid_amount' => Money::convert($purchase->paid_amount),
            'outstanding' => Money::convert($purchase->outstanding),
            'outstanding_formatted' => $purchase->outstanding_formatted,
            'is_settled' => $purchase->is_settled,
            'note' => $purchase->note,
            'user' => $purchase->relationLoaded('user') ? $purchase->user?->only(['id', 'name']) : null,
            'items' => $purchase->items->map(fn ($line) => [
                'id' => $line->id,
                'item_key' => $line->item?->key,
                'label' => $line->label,
                'bags' => $line->bags === null ? null : (float) $line->bags,
                'quantity_kg' => (float) $line->quantity_kg,
                'quantity_label' => $line->quantity_label,
                'unit_price' => Money::convert($line->unit_price),
                'amount' => Money::convert($line->amount),
                'amount_formatted' => $line->amount_formatted,
            ])->values(),
        ];
    }
}
