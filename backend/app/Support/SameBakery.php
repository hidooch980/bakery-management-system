<?php

namespace App\Support;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * That the person named in a route works at the shop doing the asking.
 *
 * Every other model carries a global bakery scope, so a query for one
 * shop's rows cannot return another's. [User] deliberately does not, and
 * the reason is sound: resolving the signed-in user is how the current
 * bakery is worked out in the first place, so scoping users by it would
 * ask the question to answer the question.
 *
 * The cost of that exemption is that route model binding on a User is
 * unguarded, and six endpoints took one straight from the URL without
 * checking. Two of them delete and update; two settle a seller's account.
 * With one shop on the box there is nothing to cross, which is exactly
 * why it went unnoticed — and the owner has four more shops built and
 * waiting on «فعلاً فعال نشده، بعد از تست نهایی». The day those open,
 * these six stop being harmless in silence.
 *
 * **404 and not 403.** A refusal that says «you may not touch that»
 * confirms the id exists and names a real person at another shop. Not
 * found is the honest answer to a question that, from where the caller
 * stands, has no subject.
 */
final class SameBakery
{
    /**
     * Returns $user when they belong to the current shop, and 404s when
     * they do not.
     *
     * With no current bakery resolved — the console, a test that has not
     * seeded one — nothing is enforced, matching what the global scope on
     * every other model does in the same situation.
     */
    public static function or404(User $user): User
    {
        $current = CurrentBakery::id();

        if ($current === null || $user->bakery_id === $current) {
            return $user;
        }

        throw new NotFoundHttpException('مورد درخواستی یافت نشد.');
    }
}
