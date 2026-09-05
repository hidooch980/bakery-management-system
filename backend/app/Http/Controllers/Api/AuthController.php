<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Rules\NotAGuessablePassword;
use App\Support\Jalali;
use App\Support\Sms;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * How many devices may hold a session at once. Three covers his phone,
     * a second one, and the shop's own — more than that is a key nobody is
     * watching.
     */
    private const MAX_SESSIONS = 3;

    use ApiResponse;

    /**
     * Authenticate a user by email or phone and issue a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            // Optional, so an older build that does not send it still logs
            // in. What it costs when it is missing is the device list being
            // three rows all called «دستگاه ناشناس».
            'device_name' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $user = User::where('email', $data['login'])
            ->orWhere('phone', $data['login'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['اطلاعات ورود نادرست است.'],
            ]);
        }

        if (! $user->is_active) {
            return $this->error('حساب کاربری شما غیرفعال است.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->closeOldestSessions($user);
        $token = $user->createToken(
            self::deviceName($data['device_name'] ?? null),
        )->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 'ورود موفقیت‌آمیز بود.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'خروج انجام شد.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed', new NotAGuessablePassword],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->error('رمز عبور فعلی نادرست است.', 422);
        }

        $user->update(['password' => $data['new_password']]);

        // Force re-login on all devices after a password change.
        $user->tokens()->delete();

        return $this->success(null, 'رمز عبور با موفقیت تغییر کرد. لطفاً دوباره وارد شوید.');
    }

    /**
     * «رمزم را فراموش کرده‌ام» — a code, by text.
     *
     * This always answers the same way, whether the number belongs to
     * somebody or to nobody at all. Saying «that number is not registered»
     * would turn the endpoint into a way of finding out who works here,
     * one number at a time, and the staff use their personal mobiles.
     *
     * So the caller learns nothing. Whoever owns the phone gets a text;
     * whoever does not, gets a polite sentence and no message.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $phone = Sms::normalise($data['phone']);

        $answer = $this->success(
            null,
            'اگر این شماره در سامانه باشد، کد تأیید برایش پیامک شد.',
        );

        if ($phone === null) {
            return $answer;
        }

        // Counted on the phone number rather than the caller's address:
        // every message costs the shop money and rings somebody's phone,
        // and changing address is easier than changing which number you
        // are pestering.
        $recent = PasswordResetCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= (int) config('sms.code.per_hour', 3)) {
            return $answer;
        }

        $user = User::where('phone', $phone)->where('is_active', true)->first();

        if ($user === null) {
            return $answer;
        }

        // Any code still outstanding is spent. Two live codes for one phone
        // doubles the guessing surface for no benefit — somebody who asked
        // twice is reading the newest message.
        PasswordResetCode::where('phone', $phone)->usable()
            ->update(['used_at' => now()]);

        $code = PasswordResetCode::freshCode();

        PasswordResetCode::create([
            'phone' => $phone,
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('sms.code.minutes', 5)),
            'requested_ip' => $request->ip(),
        ]);

        Sms::send($phone, 'کد بازیابی رمز خبازی ملازهی: '.$code
            .' — تا '.(int) config('sms.code.minutes', 5).' دقیقه معتبر است.');

        return $answer;
    }

    /**
     * The code, and a new password.
     *
     * A wrong guess is counted against the code rather than shrugged off:
     * six digits is a million combinations and five tries is not a threat,
     * but an unbounded number of tries is.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed', new NotAGuessablePassword],
        ]);

        $phone = Sms::normalise($data['phone']);
        $wrong = $this->error('کد وارد شده نادرست یا منقضی است.', 422);

        if ($phone === null) {
            return $wrong;
        }

        $reset = PasswordResetCode::where('phone', $phone)
            ->usable()
            ->latest('id')
            ->first();

        if ($reset === null) {
            return $wrong;
        }

        if (! $reset->matches(Sms::latinDigits($data['code']))) {
            $reset->increment('attempts');

            return $wrong;
        }

        $user = $reset->user;

        if ($user === null || ! $user->is_active) {
            return $wrong;
        }

        $user->update(['password' => $data['password']]);

        // Every device signed out. If somebody else knew the old password,
        // this is the moment they stop being able to use it — and the person
        // resetting is about to sign in anyway.
        $user->tokens()->delete();

        $reset->update(['used_at' => now()]);

        return $this->success(null, 'رمز عبور تغییر کرد. حالا وارد شوید.');
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }

    /**
     * Room for a few devices, and no more.
     *
     * Signing in used to delete *every* other token, so a session lived on
     * exactly one phone: opening the app on a second one silently killed
     * the first, which then failed one request at a time without ever
     * saying it had been signed out. That is what «نانینو وصل نمی‌شه»
     * turned out to be on 1405/06/11 — 96 refused requests from a phone
     * whose key had been revoked four seconds earlier.
     *
     * Not simply removed, though: a pile of forgotten keys is exactly what
     * `tokens:prune-idle` was written to clear, and a token nobody uses is
     * the one whose loss nobody notices. So the newest few stay and the
     * rest are closed — a shop with three devices keeps all three, and the
     * fourth sign-in retires the oldest rather than everybody.
     *
     * A password change or reset still closes every session; those are
     * deliberate and are left alone.
     */
    /**
     * The devices holding a session for whoever is asking.
     *
     * Written because there was no way to answer «گوشی‌ام گم شده» except by
     * deactivating the person who lost it, which stops them working. The
     * three ways a session ended before this — changing a password,
     * resetting one, an admin switching the account off — are all the side
     * effect of doing something else, and two of the three cost more than
     * the problem.
     */
    public function devices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                // The phone in your hand, so the list can say so rather
                // than inviting somebody to sign themselves out by accident
                // while standing at the till.
                'is_current' => $current !== null && $token->id === $current->id,
                'last_used_at' => Jalali::dateTime($token->last_used_at),
                // Null, not «هرگز»: a token minted a minute ago has not been
                // used yet, and the screen decides how to say that.
                'created_at' => Jalali::dateTime($token->created_at),
            ]);

        return $this->success([
            'devices' => $devices,
            'max' => self::MAX_SESSIONS,
        ]);
    }

    /**
     * Signs one device out, named by the row in that list.
     *
     * Scoped to the caller's own tokens on purpose: `$user->tokens()` and
     * not `PersonalAccessToken::find()`, so an id belonging to somebody
     * else is a 404 and not somebody else's session ending.
     */
    public function revokeDevice(Request $request, int $token): JsonResponse
    {
        $row = $request->user()->tokens()->whereKey($token)->first();

        if ($row === null) {
            return $this->error('این دستگاه پیدا نشد.', 404);
        }

        $wasCurrent = $request->user()->currentAccessToken()?->id === $row->id;

        $row->delete();

        return $this->success(
            ['signed_self_out' => $wasCurrent],
            $wasCurrent
                ? 'از این دستگاه خارج شدید.'
                : 'آن دستگاه خارج شد.',
        );
    }

    /**
     * Everything except the phone asking.
     *
     * The answer to a lost handset when its owner cannot say which row in
     * the list it is — which, standing in a shop having just realised, is
     * most of the time.
     */
    public function revokeOtherDevices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        $closed = $request->user()->tokens()
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))
            ->delete();

        return $this->success(
            ['closed' => $closed],
            $closed === 0
                ? 'دستگاه دیگری وارد نبود.'
                : 'بقیهٔ دستگاه‌ها خارج شدند.',
        );
    }

    /**
     * What to write on the token.
     *
     * Trimmed and collapsed rather than rejected: this arrives from
     * `device_info_plus`, and a manufacturer that pads its model name with
     * whitespace should not cost somebody a login. An empty or missing one
     * keeps the name every token carried before this existed.
     */
    private static function deviceName(?string $sent): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $sent ?? ''));

        return $name === '' ? 'mobile-app' : mb_substr($name, 0, 60);
    }

    private function closeOldestSessions(User $user): void
    {
        $keep = $user->tokens()
            ->orderByDesc('id')
            ->take(self::MAX_SESSIONS - 1)
            ->pluck('id');

        $user->tokens()->whereNotIn('id', $keep)->delete();
    }
}
