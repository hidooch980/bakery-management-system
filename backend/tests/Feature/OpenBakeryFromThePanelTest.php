<?php

namespace Tests\Feature;

use App\Filament\Pages\OpenBakery;
use App\Models\Bakery;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Opening a shop from the panel instead of from the server.
 *
 * The owner runs four shops and should not need a terminal to open a fifth.
 * The admin of one of those four should not be able to open anything at
 * all — that is what keeps the shops apart.
 */
class OpenBakeryFromThePanelTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $head;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->head = Bakery::query()->oldest('id')->firstOrFail();
        $this->head->update([
            'flour_bag_weight_kg' => 45,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 10_000,
            'water_ratio' => 0.62,
            'currency' => 'rial',
            'address' => 'خیابان اول',
            'phone' => '05433000000',
        ]);

        $this->owner = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->head->id,
        ]);
        $this->owner->assignRole('admin');

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function open(array $overrides = []): Testable
    {
        return Livewire::test(OpenBakery::class)
            ->fillForm([
                'name' => 'نانوایی دلشادی',
                'copy_from' => $this->head->id,
                'admin_name' => 'مدیر دلشادی',
                'email' => 'delshadi@bakery.test',
                'phone' => '09121110001',
                'password' => 'secret-enough',
                'password_confirmation' => 'secret-enough',
                ...$overrides,
            ])
            ->call('create');
    }

    public function test_the_owner_can_open_a_shop_without_a_terminal(): void
    {
        $this->open()->assertHasNoFormErrors();

        $new = Bakery::query()->where('name', 'نانوایی دلشادی')->firstOrFail();

        $this->assertNotSame($this->head->id, $new->id);
    }

    public function test_the_new_shop_starts_on_the_head_shops_recipe(): void
    {
        $this->open();

        $new = Bakery::query()->where('name', 'نانوایی دلشادی')->firstOrFail();

        // Four shops baking the same bread to the same weights differ only
        // in how much. Retyping twenty figures is twenty chances to mistype
        // one, and a mistyped chane weight is invisible: the shop simply
        // reports a flour loss that never happened.
        $this->assertEquals(45, $new->flour_bag_weight_kg);
        $this->assertEquals(0.85, $new->normal_chane_weight_kg);
        $this->assertEquals(10_000, $new->bread_price);
        $this->assertEquals(0.62, $new->water_ratio);
        $this->assertSame('rial', $new->currency);
    }

    public function test_the_new_shop_does_not_inherit_the_head_shops_identity(): void
    {
        $this->open();

        $new = Bakery::query()->where('name', 'نانوایی دلشادی')->firstOrFail();

        // Two shops wearing one address and phone read as one place.
        $this->assertNull($new->address);
        $this->assertNull($new->phone);
    }

    public function test_the_shop_can_be_opened_with_no_recipe_at_all(): void
    {
        $this->open(['copy_from' => null])->assertHasNoFormErrors();

        $new = Bakery::query()->where('name', 'نانوایی دلشادی')->firstOrFail();

        $this->assertNull($new->normal_chane_weight_kg);
    }

    public function test_its_admin_can_sign_in_and_belongs_to_the_new_shop(): void
    {
        $this->open();

        $new = Bakery::query()->where('name', 'نانوایی دلشادی')->firstOrFail();
        $admin = User::query()->where('email', 'delshadi@bakery.test')->firstOrFail();

        $this->assertSame($new->id, $admin->bakery_id);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('secret-enough', $admin->password));
    }

    public function test_a_login_already_in_use_is_refused_before_anything_is_written(): void
    {
        $shopsBefore = Bakery::count();

        $this->open(['email' => $this->owner->email])
            ->assertHasFormErrors(['email']);

        // Not "the shop was made and the admin was not" — a shop nobody can
        // sign in to is worse than no shop.
        $this->assertSame($shopsBefore, Bakery::count());
    }

    public function test_a_mistyped_repeat_password_stops_the_shop_being_locked(): void
    {
        $this->open(['password_confirmation' => 'something-else'])
            ->assertHasFormErrors(['password']);

        $this->assertSame(0, Bakery::query()->where('name', 'نانوایی دلشادی')->count());
    }

    public function test_the_admin_of_another_shop_cannot_open_one(): void
    {
        $other = Bakery::create(['name' => 'نانوایی درازهی']);
        $branchAdmin = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $other->id,
        ]);
        $branchAdmin->assignRole('admin');

        $this->actingAs($branchAdmin);

        // Not merely hidden from their menu: hiding a page is a decision
        // about clutter, and this is a decision about authority.
        $this->assertFalse(OpenBakery::canAccess());

        $this->get(OpenBakery::getUrl())->assertForbidden();
    }

    public function test_the_page_is_offered_to_the_owner_and_to_nobody_else(): void
    {
        $this->assertTrue(OpenBakery::canAccess());

        $other = Bakery::create(['name' => 'نانوایی پرکی']);
        $branchAdmin = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $other->id,
        ]);
        $branchAdmin->assignRole('admin');

        $this->actingAs($branchAdmin);

        $this->assertFalse(OpenBakery::canAccess());
    }

    public function test_the_form_lists_what_is_already_open(): void
    {
        Bakery::create(['name' => 'نانوایی درازهی']);

        // Named, so the owner does not open the same shop twice under two
        // spellings and split its books in half.
        Livewire::test(OpenBakery::class)
            ->assertSee($this->head->name)
            ->assertSee('نانوایی درازهی');
    }
}
