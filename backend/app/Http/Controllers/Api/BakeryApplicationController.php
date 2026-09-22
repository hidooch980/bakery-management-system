<?php

namespace App\Http\Controllers\Api;

use App\Actions\OpenBakery as OpenBakeryAction;
use App\Filament\Pages\OpenBakery;
use App\Http\Controllers\Controller;
use App\Models\Bakery;
use App\Models\BakeryApplication;
use App\Models\Subscription;
use App\Rules\NotAGuessablePassword;
use App\Support\Exclusively;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A bakery asking to use this, and the owner's answer.
 *
 * The public half is one endpoint and it is deliberately dull: it writes
 * a row saying somebody asked. It does not create a shop, does not
 * create a login, and does not let the caller choose anything that
 * matters — because it is an unauthenticated door on the server that
 * runs a working bakery, and the only safe thing for such a door to do
 * is take a message.
 *
 * Everything that actually happens — the shop, its first admin, its
 * subscription — happens on the other side, signed in, deliberately, by
 * the person who owns the system.
 */
class BakeryApplicationController extends Controller
{
    use ApiResponse;

    /** Anybody, unauthenticated. Writes a message and nothing else. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bakery_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // The same person pressing the button twice is not two bakeries.
        // Matched on the phone, which is the field this shop's world
        // actually identifies people by.
        $already = BakeryApplication::pending()
            ->where('phone', $data['phone'])
            ->first();

        if ($already !== null) {
            return $this->success(
                ['id' => $already->id],
                'درخواست شما ثبت شده و در انتظار بررسی است.',
            );
        }

        $application = BakeryApplication::create([
            ...$data,
            'status' => BakeryApplication::PENDING,
            'ip' => $request->ip(),
        ]);

        return $this->success(
            ['id' => $application->id],
            'درخواست شما ثبت شد. پس از بررسی با شما تماس گرفته می‌شود.',
            201,
        );
    }

    /** Who has asked — for the owner of the head shop, nobody else. */
    public function index(Request $request): JsonResponse
    {
        $this->refuseUnlessHeadShop($request);

        $applications = BakeryApplication::query()
            ->with('reviewedBy:id,name')
            ->when(
                $request->query('status'),
                fn ($q, $status) => $q->where('status', $status),
            )
            ->latest('id')
            ->paginate(30)
            ->through(fn (BakeryApplication $a) => $a->payload());

        return $this->success($applications);
    }

    /**
     * Opens the shop this application asked for.
     *
     * Through the same action the panel and the console use, so a shop
     * that arrived this way is identical to one opened by hand. A second
     * path into existence is how two shops that were meant to be the
     * same quietly start keeping different books.
     */
    public function approve(Request $request, BakeryApplication $application): JsonResponse
    {
        $this->refuseUnlessHeadShop($request);

        $data = $request->validate([
            // The password is set here rather than taken from the form a
            // stranger filled in: one typed into a public form and left
            // in a table until somebody reads it is a password sitting
            // in plain sight.
            'password' => ['required', 'string', 'min:8', new NotAGuessablePassword],
            // A sign-in needs one and `users.email` is not nullable,
            // but an applicant only has to give a phone — this shop's
            // world identifies people by phone, and demanding an email
            // from a village baker at the door would turn people away
            // at the one step that must not.
            //
            // So the owner supplies it at approval, when they are on
            // the phone to the person anyway. Without this the approval
            // crashed on the constraint, which is how it was found.
            'email' => [
                $application->email === null ? 'required' : 'sometimes',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'months' => ['sometimes', 'integer', 'min:1', 'max:36'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'copy_from' => ['sometimes', 'nullable', 'exists:bakeries,id'],
        ]);

        // Two owners approving the same application a moment apart would
        // open the shop twice, and the second bakery would be a ghost
        // nobody ever signs in to.
        $bakery = null;

        Exclusively::claim(
            $application,
            fn (BakeryApplication $a) => $a->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            function (BakeryApplication $a) use ($data, $request, &$bakery) {
                $bakery = (new OpenBakeryAction)->run(
                    name: $a->bakery_name,
                    adminName: $a->owner_name,
                    email: $data['email'] ?? $a->email,
                    phone: $a->phone,
                    password: $data['password'],
                    copyFrom: isset($data['copy_from'])
                        ? Bakery::find($data['copy_from'])
                        : null,
                );

                Subscription::create([
                    'bakery_id' => $bakery->id,
                    'plan' => 'standard',
                    'starts_on' => now(),
                    'ends_on' => now()->copy()->addMonths($data['months'] ?? 12),
                    'amount' => $data['amount'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                $a->update([
                    'status' => BakeryApplication::APPROVED,
                    'reviewed_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'bakery_id' => $bakery->id,
                ]);
            },
        );

        return $this->success(
            ['bakery_id' => $bakery?->id],
            'نانوایی «'.$application->bakery_name.'» باز شد.',
        );
    }

    public function reject(Request $request, BakeryApplication $application): JsonResponse
    {
        $this->refuseUnlessHeadShop($request);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        Exclusively::claim(
            $application,
            fn (BakeryApplication $a) => $a->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            fn (BakeryApplication $a) => $a->update([
                'status' => BakeryApplication::REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'rejection_reason' => $data['reason'],
            ]),
        );

        return $this->success(null, 'درخواست رد شد.');
    }

    /**
     * Only the head shop.
     *
     * An admin of a shop opened *through* this has no business opening
     * more of them; the permission that guards the rest of the settings
     * would let them, because it is the same permission their own shop
     * gives them. The panel's own «نانوایی جدید» page draws the line in
     * the same place and for the same reason.
     */
    private function refuseUnlessHeadShop(Request $request): void
    {
        $head = OpenBakery::headShop();

        abort_unless(
            $head !== null && (int) $request->user()?->bakery_id === $head->id,
            403,
        );
    }
}
