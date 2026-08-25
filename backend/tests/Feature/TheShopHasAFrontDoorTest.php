<?php

namespace Tests\Feature;

use App\Models\Bakery;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page anybody who types the address sees.
 *
 * It served Laravel's default welcome screen until 1405/06/03 — the
 * framework's logo, its documentation links, and «Log in / Register»
 * pointing at routes this application does not have. A real shop on a
 * real address, saying nothing about bread.
 *
 * These are about the two things that would actually hurt: the page
 * failing outright, and it showing one shop's details on another's
 * address once more shops open.
 */
class TheShopHasAFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    public function test_it_opens_without_signing_in(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_it_carries_the_shops_own_name(): void
    {
        Bakery::first()->update(['name' => 'خبازی ملازهی']);

        // Read from the database rather than written into the template, so
        // a rename in the panel reaches the website without a deploy.
        $this->get('/')->assertSee('خبازی ملازهی');
    }

    public function test_it_shows_the_phone_and_address_when_they_are_recorded(): void
    {
        Bakery::first()->update([
            'phone' => '09159991669',
            'address' => 'سیستان و بلوچستان، خاش',
        ]);

        $this->get('/')
            ->assertSee('09159991669')
            ->assertSee('سیستان و بلوچستان، خاش');
    }

    public function test_a_shop_with_no_phone_recorded_shows_no_empty_row(): void
    {
        Bakery::first()->update(['phone' => null, 'address' => null]);

        // A blank «تلفن —» is worse than no line: it reads as a shop that
        // has lost its own number.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('تلفن');
    }

    public function test_it_leads_to_the_panel_and_not_to_routes_that_do_not_exist(): void
    {
        $page = $this->get('/');

        // Laravel's default page linked at `login` and `register`. Neither
        // route exists here, and the panel is where staff actually sign in.
        $page->assertSee('/admin', false);
        $page->assertDontSee('نانوایی رجیستر');
    }

    public function test_nothing_of_laravels_own_page_is_left(): void
    {
        $page = $this->get('/');

        // The framework's own marketing, on a bakery's address.
        $page->assertDontSee('laravel.com', false);
        $page->assertDontSee('Documentation', false);
    }

    public function test_it_still_opens_when_no_shop_has_been_created_yet(): void
    {
        // A fresh install, before the seeder or the open-bakery page has
        // run. The front page must not be the first thing that breaks.
        Bakery::query()->delete();

        $this->get('/')->assertOk()->assertSee('نانوایی');
    }

    public function test_the_page_is_marked_up_as_persian_and_right_to_left(): void
    {
        // Without these a Persian page renders left-aligned with the
        // punctuation in the wrong places, which is most of what makes a
        // site look broken to somebody reading it.
        $this->get('/')
            ->assertSee('lang="fa"', false)
            ->assertSee('dir="rtl"', false);
    }
}
