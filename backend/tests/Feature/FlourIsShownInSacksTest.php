<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryMovementResource;
use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Qty;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * انبار آرد به کیسه دیده و وارد می‌شود؛ پایگاه داده همچنان کیلوگرم است.
 */
class FlourIsShownInSacksTest extends TestCase
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

    public function test_کیلوگرم_به_کیسه_با_اعشار_نوشته_می‌شود(): void
    {
        $this->assertSame('13 کیسه', Qty::flourBags(520));
        $this->assertSame('46.5 کیسه', Qty::flourBags(1860));
        $this->assertSame('0.13 کیسه', Qty::flourBags(5));
    }

    public function test_وزن_کیسه_از_تنظیمات_نانوایی_خوانده_می‌شود(): void
    {
        Bakery::first()->update(['flour_bag_weight_kg' => 50]);

        $this->assertSame('10 کیسه', Qty::flourBags(500));
    }

    public function test_ورود_دستی_آرد_به_کیسه_است_و_کیلوگرم_ذخیره_می‌شود(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);

        Livewire::test(InventoryMovementResource\Pages\CreateInventoryMovement::class)
            ->fillForm([
                'inventory_item_id' => $flour->id,
                'direction' => 'in',
                'quantity' => 12,
                'reason' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(480.0, (float) $flour->movements()->latest('id')->value('quantity'));
        $this->assertSame(480.0, $flour->fresh()->balance);
    }

    public function test_کالای_غیر_آرد_همچنان_کیلوگرم_وارد_می‌شود(): void
    {
        $salt = InventoryItem::ofKey(InventoryItem::SALT);

        Livewire::test(InventoryMovementResource\Pages\CreateInventoryMovement::class)
            ->fillForm([
                'inventory_item_id' => $salt->id,
                'direction' => 'in',
                'quantity' => 12,
                'reason' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(12.0, (float) $salt->movements()->latest('id')->value('quantity'));
    }

    public function test_فهرست_گردش_انبار_آرد_را_به_کیسه_نشان_می‌دهد(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1860, 'purchase');

        $this->get(InventoryMovementResource::getUrl('index'))
            ->assertOk()
            ->assertSee('46.5 کیسه');
    }
}
