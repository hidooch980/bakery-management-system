<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SellerWarningRule;
use App\Models\StaffAdjustment;
use App\Models\StaffPenaltyRequest;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seller-warning rules (item 12): off until the manager sets them, each hit
 * a pending warning in the penalty-request list, filed once per day, and
 * only ever a warning — nothing docked until approved, and even then a
 * note, not an amount.
 */
class SellerWarningRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();
        $this->travelTo(now()->setTime(12, 0));

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->seller = User::factory()->create(['is_active' => true, 'name' => 'مریم']);
        $this->seller->assignRole('seller');
    }

    private function lateYesterday(int $minutes): void
    {
        WorkStart::create([
            'user_id' => $this->seller->id, 'type' => WorkStart::BAKING,
            'date' => now()->subDay()->toDateString(), 'started_at' => now()->subDay(),
            'is_late' => true, 'late_minutes' => $minutes, 'penalty_amount' => 0,
        ]);
    }

    private function rules(): array
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/seller-warning-rules')
            ->assertOk()->json('data.rules');
    }

    private function rule(string $condition): SellerWarningRule
    {
        $this->rules();

        return SellerWarningRule::where('condition', $condition)->firstOrFail();
    }

    public function test_the_shop_starts_with_every_rule_off_and_the_nanino_ones_sourced(): void
    {
        $rules = collect($this->rules());

        $this->assertCount(count(SellerWarningRule::DEFAULTS), $rules);
        $this->assertFalse($rules->contains('is_active', true));
        $nanino = $rules->where('source', 'nanino');
        $this->assertCount(3, $nanino);
        $this->assertSame(SellerWarningRule::NANINO_URL, $nanino->first()['source_url']);

        $this->lateYesterday(60);
        $this->artisan('sellers:rule-warnings')->assertSuccessful();
        $this->assertSame(0, StaffPenaltyRequest::count());
    }

    public function test_a_rule_cannot_be_switched_on_without_a_threshold(): void
    {
        $rule = $this->rule('debt_over');

        $this->patchJson("/api/v1/seller-warning-rules/{$rule->id}", ['is_active' => true])
            ->assertStatus(422);
        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_a_money_threshold_is_entered_in_the_shops_currency(): void
    {
        $rule = $this->rule('debt_over');

        $this->patchJson("/api/v1/seller-warning-rules/{$rule->id}", ['threshold' => 5_000_000, 'is_active' => true])
            ->assertOk()->assertJsonPath('data.threshold', 5000000)->assertJsonPath('data.is_active', true);

        $this->assertEquals(500_000, (float) $rule->fresh()->threshold); // stored in toman
    }

    public function test_a_late_shift_files_one_pending_warning_and_only_once(): void
    {
        $rule = $this->rule('late_shift');
        $this->patchJson("/api/v1/seller-warning-rules/{$rule->id}", ['threshold' => 15, 'is_active' => true])->assertOk();
        $this->lateYesterday(40);

        $this->artisan('sellers:rule-warnings')->assertSuccessful();
        $this->artisan('sellers:rule-warnings')->assertSuccessful();

        $this->assertSame(1, StaffPenaltyRequest::count());
        $w = StaffPenaltyRequest::firstOrFail();
        $this->assertSame(StaffPenaltyRequest::WARNING, $w->type);
        $this->assertSame(StaffPenaltyRequest::PENDING, $w->status);
        $this->assertSame('shift_hours', $w->reason);
        $this->assertNull($w->requested_by);
        $this->assertStringContainsString('40 دقیقه', $w->note);
        $this->assertSame(0, StaffAdjustment::count());

        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/penalty-requests')->assertOk()->json('data');
        $row = collect($list['data'] ?? $list)->first();
        $this->assertSame('قانون: '.$rule->name, $row['requested_by_name']);
        $this->assertTrue($row['from_rule']);
    }

    public function test_lateness_within_the_allowance_files_nothing(): void
    {
        $rule = $this->rule('late_shift');
        $rule->update(['threshold' => 15, 'is_active' => true]);
        $this->lateYesterday(10);

        $this->artisan('sellers:rule-warnings')->assertSuccessful();

        $this->assertSame(0, StaffPenaltyRequest::count());
    }

    public function test_approving_a_rule_warning_records_a_note_not_an_amount(): void
    {
        $rule = $this->rule('late_shift');
        $rule->update(['threshold' => 15, 'is_active' => true]);
        $this->lateYesterday(40);
        $this->artisan('sellers:rule-warnings')->assertSuccessful();
        $w = StaffPenaltyRequest::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$w->id}/approve")->assertOk();

        $adj = StaffAdjustment::firstOrFail();
        $this->assertSame(StaffAdjustment::BY_NOTE, $adj->basis);
        $this->assertNull($adj->amount);
    }

    public function test_a_bulk_sale_outside_the_card_reader_is_flagged(): void
    {
        $rule = $this->rule('off_queue_bulk');
        $rule->update(['threshold' => 100, 'is_active' => true]);

        $this->travelTo(now()->subDay());
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1, 'status' => 'processed']);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id, 'user_id' => $this->admin->id, 'chane_count' => 300,
            'normal_weight_kg' => 1, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0, 'status' => 'sold',
        ]);
        foreach ([['card', 200], ['cash', 40], ['cash', 150]] as [$type, $bread]) {
            Sale::create([
                'chane_entry_id' => $chane->id, 'user_id' => $this->seller->id, 'bread_count' => $bread,
                'payment_type' => $type, 'amount' => $bread * 1000, 'amount_difference' => 0,
            ]);
        }
        $this->travelBack();
        $this->travelTo(now()->setTime(12, 0));

        $this->artisan('sellers:rule-warnings')->assertSuccessful();

        $this->assertSame(1, StaffPenaltyRequest::count());
        $this->assertStringContainsString('150', StaffPenaltyRequest::first()->note);
        $this->assertSame('off_queue', StaffPenaltyRequest::first()->reason);
    }

    public function test_the_nanino_complaint_list_can_be_picked_by_hand(): void
    {
        $keys = collect($this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/penalty-requests/options')->assertOk()->json('data.reasons'))->pluck('label');

        foreach (['عدم رعایت اصول بهداشتی', 'اجبار مشتری به خرید نان کنجدی', 'عدم برخورد صحیح با مشتری'] as $label) {
            $this->assertTrue($keys->contains($label), $label);
        }
    }
}
