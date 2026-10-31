<?php

namespace App\Support;

use App\Models\NotificationSetting;
use App\Models\StaffPenaltyRequest;
use App\Models\User;

/** Tells a member of staff, by SMS, that a penalty on them stands or was lifted. */
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
            AutoSms::send(
                $kind,
                $request->user?->name,
                $request->user?->phone,
                $message,
                NotificationSetting::current()->sms_staff_penalty,
                $by,
            );
        } catch (\Throwable $e) {
            // A message that could not go must never undo the decision.
            report($e);
        }
    }
}
