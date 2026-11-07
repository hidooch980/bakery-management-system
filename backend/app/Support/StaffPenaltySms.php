<?php

namespace App\Support;

use App\Models\NotificationSetting;
use App\Models\StaffPenaltyRequest;
use App\Models\User;

/** به کارمند خبر می‌دهد جریمه یا اخطارش ثبت یا لغو شد؛ اول بله، وگرنه پیامک. */
class StaffPenaltySms
{
    public static function approved(StaffPenaltyRequest $request, ?User $by = null): void
    {
        $what = $request->type === StaffPenaltyRequest::FINE
            ? 'جریمهٔ '.Money::format((float) $request->amount)
            : 'اخطار';

        self::send($request, 'staff_penalty', implode("\n", [
            CurrentBakery::get()?->name,
            $request->user?->name.' عزیز،',
            'برای شما '.$what.' ثبت شد.',
            'علت: '.$request->reason_label,
            'تاریخ: '.AppCalendar::date($request->occurred_on),
        ]), $by);
    }

    public static function cancelled(StaffPenaltyRequest $request, ?User $by = null): void
    {
        self::send($request, 'staff_penalty_cancelled', implode("\n", [
            CurrentBakery::get()?->name,
            $request->user?->name.' عزیز،',
            ($request->type === StaffPenaltyRequest::FINE ? 'جریمهٔ' : 'اخطارِ')
                .' تاریخ '.AppCalendar::date($request->occurred_on).' لغو شد.',
        ]), $by);
    }

    private static function send(StaffPenaltyRequest $request, string $kind, string $message, ?User $by): void
    {
        try {
            if ($request->user === null) {
                return;
            }

            Notify::person(
                $kind,
                $request->user,
                $message,
                NotificationSetting::current()->sms_staff_penalty,
                $by,
            );
        } catch (\Throwable $e) {
            // پیامی که نرفت نباید تصمیم مدیر را برگرداند.
            report($e);
        }
    }
}
