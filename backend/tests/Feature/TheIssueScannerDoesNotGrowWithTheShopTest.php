<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Loan;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scanner behind «امروز» and «مرکز مشکلات» asks a fixed number of
 * questions, whatever size the shop is.
 *
 * The rule written down after a sidebar badge put 320 queries on every
 * panel page is to measure at one size and again at ten, and treat a
 * difference as the bug — a page that is quick on a test database and
 * slow in Ramadan is a page nobody measured. These two pages were the
 * last ones recorded in the audit as still growing.
 *
 * Counted rather than timed on purpose: a query count is the same on my
 * machine and the shop's, and it is the thing that actually grows.
 */
class TheIssueScannerDoesNotGrowWithTheShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();
    }

    /** A shop of the given size: sellers holding money, accounts, loans. */
    private function shopOf(int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            $seller = User::factory()->create(['is_active' => true]);
            $seller->assignRole('seller');

            $dough = DoughEntry::create([
                'user_id' => $seller->id,
                'bag_count' => 4,
                'status' => 'shaped',
            ]);

            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $seller->id,
                'chane_count' => 400,
                'normal_weight_kg' => 340,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
                'status' => 'sold',
            ]);

            // Two sales apiece, unsettled and old enough to be late, so
            // both the seller checks have something to say about everyone.
            for ($n = 0; $n < 2; $n++) {
                Sale::create([
                    'user_id' => $seller->id,
                    'chane_entry_id' => $chane->id,
                    'payment_type' => 'cash',
                    'bread_count' => 100,
                    'amount' => 1_000_000,
                ])->forceFill(['created_at' => now()->subDays(20)])->save();
            }

            BankAccount::create([
                'title' => "حساب {$i}",
                'opening_balance' => -100,
            ]);

            Loan::create([
                'title' => "وام {$i}",
                'principal' => 12_000,
                'instalment_amount' => 1_000,
                'instalment_count' => 12,
                'first_due_on' => now()->subDays(3)->toDateString(),
            ]);
        }
    }

    private function queriesForScan(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        (new IssueScanner)->scan();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_scan_costs_the_same_at_ten_times_the_size(): void
    {
        $this->shopOf(1);

        // Once unmeasured, so the things a request looks up once — the
        // inventory rows, the shop's own settings — are not counted as a
        // saving the second time round.
        (new IssueScanner)->scan();
        $small = $this->queriesForScan();

        $this->shopOf(9);
        $large = $this->queriesForScan();

        $this->assertSame(
            $small,
            $large,
            "The scan asked {$small} questions for a shop of one and {$large}"
            .' for a shop of ten. Whatever the difference is, it is per-row'
            .' work inside a loop and will keep growing.'
        );
    }
}
