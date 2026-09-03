<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who the shop buys from, and what it owes each of them.
 *
 * Reading the list is open to whoever may record a delivery — a picker
 * with no names in it cannot be used. The balances, and the right to add
 * or change a supplier, belong to whoever holds the money.
 */
class SupplierController extends Controller
{
    use ApiResponse;

    /**
     * The picker.
     *
     * Deactivated suppliers are left out unless asked for. A mill the shop
     * stopped using two years ago in a list of four is how the wrong one
     * gets tapped — and this project has already had a bakery vanish from
     * a dropdown because a filter hid it, so the opposite mistake is
     * guarded too: `all=1` returns everything.
     */
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->when(! $request->boolean('all'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'phone' => $s->phone,
                'kind' => $s->kind,
                'is_active' => $s->is_active,
            ]);

        return $this->success($suppliers);
    }

    /**
     * Every supplier with what stands between them and the shop.
     *
     * The one screen the owner asked for by name. Settled accounts are
     * kept out: a list that grows for ever stops being read, and a mill
     * that is square is not a thing to do today.
     */
    public function balances(): JsonResponse
    {
        $suppliers = Supplier::query()->with(['purchases', 'payments'])->get();

        $rows = $suppliers
            ->map(function (Supplier $s) {
                // Balances are worked out from the relations already
                // loaded rather than by asking each record for its own
                // total. Reading them one at a time is a query per
                // invoice, and this is the screen most likely to be left
                // open on a phone.
                $paidAgainst = $s->payments->groupBy('purchase_id')
                    ->map(fn ($group) => (float) $group->sum('amount'));

                $unpaid = $s->purchases->filter(
                    fn ($p) => (float) $p->amount
                        - (float) $p->paid_amount
                        - ($paidAgainst[$p->id] ?? 0.0) >= 0.01
                );

                $balance = round(
                    (float) $s->purchases->sum('amount')
                    - (float) $s->purchases->sum('paid_amount')
                    - (float) $s->payments->sum('amount'),
                    2
                );

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'phone' => $s->phone,
                    'kind' => $s->kind,
                    'balance' => Money::convert($balance),
                    'balance_formatted' => Money::format($balance),
                    'invoices' => $s->purchases->count(),
                    'unpaid_invoices' => $unpaid->count(),
                    // The oldest unpaid invoice is the one worth ringing
                    // about, so the age of the account is that row's age
                    // and not an average — an average flatters a mill
                    // sitting on a two-month-old invoice behind
                    // yesterday's.
                    'oldest_unpaid' => $unpaid->min('purchased_on')?->toDateString(),
                    'raw_balance' => $balance,
                ];
            })
            ->filter(fn (array $row) => abs($row['raw_balance']) >= 0.01)
            ->sortByDesc(fn (array $row) => $row['raw_balance'])
            ->map(function (array $row) {
                unset($row['raw_balance']);

                return $row;
            })
            ->values();

        $owed = $suppliers->sum(fn (Supplier $s) => max(0, round(
            (float) $s->purchases->sum('amount')
            - (float) $s->purchases->sum('paid_amount')
            - (float) $s->payments->sum('amount'),
            2
        )));

        return $this->success([
            'suppliers' => $rows,
            'total_owed' => Money::convert($owed),
            'total_owed_formatted' => Money::format($owed),
            'currency_label' => Money::label(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'kind' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $supplier = Supplier::create($data);

        return $this->success($this->payload($supplier), 'تأمین‌کننده ثبت شد.', 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $supplier->update($data);

        return $this->success($this->payload($supplier->fresh()), 'تأمین‌کننده به‌روزرسانی شد.');
    }

    /**
     * Removed only while they have no history.
     *
     * Once a delivery has their name on it, the name is what makes the
     * invoice mean anything — and a shop cannot be audited on invoices
     * whose supplier has been tidied away. Deactivating takes them out of
     * the picker, which is what «حذف» is usually reaching for.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchases()->exists() || $supplier->payments()->exists()) {
            return $this->error(
                'این تأمین‌کننده سابقه خرید دارد و حذف نمی‌شود. می‌توانید غیرفعالش کنید.',
                409
            );
        }

        $supplier->delete();

        return $this->success(null, 'تأمین‌کننده حذف شد.');
    }

    private function payload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'kind' => $supplier->kind,
            'is_active' => $supplier->is_active,
            'note' => $supplier->note,
            'balance' => Money::convert($supplier->balance),
            'balance_formatted' => $supplier->balance_formatted,
        ];
    }
}
