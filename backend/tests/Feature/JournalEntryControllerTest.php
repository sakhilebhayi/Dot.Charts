<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_a_standalone_entry_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'My first reflection',
            'body' => 'Notes on the market today.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'My first reflection');
        $this->assertDatabaseHas('journal_entries', ['title' => 'My first reflection', 'user_id' => $user->id]);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/journal-entries', ['title' => 'X', 'body' => 'Y']);

        $response->assertStatus(401);
    }

    public function test_store_requires_title_and_body(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_store_links_to_the_users_own_backtest_run_and_strategy(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Linked entry',
            'body' => 'Reflecting on this specific run.',
            'symbol' => 'AAPL',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('journal_entries', [
            'title' => 'Linked entry',
            'backtest_run_id' => $run->id,
            'custom_strategy_id' => $strategy->id,
        ]);
    }

    public function test_store_rejects_linking_to_another_users_backtest_run(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $othersRun = BacktestRun::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Sneaky entry',
            'body' => 'Trying to link to a run I do not own.',
            'backtest_run_id' => $othersRun->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('journal_entries', ['title' => 'Sneaky entry']);
    }

    public function test_store_rejects_linking_to_another_users_custom_strategy(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $othersStrategy = CustomStrategy::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/journal-entries', [
            'title' => 'Sneaky entry',
            'body' => 'Trying to link to a strategy I do not own.',
            'custom_strategy_id' => $othersStrategy->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('journal_entries', ['title' => 'Sneaky entry']);
    }

    public function test_index_returns_only_the_authenticated_users_entries(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Mine']);
        JournalEntry::factory()->create(['user_id' => $otherUser->id, 'title' => 'Not Mine']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Not Mine'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/journal-entries');

        $response->assertStatus(401);
    }

    public function test_index_paginates_at_twenty(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->count(21)->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries');

        $response->assertOk();
        $this->assertCount(20, $response->json('data'));
        $this->assertNotNull($response->json('next_page_url'));
    }

    public function test_index_filters_by_symbol(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'AAPL note', 'symbol' => 'AAPL']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'BTC note', 'symbol' => 'BTC/USDT']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/journal-entries?symbol=AAPL');

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('AAPL note'));
        $this->assertFalse($titles->contains('BTC note'));
    }

    public function test_index_filters_by_backtest_run_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $run = BacktestRun::factory()->create(['user_id' => $user->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Linked', 'backtest_run_id' => $run->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Unlinked']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries?backtest_run_id={$run->id}");

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Linked'));
        $this->assertFalse($titles->contains('Unlinked'));
    }

    public function test_index_filters_by_custom_strategy_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        $strategy = CustomStrategy::factory()->create(['user_id' => $user->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Linked', 'custom_strategy_id' => $strategy->id]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Unlinked']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/journal-entries?custom_strategy_id={$strategy->id}");

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Linked'));
        $this->assertFalse($titles->contains('Unlinked'));
    }
}
