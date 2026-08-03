<?php

namespace Tests\Feature;

use App\Filament\Widgets\DueFollowUpsTable;
use App\Models\Bakery;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The record of dealing with a customer, and the follow-ups it leaves.
 */
class CustomerCrmTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->school = Customer::create([
            'name' => 'دبستان شهید بهشتی',
            'type' => 'school',
            'phone' => '09120000000',
        ]);
    }

    public function test_a_call_can_be_recorded_against_a_customer(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$this->school->id}/interactions", [
                'type' => 'debt_chase',
                'summary' => 'قول داد هفته آینده تسویه کند',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'پیگیری بدهی');

        $this->assertSame(1, $this->school->interactions()->count());
    }

    /** The panel signs the record, so it is known who made the call. */
    public function test_the_caller_is_recorded(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$this->school->id}/interactions", [
                'type' => 'call',
                'summary' => 'هماهنگی سفارش',
            ])
            ->assertCreated()
            ->assertJsonPath('data.by', $this->admin->name);
    }

    public function test_a_follow_up_date_is_read_as_jalali(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customers/{$this->school->id}/interactions", [
                'type' => 'call',
                'summary' => 'پیگیری شود',
                'follow_up_on' => '1405/05/10',
            ])
            ->assertCreated();

        $this->assertSame(
            '1405/05/10',
            Jalali::date(CustomerInteraction::first()->follow_up_on)
        );
    }

    public function test_the_call_list_holds_only_what_has_come_due(): void
    {
        $this->interaction(followUp: now()->subDay());
        $this->interaction(followUp: now()->addWeek());
        $this->interaction(followUp: null);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['count']);
        $this->assertTrue($data['follow_ups'][0]['is_overdue']);
    }

    public function test_the_call_list_carries_what_the_customer_owes(): void
    {
        $this->interaction(followUp: now());

        $followUp = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->json('data.follow_ups.0');

        $this->assertSame('09120000000', $followUp['customer_phone']);
        $this->assertArrayHasKey('outstanding_formatted', $followUp);
    }

    public function test_completing_a_follow_up_drops_it_off_the_list(): void
    {
        $interaction = $this->interaction(followUp: now()->subDay());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/interactions/{$interaction->id}/complete")
            ->assertOk();

        $this->assertSame(
            0,
            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/follow-ups')->json('data.count')
        );
    }

    public function test_completing_a_follow_up_twice_is_refused(): void
    {
        $interaction = $this->interaction(followUp: now()->subDay());
        $interaction->update(['completed_at' => now()]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/interactions/{$interaction->id}/complete")
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_read_the_call_list(): void
    {
        $seller = User::factory()->create();
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/follow-ups')
            ->assertForbidden();
    }

    public function test_the_dashboard_shows_a_due_follow_up(): void
    {
        $this->interaction(followUp: now()->subDay(), summary: 'تماس برای تسویه');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = preg_replace('/\s+/u', ' ', strip_tags(
            Livewire::test(DueFollowUpsTable::class)->html()
        ));

        $this->assertStringContainsString('دبستان شهید بهشتی', $html);
        $this->assertStringContainsString('تماس برای تسویه', $html);
    }

    private function interaction($followUp, string $summary = 'شرح'): CustomerInteraction
    {
        return CustomerInteraction::create([
            'customer_id' => $this->school->id,
            'user_id' => $this->admin->id,
            'type' => 'call',
            'summary' => $summary,
            'follow_up_on' => $followUp,
        ]);
    }
}
