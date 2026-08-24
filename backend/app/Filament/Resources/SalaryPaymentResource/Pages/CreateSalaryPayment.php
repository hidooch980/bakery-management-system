<?php

namespace App\Filament\Resources\SalaryPaymentResource\Pages;

use App\Filament\Resources\SalaryPaymentResource;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;

class CreateSalaryPayment extends CreateRecord
{
    protected static string $resource = SalaryPaymentResource::class;

    // Filament's default sends the admin to the edit page after creating,
    // which — inconsistently applied across the panel — read like a broken
    // "create" button that never returns to the list. Every create page
    // now goes back to the list uniformly.
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Arriving from a wage request, with the person and the month already
     * known.
     *
     * The request page has no approve button on purpose: paying is the
     * approval, and it happens here so the figures are on screen first.
     * That only holds if getting here is one tap — retyping the name and
     * the period is exactly the friction that would grow an approve
     * button back.
     *
     * The defaults are laid down first and then written over, so every
     * field the link says nothing about — zeroed bonus and deduction,
     * this month — still has what the form would have given it.
     *
     * Written straight into the form's state rather than through a second
     * `fill()`, and that distinction is the whole of the care here. A
     * fill runs each field's `formatStateUsing`, which is where Toman
     * becomes Rial and a Gregorian date becomes Jalali text. Filling
     * again over state that has already been through it converts
     * everything twice: the first version of this opened the pay sheet at
     * 1,500,000,000 Rial for a 150,000,000 Rial wage. This shop has
     * carried four ten-times errors already and does not need a fifth
     * sitting in a box marked «حقوق پایه» waiting to be agreed to.
     *
     * So these are display values, exactly as the select's own
     * `afterStateUpdated` writes them when a person picks the name by hand.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        if ($user = User::find(request()->integer('user_id'))) {
            $this->data['user_id'] = $user->id;

            if ($user->monthly_salary) {
                $this->data['base_amount'] = Money::convert($user->monthly_salary);
            }
        }

        $asked = request()->query('period_start');

        if ($period = Jalali::parseFlexible(is_string($asked) ? $asked : null)) {
            $this->data['period_start'] = Jalali::date($period);
        }
    }
}
