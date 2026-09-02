<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every reason the application writes has a Persian name.
 *
 * `flour_sale` and `correction` did not. Twelve flour sales and five
 * opening balances had been showing the owner the raw English key
 * wherever a movement is named, and nobody noticed until «آرد کجا رفت»
 * put the reasons in a column of their own and one line read
 * «flour_sale» in the middle of a Persian table.
 *
 * The list and the code that writes into it are in different files, so
 * nothing but a test keeps them together — adding a reason is one line
 * and remembering to name it is a separate thought.
 */
class EveryMovementReasonHasANameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every string literal handed to `move()` anywhere in the application,
     * read out of the source rather than listed here — a list copied by
     * hand is one more place to forget.
     *
     * @return list<string>
     */
    private function reasonsTheCodeWrites(): array
    {
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // ->move('out', $qty, 'production', ...) and the multi-line
            // form the recorder writes it in.
            preg_match_all(
                '/->move\(\s*[^,]+,\s*[^,]+,\s*\'([a-z_]+)\'/s',
                $file->getContents(),
                $matches
            );

            $found = array_merge($found, $matches[1]);
        }

        // What StockReversal turns each of those into on a deletion.
        $found = array_merge($found, [
            'production_reversal',
            'flour_sale_reversal',
            'consignment_return',
        ]);

        return array_values(array_unique($found));
    }

    public function test_every_reason_the_code_writes_is_named(): void
    {
        $unnamed = array_diff(
            $this->reasonsTheCodeWrites(),
            array_keys(InventoryMovement::REASONS)
        );

        $this->assertSame(
            [],
            array_values($unnamed),
            'These reasons are written by the application but have no Persian name, '
                .'so they reach the owner as raw keys: '.implode(', ', $unnamed)
        );
    }

    /** The two that were missing, named for what they are. */
    public function test_a_flour_sale_and_a_correction_read_in_persian(): void
    {
        $item = InventoryItem::ofKey(InventoryItem::FLOUR);
        $item->move('in', 400, 'purchase');

        $sale = $item->move('out', 40, 'flour_sale');
        $correction = $item->move('in', 100, 'correction');

        $this->assertSame('فروش آرد', $sale->reason_label);
        $this->assertSame('اصلاح موجودی', $correction->reason_label);
    }

    /**
     * The fallback stays, because a reason written by a migration years
     * ago must still render as something rather than throwing. It is a
     * safety net, not a place to leave things.
     */
    public function test_an_unknown_reason_still_renders(): void
    {
        $movement = InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', 1, 'something_from_a_migration');

        $this->assertSame('something_from_a_migration', $movement->reason_label);
    }
}
