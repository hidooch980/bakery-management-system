<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reproduces the reported "create button does nothing" complaint by
 * actually filling and submitting the panel form, the way a user does,
 * rather than only checking that the page loads.
 */
class FlourAllocationPanelTest extends TestCase
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
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_creating_a_flour_allocation_redirects_to_the_list(): void
    {
        Livewire::test(\App\Filament\Resources\FlourAllocationResource\Pages\CreateFlourAllocation::class)
            ->fillForm([
                'month_start' => Jalali::currentMonthRange()[0]->toDateString(),
                'total_bags' => 75,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(\App\Filament\Resources\FlourAllocationResource::getUrl('index'));

        $this->assertDatabaseCount('flour_allocations', 1);
    }
}
