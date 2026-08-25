<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neither the date nor the time appeared anywhere in the panel.
 *
 * In a shop whose flour quota period runs 5th→4th rather than by the
 * calendar month, whose lateness is counted per day, and where a batch is
 * now allowed once a day, «what is today» is a working question. The
 * answer was on the wall behind the desk.
 */
class ThePanelKnowsWhatDayItIsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_todays_jalali_date_is_on_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertSee(Jalali::longDate(now()));
    }

    public function test_the_time_is_rendered_before_the_browser_touches_it(): void
    {
        // The seconds tick in Alpine, but the first paint has to be right:
        // a clock that is blank until JavaScript runs reads as a broken
        // panel on the shop's slow connection.
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertSee(Jalali::time(now()));
    }

    public function test_it_is_on_pages_other_than_the_dashboard(): void
    {
        // Hung off the topbar rather than added as a dashboard widget, so
        // that it is there while somebody is recording a batch too.
        $this->actingAs($this->admin())
            ->get('/admin/dough-entries')
            ->assertOk()
            ->assertSee(Jalali::longDate(now()));
    }

    public function test_a_signed_out_visitor_is_still_just_redirected(): void
    {
        $this->get('/admin')->assertRedirect();
    }
}
