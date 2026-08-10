<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The actual enforcement mechanism behind wiki.md's "position/order
 * exclusion enforced at the schema level" claim for the trading journal --
 * not just a comment saying so. Fails the moment anyone adds a
 * position/order/P&L-shaped column to journal_entries, deliberately or by
 * accident, without an explicit, informed decision to override this
 * platform's standing regulated-market invariant. See
 * docs/superpowers/specs/2026-08-10-trading-journal-design.md and
 * platforms/dot-charts.md §2 in ~/Dot/Dot.Brain for why this exists.
 */
class JournalEntriesSchemaInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const BANNED_COLUMN_NAMES = [
        'quantity', 'qty', 'price', 'entry_price', 'exit_price', 'fill_price',
        'position_size', 'position', 'side', 'direction', 'order_type',
        'leverage', 'pnl', 'profit_loss', 'realized_pnl', 'unrealized_pnl',
        'stop_loss', 'take_profit', 'margin', 'lot_size', 'volume',
    ];

    public function test_journal_entries_never_gains_a_position_or_order_shaped_column(): void
    {
        $columns = Schema::getColumnListing('journal_entries');

        $bannedFound = array_intersect(array_map('strtolower', $columns), self::BANNED_COLUMN_NAMES);

        $this->assertEmpty(
            $bannedFound,
            'journal_entries has gained a position/order-shaped column ('.implode(', ', $bannedFound).') '
                .'-- this violates Dot.Charts\' standing, deliberate never-persist-positions invariant '
                .'(wiki.md §7, platforms/dot-charts.md §2). Not a bug to silently fix -- needs an explicit, '
                .'informed ecosystem-level decision before proceeding.'
        );
    }

    public function test_journal_entries_has_exactly_the_expected_columns(): void
    {
        $columns = Schema::getColumnListing('journal_entries');
        sort($columns);

        $this->assertSame(
            ['backtest_run_id', 'body', 'created_at', 'custom_strategy_id', 'id', 'symbol', 'title', 'updated_at', 'user_id'],
            $columns
        );
    }
}
