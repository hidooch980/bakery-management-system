<?php

namespace Tests\Feature;

use App\Filament\Resources\ConsignmentFlourResource\Pages\CreateConsignmentFlour;
use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel's consignment form asks for sacks, because sacks are what
 * changes hands at the door.
 *
 * It used to ask for a weight, and on 1405/06/08 twelve sacks borrowed
 * from نانوایی کنت were recorded as twelve kilograms — 0.30 of a sack.
 * Nobody mistyped: the number in the person's head was the sack count and
 * the box in front of them said کیلوگرم. Correcting it needed the row
 * deleted and rewritten through the model, because the stock hooks fire
 * on created and deleted and not on update, so 468kg of real flour would
 * otherwise have stayed outside the warehouse for good.
 *
 * There was no test over this form at all, which is how the kilogram box
 * survived every pass over the warehouse.
 */
class ConsignmentFlourIsEnteredInSacksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function partner(): Customer
    {
        return Customer::create([
            'name' => 'نانوایی کنت',
            'type' => Customer::PARTNER_TYPE,
            'is_active' => true,
        ]);
    }

    public function test_the_form_records_a_sack_count_and_weighs_it_itself(): void
    {
        // Enough on hand that lending is possible either way, so what is
        // asserted is the conversion and not a stock refusal.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $this->partner()->id,
                'direction' => 'borrowed',
                'partner_phone' => '09151234567',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $record = ConsignmentFlour::firstOrFail();

        // Twelve sacks, not twelve kilograms.
        $this->assertEqualsWithDelta(12, (float) $record->bags, 0.001);
        $this->assertEqualsWithDelta(480, (float) $record->amount_kg, 0.001);
    }

    public function test_the_warehouse_moves_by_the_weight_those_sacks_carry(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 2000, 'purchase');

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $this->partner()->id,
                'direction' => 'borrowed',
                'partner_phone' => '09151234567',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // 2000 + 480. Under the old form this would have read 2012 — the
        // ledger would have balanced and the flour would not have been
        // there.
        $this->assertEqualsWithDelta(2480, $flour->fresh()->balance, 0.001);
    }

    public function test_the_table_says_the_sack_count_before_the_weight(): void
    {
        // Lending moves flour out, so there has to be some to lend.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        $record = ConsignmentFlour::create([
            'customer_id' => $this->partner()->id,
            'direction' => 'lent',
            'bags' => 5,
            'occurred_on' => now()->toDateString(),
        ]);

        // The sack count leads: it is what was counted at the door, and
        // the weight is for the books.
        $this->assertStringStartsWith('5 کیسه', $record->quantity_label);
        $this->assertStringContainsString('200 کیلوگرم', $record->quantity_label);
    }

    /**
     * Four partner bakeries were holding 116 sacks of this shop's flour on
     * 2026-09-03 with not one telephone number on file — the panel could
     * name every debtor and offered no way to ask any of them for it back.
     *
     * A number that is a nuisance to type once is the whole of the
     * follow-up later.
     */
    public function test_a_transfer_will_not_be_recorded_without_a_number_to_ring(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $this->partner()->id,
                'direction' => 'lent',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['partner_phone']);

        $this->assertSame(0, ConsignmentFlour::count());
    }

    /**
     * The number belongs to the partner, not to one transfer: the next
     * amanat is a different row and the number has to still be there.
     */
    public function test_the_number_typed_on_a_transfer_lands_on_the_partner(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        $partner = $this->partner();

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $partner->id,
                'direction' => 'lent',
                'partner_phone' => '09151234567',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('09151234567', $partner->fresh()->phone);
    }

    public function test_a_number_already_on_the_partner_is_not_overwritten(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        $partner = $this->partner();
        $partner->update(['phone' => '09150000000']);

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $partner->id,
                'direction' => 'lent',
                'partner_phone' => '09159999999',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The partner page is where a number gets corrected. A transfer
        // form quietly rewriting it is how a good number is lost to a
        // typo on an unrelated row.
        $this->assertSame('09150000000', $partner->fresh()->phone);
    }

    public function test_a_handover_date_can_be_marked_a_guess(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $this->partner()->id,
                'direction' => 'lent',
                'partner_phone' => '09151234567',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
                'date_is_approximate' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(ConsignmentFlour::firstOrFail()->date_is_approximate);
    }

    public function test_an_ordinary_transfer_is_not_marked_a_guess(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');

        Livewire::test(CreateConsignmentFlour::class)
            ->fillForm([
                'customer_id' => $this->partner()->id,
                'direction' => 'lent',
                'partner_phone' => '09151234567',
                'bags' => 12,
                'occurred_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(ConsignmentFlour::firstOrFail()->date_is_approximate);
    }
}
