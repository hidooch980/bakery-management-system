<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `shop:health` names a bakery filed under the wrong kind of customer.
 *
 * The consignment page lists only customers typed «همکار / نانوایی», so a
 * bakery saved as anything else is not in the dropdown and nothing says
 * why — the name is simply not there.
 *
 * That is not hypothetical. نانوایی ناهوت was typed «مدرسه» when the
 * customer list was first entered, and twenty sacks lent to منصور پرکی
 * went a month with no record of any kind: whoever tried to enter them
 * could not find the name. It came to light because the owner said so.
 *
 * The register is reproduced here as it actually is — the misspelling and
 * the trailing space included — because a check written against tidy test
 * data proves nothing about the row it has to catch.
 */
class ABakeryFiledAsSomethingElseIsReportedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function customer(string $name, string $type): Customer
    {
        return Customer::create(['name' => $name, 'type' => $type, 'is_active' => true]);
    }

    public function test_a_bakery_typed_as_a_school_is_named(): void
    {
        $this->customer('منصور پرکی نانوایی ناهوت ', 'school');

        $this->artisan('shop:health')
            ->expectsOutputToContain('نانوایی ثبت‌شده زیر نوعی جز همکار: 1')
            ->expectsOutputToContain('«منصور پرکی نانوایی ناهوت» زیر «مدرسه»');
    }

    public function test_a_bakery_typed_as_a_partner_is_not_reported(): void
    {
        $this->customer('عبدالریٌوف درازهی نانوایی هیدوچ ', Customer::PARTNER_TYPE);

        $this->artisan('shop:health')
            ->expectsOutputToContain('نانوایی ثبت‌شده زیر نوعی جز همکار: 0')
            ->doesntExpectOutputToContain('نانوایی هیدوچ» زیر');
    }

    /**
     * The check keys on the word «نانوایی», so a real school must not trip
     * it however it is typed — otherwise the warning becomes noise and the
     * next real one is scrolled past.
     */
    public function test_a_school_that_really_is_a_school_is_left_alone(): void
    {
        $this->customer('خابگاه دخترانه هیدوچ', 'school');
        $this->customer('پاسگاه هیدوچ ', 'office');

        $this->artisan('shop:health')
            ->expectsOutputToContain('نانوایی ثبت‌شده زیر نوعی جز همکار: 0');
    }

    /**
     * A whole register at once, the way the shop's own is: eight partners,
     * two dormitories, two offices — and one bakery mis-filed among them.
     * A check that passes on a single row proves nothing about picking one
     * row out of a list.
     */
    public function test_one_mis_filed_bakery_is_found_among_a_full_register(): void
    {
        $this->customer('خابگاه دخترانه هیدوچ', 'school');
        $this->customer('خابگاه پسرانه هیدوچ', 'school');
        $this->customer('پاسگاه هیدوچ ', 'office');
        $this->customer('سپاه هیدوچ', 'office');
        $this->customer('عبدالحمید پرکی نانوایی کلوکان', Customer::PARTNER_TYPE);
        $this->customer('عبدالریٌوف درازهی نانوایی هیدوچ ', Customer::PARTNER_TYPE);
        $this->customer('ممد زاکر پرکی نانوایی پدگان', Customer::PARTNER_TYPE);
        $this->customer('منصور پرکی نانوایی ناهوت ', 'school');
        $this->customer('محمداکبر قریشیان نانوایی کنت', Customer::PARTNER_TYPE);
        $this->customer('متفرقه بدهی حنیف محمودی فر', 'other');

        $this->artisan('shop:health')
            ->expectsOutputToContain('نانوایی ثبت‌شده زیر نوعی جز همکار: 1')
            ->expectsOutputToContain('«منصور پرکی نانوایی ناهوت» زیر «مدرسه»')
            ->doesntExpectOutputToContain('نانوایی پدگان» زیر');
    }

    /**
     * A mis-filed record is something to look at, not a broken system, so
     * it must not fail the command — `shop:health` is run from scripts and
     * a non-zero exit means the shop's own data no longer adds up.
     */
    public function test_a_mis_filed_bakery_is_a_warning_and_not_a_failure(): void
    {
        $this->customer('منصور پرکی نانوایی ناهوت ', 'school');

        // The backup check fails on a machine with no dumps on disk, which
        // would mask the exit code this test is about.
        @mkdir(storage_path('app/backups'), 0775, true);
        file_put_contents(storage_path('app/backups/health-test.sql.gz'), 'x');

        try {
            $this->artisan('shop:health')->assertSuccessful();
        } finally {
            @unlink(storage_path('app/backups/health-test.sql.gz'));
        }
    }
}
