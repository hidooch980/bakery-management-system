<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\IssueAcknowledgement;
use App\Models\Loan;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `shop:health --issues` says what the open items actually are.
 *
 * Without it the summary names a screen — «۱۱ مورد باز (۲ بحرانی)» — and
 * on a shop run from a phone over SSH that screen is not reachable while
 * the terminal is. The line then says something is wrong and refuses to
 * say what, which is worse than silence: the owner knows there is a
 * problem and has no way to find out which one.
 *
 * Found on a real server the day the deploy finally ran: the count was
 * printed, the list was not, and the only way to see it was a tinker
 * one-liner typed out by hand.
 */
class TheOpenIssuesCanBeReadWithoutABrowserTest extends TestCase
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
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 10_000, 'purchase');
    }

    private function anOpenIssue(string $partner, float $bags): SystemIssue
    {
        $customer = Customer::create(['name' => $partner, 'type' => 'partner']);

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'direction' => 'lent',
            'bags' => $bags,
            'occurred_on' => now()->subDays(30),
        ]);

        return (new IssueScanner)->scan()
            ->firstWhere('key', 'consignment-open-'.$customer->id);
    }

    private function answer(SystemIssue $issue): void
    {
        IssueAcknowledgement::create([
            'issue_key' => $issue->key,
            'title' => $issue->title,
            'severity' => $issue->severity,
            'magnitude' => $issue->magnitude,
            'note' => 'تصمیم گرفته شد.',
        ]);
    }

    private function listing(): string
    {
        Artisan::call('shop:health', ['--issues' => true]);

        return Artisan::output();
    }

    private function summaryOnly(): string
    {
        Artisan::call('shop:health');

        return Artisan::output();
    }

    public function test_each_open_issue_is_named(): void
    {
        $first = $this->anOpenIssue('نانوایی هیدوچ', 56);
        $second = $this->anOpenIssue('نانوایی پدگان', 20);

        $listing = $this->listing();

        $this->assertStringContainsString($first->title, $listing);
        $this->assertStringContainsString($second->title, $listing);
    }

    public function test_the_suggestion_is_printed_too(): void
    {
        // A title alone says a thing is wrong. What to do about it is the
        // half that makes the listing worth reading.
        $issue = $this->anOpenIssue('نانوایی هیدوچ', 56);

        $this->assertNotSame('', trim($issue->suggestion));

        $listing = $this->listing();

        foreach (preg_split('/\s+/', trim($issue->suggestion)) as $word) {
            if (mb_strlen($word) > 3) {
                $this->assertStringContainsString($word, $listing);

                return;
            }
        }
    }

    public function test_an_answered_issue_is_not_listed(): void
    {
        $answered = $this->anOpenIssue('نانوایی هیدوچ', 56);
        $open = $this->anOpenIssue('نانوایی پدگان', 20);

        $this->answer($answered);

        $listing = $this->listing();

        $this->assertStringContainsString($open->title, $listing);
        $this->assertStringNotContainsString($answered->title, $listing);
    }

    public function test_nothing_is_listed_without_the_flag(): void
    {
        // The default output is what cron and the deploy script read. A
        // listing appearing there would bury the summary it exists to
        // support.
        $issue = $this->anOpenIssue('نانوایی هیدوچ', 56);

        $this->assertStringNotContainsString($issue->title, $this->summaryOnly());
    }

    public function test_a_clean_shop_says_so_rather_than_printing_nothing(): void
    {
        $listing = $this->listing();

        $this->assertStringContainsString('هیچ مورد بازی', $listing);
    }

    public function test_the_severity_is_written_in_words(): void
    {
        // «بحرانی» and «هشدار» rather than a colour or an icon name: the
        // first run of this flag died on «Invalid "danger" color», because
        // SystemIssue::color() answers for Filament, not for a terminal.
        $issue = $this->anOpenIssue('نانوایی هیدوچ', 56);

        $this->assertStringContainsString($issue->severityLabel(), $this->listing());
    }

    /**
     * An issue with no page at all.
     *
     * A token idle past the prune window says the nightly job is not
     * running — a server condition, with nothing in the panel to click.
     */
    private function aTokenNobodyPruned(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $this->admin->id,
            'name' => 'گوشیِ فراموش‌شده',
            'token' => hash('sha256', 'stale-'.uniqid()),
            'abilities' => '["*"]',
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
            'last_used_at' => now()->subDays(90),
        ]);
    }

    /** An issue that carries the page which fixes it. */
    private function anIssueWithAPage(): SystemIssue
    {
        Loan::create([
            'title' => 'وام خرید دستگاه',
            'principal' => 307_668_800,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 77,
            'first_due_on' => now()->subMonth(),
        ]);

        $issue = (new IssueScanner)->scan()
            ->first(fn ($i) => str_starts_with($i->key, 'loan-due-'));

        $this->assertNotNull($issue);
        $this->assertNotNull($issue->url, 'این مورد باید آدرس داشته باشد.');

        return $issue;
    }

    public function test_it_says_where_to_go_and_fix_it(): void
    {
        // The listing printed what was wrong, why, and what to do — and
        // not the one thing that says where. That is the same fault it was
        // built to fix one level up: the summary said something was wrong
        // and would not say what; this said what and would not say where.
        //
        // Proved by running it: the loan issue carries /admin/loans and
        // the output contained no address at all.
        $issue = $this->anIssueWithAPage();

        $this->assertStringContainsString($issue->url, $this->listing());
    }

    public function test_the_page_is_named_as_well_as_addressed(): void
    {
        // «/admin/loans» alone is a path. «وام‌ها» alone is a screen
        // somebody has to go and find. Both is an instruction.
        $issue = $this->anIssueWithAPage();

        $this->assertStringContainsString($issue->urlLabel, $this->listing());
    }

    public function test_only_the_issues_that_have_a_page_get_an_arrow(): void
    {
        // Not every issue has somewhere to go — the server-health ones
        // carry no page at all. A bare «→» under one of those would read
        // as a line that failed to render.
        //
        // Both kinds have to be present or this proves nothing: with
        // every open issue carrying a page, the count matches whether the
        // `url !== null` guard is there or not. Checked by removing the
        // guard — the assertion stayed green until a url-less issue was
        // put in front of it.
        $this->anIssueWithAPage();
        $this->aTokenNobodyPruned();

        $listing = $this->listing();

        $expected = (new IssueScanner)->scan()
            ->filter(fn (SystemIssue $issue) => $issue->url !== null)
            ->count();

        $this->assertSame($expected, substr_count($listing, '→'));

        // And no stray line where the address would have been. The arrow
        // is trimmed away on its own, so removing the guard leaves not a
        // bare «→» but a line of spaces under every url-less issue —
        // which is what the guard is actually for, and what a sabotage
        // run showed when the arrow count stayed right and two blank
        // lines appeared.
        foreach (explode("\n", $listing) as $line) {
            $this->assertFalse(
                $line !== '' && trim($line) === '',
                'خطی که فقط فاصله است، جای آدرسِ نبوده را گرفته.'
            );
        }
    }

    public function test_the_listing_never_costs_the_command_its_exit_code(): void
    {
        // The deploy script reads this exit code. Asking for more detail
        // must not turn a healthy shop into a failed deploy.
        $this->anOpenIssue('نانوایی هیدوچ', 56);

        $without = Artisan::call('shop:health');
        $with = Artisan::call('shop:health', ['--issues' => true]);

        $this->assertSame($without, $with);
    }
}
