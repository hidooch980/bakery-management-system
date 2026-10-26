<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateRecord;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankSms;
use App\Models\BankSmsAccount;
use App\Support\BankSmsBook;
use App\Support\BankSmsMatcher;
use App\Traits\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «پیامک‌های بانکی» for the owner's phone.
 *
 * Only the shop's admin sends or decides SMS: the route already wants
 * manage-finance, and an accountant holding that is still not who reads
 * the owner's bank messages.
 */
class BankSmsController extends Controller
{
    use ApiResponse;

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'پیامک‌های بانکی فقط برای مدیر است.');
    }

    /** The phone's queue, in one go. Each message answers for itself. */
    public function store(Request $request): JsonResponse
    {
        $this->ownerOnly($request);

        $data = $request->validate([
            'messages' => ['required', 'array', 'max:100'],
            'messages.*.sender' => ['required', 'string', 'max:40'],
            'messages.*.body' => ['required', 'string', 'max:2000'],
            'messages.*.received_at' => ['required', 'date'],
        ]);

        $results = [];
        foreach ($data['messages'] as $m) {
            try {
                [$sms, $outcome] = BankSmsBook::ingest($request->user(), $m['sender'], $m['body'], $m['received_at']);
            } catch (UniqueConstraintViolationException) {
                [$sms, $outcome] = [null, 'duplicate'];
            }
            $results[] = ['outcome' => $outcome, 'id' => $sms?->id, 'status' => $sms?->status];
        }

        return $this->success([
            'results' => $results,
            'open' => BankSms::query()->whereIn('status', BankSms::OPEN)->count(),
        ], 'پیامک‌ها رسید.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        BankSmsBook::reopenOrphans();

        $open = $request->query('status', 'open') === 'open';

        $items = BankSms::query()
            ->with(['account:id,title', 'transaction.source'])
            ->when($open, fn ($q) => $q->whereIn('status', BankSms::OPEN))
            ->when(! $open, fn ($q) => $q->whereNotIn('status', BankSms::OPEN))
            ->latest('received_at')
            ->limit($open ? 200 : 100)
            ->get();

        return $this->success([
            'items' => $items->map(fn ($s) => BankSmsBook::present($s))->values(),
            'open' => BankSms::query()->whereIn('status', BankSms::OPEN)->count(),
            'balances' => BankSmsBook::balances(),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $this->ownerOnly($request);

        return $this->success([
            'open' => BankSms::query()->whereIn('status', BankSms::OPEN)->count(),
            'balances' => BankSmsBook::balances(),
        ]);
    }

    public function confirm(Request $request, BankSms $sms): JsonResponse
    {
        $this->ownerOnly($request);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:32'],
            'bank_account_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $sms = BankSmsBook::confirm($sms, $request->user(), $data['category'],
                $data['bank_account_id'] ?? null, $data['note'] ?? null, (bool) ($data['force'] ?? false));
        } catch (DuplicateRecord $e) {
            return response()->json([
                'success' => false,
                'message' => 'همین مبلغ امروز یک بار ثبت شده: '.$e->twin->describe()
                    .'. اگر واقعاً جداست، با تأیید دوباره ثبت کنید.',
                'data' => ['duplicate_of' => $e->twin->getKey()],
            ], 409);
        }

        return $this->success(BankSmsBook::present($sms->fresh(['account', 'transaction.source'])),
            $sms->direction === 'in' ? 'درآمد ثبت شد.' : 'هزینه ثبت شد.');
    }

    public function ignore(Request $request, BankSms $sms): JsonResponse
    {
        $this->ownerOnly($request);

        return $this->success(BankSmsBook::present(BankSmsBook::ignore($sms, $request->user())), 'نادیده گرفته شد.');
    }

    public function link(Request $request, BankSms $sms): JsonResponse
    {
        $this->ownerOnly($request);

        $data = $request->validate(['bank_transaction_id' => ['required', 'integer']]);

        $sms = BankSmsBook::link($sms, $request->user(), (int) $data['bank_transaction_id']);

        return $this->success(BankSmsBook::present($sms->fresh(['account', 'transaction.source'])), 'به ثبت قبلی وصل شد.');
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->ownerOnly($request);

        return $this->success([
            'mappings' => BankSmsAccount::query()->with('account:id,title')->orderBy('last4')->get()
                ->map(fn ($m) => ['id' => $m->id, 'last4' => $m->last4, 'bank_account_id' => $m->bank_account_id, 'account_name' => $m->account?->title])
                ->values(),
            'accounts' => BankAccount::query()->where('is_active', true)->orderBy('title')->get(['id', 'title']),
            // Digits seen in SMS that no account is tied to yet.
            'unmapped' => BankSms::query()->whereNotNull('last4')
                ->whereNotIn('last4', BankSmsAccount::query()->select('last4'))
                ->distinct()->pluck('last4')->values(),
        ]);
    }

    public function saveAccount(Request $request): JsonResponse
    {
        $this->ownerOnly($request);

        $data = $request->validate([
            'last4' => ['required', 'digits:4'],
            'bank_account_id' => ['required', 'integer'],
        ]);
        abort_unless(BankAccount::query()->whereKey($data['bank_account_id'])->exists(), 422, 'حساب پیدا نشد.');

        $mapping = BankSmsAccount::query()->updateOrCreate(['last4' => $data['last4']], ['bank_account_id' => $data['bank_account_id']]);

        // Open SMS on those digits now know their account, and may now match.
        BankSms::query()->whereIn('status', BankSms::OPEN)->where('last4', $data['last4'])->get()
            ->each(function (BankSms $sms) use ($mapping) {
                $sms->forceFill(['bank_account_id' => $mapping->bank_account_id])->save();
                BankSmsMatcher::settle($sms);
            });

        BankSmsBook::applyLatestBalanceFor($data['last4']);

        return $this->success(['id' => $mapping->id], 'حساب وصل شد.');
    }

    public function deleteAccount(Request $request, BankSmsAccount $mapping): JsonResponse
    {
        $this->ownerOnly($request);
        $mapping->delete();

        return $this->success(null, 'وصل حساب برداشته شد.');
    }
}
