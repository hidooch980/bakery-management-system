<?php

namespace Tests\Feature;

use App\Models\FlourAllocation;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        [$from, $until] = Jalali::currentMonthRange();

        $a = FlourAllocation::create([
            'month_start' => $from,
            'month_label' => 'تست',
            'total_bags' => 75,
        ]);
        $a->syncPeriods();

        fwrite(STDERR, "\n--- مرز ماه ---\n");
        fwrite(STDERR, '  امروز:            '.now()->toDateString()."\n");
        fwrite(STDERR, '  ماه جاری از:      '.$from->toDateString()."\n");
        fwrite(STDERR, '  ماه جاری تا:      '.$until->toDateString()."\n");
        fwrite(STDERR, "  دوره‌های ساخته‌شده:\n");

        foreach ($a->fresh('periods')->periods as $p) {
            fwrite(STDERR, sprintf("    %d: %s → %s\n", $p->period_number,
                $p->starts_on->toDateString(), $p->ends_on->toDateString()));
        }

        fwrite(STDERR, '  دورهٔ امروز:       '
            .($a->fresh('periods')->periodFor(now())?->period_number ?? 'هیچ')."\n\n");

        $this->assertTrue(true);
    }
}
