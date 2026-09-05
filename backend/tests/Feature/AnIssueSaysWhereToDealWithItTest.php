<?php

namespace Tests\Feature;

use App\Support\IssueDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «امروز» names the tab that deals with each thing it lists.
 *
 * The panel has had a link on every issue since the issue centre was
 * written, and the phone was never sent it — so the owner read «موجودی
 * «حساب اصلی» منفی است» and was left to work out for himself that the
 * answer is behind «مالی». A panel path is no use on a handset, so the
 * mapping is to the app's own tabs, and it lives here rather than on the
 * phone: a new check gets a destination without an app release.
 */
class AnIssueSaysWhereToDealWithItTest extends TestCase
{
    public static function issues(): array
    {
        return [
            'a stock balance below zero' => ['negative-stock-flour', 'warehouse'],
            'flour running low' => ['low-stock-flour', 'warehouse'],
            'the month quota overdrawn' => ['quota-over', 'warehouse'],
            'flour out with a partner' => ['consignment-open-partner-3', 'warehouse'],
            'the diesel nearly gone' => ['diesel-running-out-12', 'warehouse'],

            'an overdrawn account' => ['negative-bank-3', 'finance'],
            'a seller still holding cash' => ['seller-account-7', 'finance'],
            'a loan instalment due' => ['loan-due-2', 'finance'],
            'a month spent at a loss' => ['trading-at-a-loss-2026-08', 'finance'],

            'dough left pending' => ['stale-dough', 'overview'],
        ];
    }

    #[DataProvider('issues')]
    public function test_it_sends_each_kind_of_issue_somewhere_it_can_be_dealt_with(
        string $key,
        string $expected,
    ): void {
        $this->assertSame($expected, IssueDestination::forKey($key));
    }

    /**
     * The longer prefix has to win. `seller-account-stale-3` starts with
     * `seller-account-`, and reading it as the shorter one would work only
     * for as long as the two happen to go to the same place.
     */
    public function test_a_longer_prefix_is_not_swallowed_by_a_shorter_one(): void
    {
        $this->assertSame(
            IssueDestination::FINANCE,
            IssueDestination::forKey('seller-account-stale-3'),
        );
    }

    /**
     * The shop's settings are not on the phone at all, so a button
     * promising to take him there would be a lie. Null means the row shows
     * no button, which is what every row did before this existed.
     */
    public function test_an_issue_with_nowhere_to_go_says_so(): void
    {
        $this->assertNull(IssueDestination::forKey('missing-settings'));
        $this->assertNull(IssueDestination::forKey('something-new-nobody-mapped'));
    }
}
