<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Handsets;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * هر کارمند با چه گوشی و چه اندرویدی وصل است.
 *
 * ثبتش از قبل بود، ولی فهرست دستگاه‌ها **فقط مالِ خودِ شخص** است:
 * فروشنده اندروید گوشیِ خودش را می‌بیند و صاحب اندروید گوشیِ خودش
 * را. هیچ‌جا نبود که صاحب گوشیِ فروشنده را ببیند — که دقیقاً همان
 * کاری است که آدم می‌خواهد بکند وقتی فروشنده می‌گوید «نصب نمی‌شود».
 *
 * پس این صفحه همان یک سؤال را جواب می‌دهد و نه بیشتر: چه کسی، روی
 * چه گوشی‌ای، با چه اندرویدی، با کدام نسخهٔ اپ — و کدامشان اصلاً
 * نمی‌توانند نسخهٔ تازه را بگیرند.
 */
class StaffHandsets extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'کارکنان';

    protected static ?string $navigationLabel = 'گوشی کارکنان';

    protected static ?string $title = 'گوشی کارکنان';

    protected static ?string $slug = 'staff-handsets';

    protected static string $view = 'filament.pages.staff-handsets';

    /**
     * عددِ کنار منو: چند گوشی جا مانده‌اند.
     *
     * فقط وقتی چیزی برای گفتن هست. صفحه‌ای که همیشه عدد دارد، عددی
     * است که کسی دیگر نمی‌بیندش.
     */
    public static function getNavigationBadge(): ?string
    {
        $stranded = self::rowsFor(auth()->user())
            ->filter(fn (array $row) => $row['stranded'])
            ->count();

        return $stranded > 0 ? (string) $stranded : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        return self::rowsFor(auth()->user());
    }

    /**
     * یک ردیف برای هر نشستِ باز، به‌علاوهٔ کارکنانی که هیچ نشستی
     * ندارند.
     *
     * کسی که هرگز وارد نشده هم یک ردیف است: «هیچ‌وقت وارد نشده» خودش
     * جوابِ «چرا این نفر چیزی ثبت نمی‌کند» است، و اگر از فهرست بیفتد
     * بیرون، آن سؤال بی‌جواب می‌ماند.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function rowsFor(?User $viewer): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        $staff = User::ofCurrentBakery()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($staff->isEmpty()) {
            return collect();
        }

        // توکن‌ها جدولِ Sanctum اند و مدلِ خودمان را ندارند، پس
        // مستقیم خوانده می‌شوند — و فقط برای همین کارکنان، که همان
        // محدودیتِ نانوایی است یک قدم بالاتر.
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $staff->pluck('id'))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('tokenable_id');

        return $staff->flatMap(function (User $person) use ($tokens) {
            $theirs = $tokens->get($person->id);

            if ($theirs === null || $theirs->isEmpty()) {
                return [[
                    'user' => $person->name,
                    'device' => null,
                    'os' => null,
                    'sdk' => null,
                    'app_version' => null,
                    'last_used' => null,
                    'stranded' => false,
                    'never_signed_in' => true,
                ]];
            }

            return $theirs->map(fn ($token) => [
                'user' => $person->name,
                'device' => $token->name,
                'os' => $token->os_version,
                'sdk' => $token->sdk_int === null ? null : (int) $token->sdk_int,
                'app_version' => $token->app_version,
                'last_used' => $token->last_used_at
                    ? AppCalendar::dateTime($token->last_used_at)
                    : null,
                // فقط وقتی واقعاً می‌دانیم. null یعنی نمی‌دانیم، و
                // «جا مانده» خواندنش کسی را می‌فرستد گوشیِ سالم عوض
                // کند.
                'stranded' => Handsets::canInstallUpdates(
                    $token->sdk_int === null ? null : (int) $token->sdk_int
                ) === false,
                'never_signed_in' => false,
            ])->all();
        });
    }

    /** پایین‌ترین اندرویدی که اپ رویش نصب می‌شود، برای نوشتن روی صفحه. */
    public function minimumAndroid(): int
    {
        return Handsets::MIN_ANDROID_SDK;
    }
}
