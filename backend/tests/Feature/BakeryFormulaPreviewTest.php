<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageBakery;
use App\Models\FlourAllocation;
use App\Models\User;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "پیش‌نمایش برای یک کیسه" placeholder on /admin/manage-bakery shows
 * what one bag of flour turns into — both as normal chane and, once a
 * nanino weight is set, as nanino chane too.
 */
class BakeryFormulaPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_preview_shows_the_nanino_count_alongside_the_normal_one(): void
    {
        Livewire::test(ManageBakery::class)
            ->fillForm([
                'flour_bag_weight_kg' => 40,
                'water_ratio' => 0.6,
                'salt_ratio' => 0.015,
                'yeast_ratio' => 0,
                'dough_loss_ratio' => 0,
                // Proving is measured in ProofGainTest; here the
                // formula's own arithmetic is what is under test.
                'proof_gain_ratio' => 0,
                'normal_chane_weight_kg' => 0.85,
                'nanino_chane_weight_kg' => 1.0,
            ])
            // 40 + 24 + 0.6 = 64.6kg dough; 76 normal (0.85kg), 64 nanino (1.0kg).
            ->assertSee('خمیر 64.60 کیلوگرم')
            ->assertSee('تا 76 چانه عادی')
            ->assertSee('تا 64 چانه نانینو');
    }

    public function test_the_preview_omits_nanino_when_its_weight_is_not_set(): void
    {
        Livewire::test(ManageBakery::class)
            ->fillForm([
                'flour_bag_weight_kg' => 40,
                'water_ratio' => 0.6,
                'salt_ratio' => 0.015,
                'yeast_ratio' => 0,
                'dough_loss_ratio' => 0,
                // Proving is measured in ProofGainTest; here the
                // formula's own arithmetic is what is under test.
                'proof_gain_ratio' => 0,
                'normal_chane_weight_kg' => 0.85,
                'nanino_chane_weight_kg' => null,
            ])
            ->assertSee('تا 76 چانه عادی')
            // The field label itself says "چانه نانینو"; only the preview's
            // own "یا حدود..." clause is what should disappear here.
            ->assertDontSee('یا  تا');
    }

    public function test_the_period_preview_covers_each_period_and_the_month_total(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 30,
        ]);
        $allocation->syncPeriods();

        $html = Livewire::test(ManageBakery::class)
            ->fillForm([
                'flour_bag_weight_kg' => 40,
                'water_ratio' => 0.6,
                'salt_ratio' => 0.015,
                'yeast_ratio' => 0,
                'dough_loss_ratio' => 0,
                // Proving is measured in ProofGainTest; here the
                // formula's own arithmetic is what is under test.
                'proof_gain_ratio' => 0,
                'normal_chane_weight_kg' => 0.85,
                'nanino_chane_weight_kg' => 1.0,
            ])
            ->html();

        $text = preg_replace('/\s+/u', ' ', strip_tags($html));

        // 30 bags split three ways is 10 each: 646kg of dough, which is
        // 760 normal chane or 646 nanino. The month total restates all 30.
        $this->assertStringContainsString('10.0 کیسه ← خمیر 646.0 کیلوگرم', $text);
        $this->assertStringContainsString('حدود 760 چانه عادی یا حدود 646 چانه نانینو', $text);
        $this->assertStringContainsString('سرجمع ماه: 30.0 کیسه ← خمیر 1,938.0 کیلوگرم', $text);
    }

    public function test_the_period_preview_says_so_when_no_quota_is_registered(): void
    {
        Livewire::test(ManageBakery::class)
            ->fillForm(['flour_bag_weight_kg' => 40])
            ->assertSee('برای این ماه سهمیه‌ای ثبت نشده است.');
    }
}
