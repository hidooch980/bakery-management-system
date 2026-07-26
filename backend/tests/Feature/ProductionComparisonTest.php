<?php

namespace Tests\Feature;

use App\Filament\Widgets\ProductionComparisonOverview;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Today's production against the formula: what was shaped, how much each
 * bag of dough yielded compared with what it should have, and the nanino
 * output beside it.
 */
class ProductionComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function produce(int $bags, int $chaneCount, float $naninoWeight = 0): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => $bags]);

        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => $chaneCount,
            'normal_weight_kg' => $chaneCount * 0.85,
            'nanino_weight_kg' => $naninoWeight,
            'spray_flour_kg' => 0,
        ]);
    }

    private function widgetText(): string
    {
        $html = Livewire::test(ProductionComparisonOverview::class)->html();

        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    public function test_it_reports_the_chane_shaped_and_the_dough_it_came_from(): void
    {
        $this->produce(bags: 2, chaneCount: 152);

        $text = $this->widgetText();

        $this->assertStringContainsString('چانه تولیدی امروز 152 عدد', $text);
        $this->assertStringContainsString('از 2 کیسه خمیر', $text);
    }

    public function test_matching_the_formula_yield_is_reported_as_expected(): void
    {
        // One bag yields 64.6kg of dough, so 76 chane at 0.85kg each.
        $this->produce(bags: 2, chaneCount: 152);

        $text = $this->widgetText();

        $this->assertStringContainsString('چانه به ازای هر کیسه 76.0 عدد', $text);
        $this->assertStringContainsString('انتظار 76 عدد', $text);
    }

    public function test_falling_short_of_the_formula_shows_the_gap(): void
    {
        // 140 from 2 bags is 70 a bag, six short of the expected 76.
        $this->produce(bags: 2, chaneCount: 140);

        $text = $this->widgetText();

        $this->assertStringContainsString('چانه به ازای هر کیسه 70.0 عدد', $text);
        $this->assertStringContainsString('-6.0', $text);
    }

    public function test_beating_the_formula_is_shown_as_a_surplus(): void
    {
        $this->produce(bags: 2, chaneCount: 160);

        $this->assertStringContainsString('+4.0', $this->widgetText());
    }

    public function test_the_nanino_equivalent_restates_todays_normal_chane(): void
    {
        // 152 chane at 0.85kg is 129.2kg of dough, which at 1.0kg a nanino
        // loaf is 129 — the comparison figure, not a count of anything made.
        $this->produce(bags: 2, chaneCount: 152);

        $text = $this->widgetText();

        $this->assertStringContainsString('معادل نانینو 129 عدد', $text);
        $this->assertStringContainsString('اگر نانینو شکل می‌گرفت', $text);
    }

    public function test_the_equivalent_stands_even_when_no_nanino_was_shaped(): void
    {
        // The old reading showed a bare "0 عدد • 0٪" here, which said
        // nothing about how the two systems compare.
        $this->produce(bags: 2, chaneCount: 152, naninoWeight: 0);

        $text = $this->widgetText();

        $this->assertStringContainsString('معادل نانینو 129 عدد', $text);
        $this->assertStringNotContainsString('معادل نانینو 0 عدد', $text);
    }

    public function test_nanino_actually_shaped_is_named_alongside_the_equivalent(): void
    {
        $this->produce(bags: 2, chaneCount: 152, naninoWeight: 48);

        $text = $this->widgetText();

        $this->assertStringContainsString('معادل نانینو 129 عدد', $text);
        $this->assertStringContainsString('نانینوی واقعی: 48 عدد', $text);
    }

    public function test_a_day_with_no_dough_reports_no_yield_rather_than_zero(): void
    {
        $text = $this->widgetText();

        // Dividing by no bags would read as a total failure, which it is not.
        $this->assertStringContainsString('چانه به ازای هر کیسه —', $text);
        $this->assertStringContainsString('خمیری برای امروز ثبت نشده', $text);
    }

    public function test_the_yield_is_not_judged_without_a_configured_chane_weight(): void
    {
        Bakery::first()->update(['normal_chane_weight_kg' => null]);
        $this->produce(bags: 2, chaneCount: 152);

        $this->assertStringContainsString(
            'وزن چانه عادی در تنظیمات ثبت نشده است',
            $this->widgetText()
        );
    }

    public function test_the_equivalent_needs_both_chane_weights(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);
        $this->produce(bags: 2, chaneCount: 152);

        $text = $this->widgetText();

        $this->assertStringContainsString('معادل نانینو —', $text);
        $this->assertStringContainsString('وزن هر دو نوع چانه در تنظیمات ثبت نشده است', $text);
    }
}
