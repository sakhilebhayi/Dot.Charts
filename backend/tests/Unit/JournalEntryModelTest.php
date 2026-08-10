<?php

namespace Tests\Unit;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standalone_entry_persists_with_no_links(): void
    {
        $user = User::factory()->create();

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'First reflection',
            'body' => 'Just some notes, not linked to anything.',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'user_id' => $user->id,
            'title' => 'First reflection',
            'backtest_run_id' => null,
            'custom_strategy_id' => null,
        ]);
        $this->assertTrue($entry->user->is($user));
        $this->assertNull($entry->backtestRun);
        $this->assertNull($entry->customStrategy);
    }

    public function test_an_entry_can_link_to_a_backtest_run_and_a_custom_strategy(): void
    {
        $user = User::factory()->create();
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Linked reflection',
            'body' => 'This one references a real backtest and strategy.',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);

        $this->assertTrue($entry->backtestRun->is($run));
        $this->assertTrue($entry->customStrategy->is($strategy));
    }

    public function test_deleting_a_linked_backtest_run_nulls_the_link_not_the_entry(): void
    {
        $user = User::factory()->create();
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Reflection',
            'body' => 'Body text.',
            'backtest_run_id' => $run->id,
        ]);

        $run->delete();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'backtest_run_id' => null]);
    }

    public function test_deleting_a_linked_custom_strategy_nulls_the_link_not_the_entry(): void
    {
        $user = User::factory()->create();
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'title' => 'Reflection',
            'body' => 'Body text.',
            'custom_strategy_id' => $strategy->id,
        ]);

        $strategy->delete();

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'custom_strategy_id' => null]);
    }
}
