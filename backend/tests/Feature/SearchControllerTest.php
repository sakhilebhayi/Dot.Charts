<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The header search. Its contract: signed-in only, the token's own rows only, at most five per group, nothing heavy in a row,
 * and the person's own % and _ are text, not wildcards.
 */
class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('api')->plainTextToken);
    }

    public function test_it_requires_authentication_and_answers_json_401(): void
    {
        $this->call('GET', '/api/search', ['q' => 'aapl'])->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_it_finds_the_accounts_backtests_journal_entries_and_strategies(): void
    {
        $user = User::factory()->create();
        BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'NVDA']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Why NVDA gapped', 'body' => 'Notes.']);
        CustomStrategy::factory()->create(['user_id' => $user->id, 'name' => 'NVDA dip buyer']);

        $r = $this->as($user)->getJson('/api/search?q=nvda');

        $r->assertOk()->assertJsonPath('q', 'nvda')->assertJsonPath('limit', 5);
        $r->assertJsonCount(1, 'backtests')->assertJsonCount(1, 'journal')->assertJsonCount(1, 'strategies');
        $r->assertJsonPath('backtests.0.symbol', 'NVDA')->assertJsonPath('journal.0.title', 'Why NVDA gapped')->assertJsonPath('strategies.0.name', 'NVDA dip buyer');
    }

    public function test_it_never_returns_another_accounts_rows(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        BacktestRun::factory()->create(['user_id' => $theirs->id, 'symbol' => 'SECRET1']);
        JournalEntry::factory()->create(['user_id' => $theirs->id, 'title' => 'SECRET1 plan', 'body' => 'SECRET1']);
        CustomStrategy::factory()->create(['user_id' => $theirs->id, 'name' => 'SECRET1 rules']);
        BacktestRun::factory()->create(['user_id' => $mine->id, 'symbol' => 'SECRET1']);

        $r = $this->as($mine)->getJson('/api/search?q=secret1')->assertOk();

        $r->assertJsonCount(1, 'backtests')->assertJsonCount(0, 'journal')->assertJsonCount(0, 'strategies');
        $this->assertSame($mine->id, BacktestRun::find($r->json('backtests.0.id'))->user_id);
        $this->app['auth']->forgetGuards(); // the guard keeps the first user for the rest of the test
        $this->as($theirs)->getJson('/api/search?q=secret1')->assertJsonCount(1, 'backtests')->assertJsonCount(1, 'journal')->assertJsonCount(1, 'strategies');
    }

    public function test_runs_without_an_owner_are_not_searchable(): void
    {
        $user = User::factory()->create();
        BacktestRun::factory()->create(['user_id' => null, 'symbol' => 'ANON9']);

        $this->as($user)->getJson('/api/search?q=anon9')->assertOk()->assertJsonCount(0, 'backtests');
    }

    public function test_a_strategy_is_found_by_the_name_the_backtest_page_shows(): void
    {
        $user = User::factory()->create();
        BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'SPY', 'strategy' => 'ma_crossover']);
        BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'QQQ', 'strategy' => 'breakout']);

        $r = $this->as($user)->getJson('/api/search?q=crossover')->assertOk();

        $r->assertJsonCount(1, 'backtests')->assertJsonPath('backtests.0.symbol', 'SPY');
    }

    public function test_it_returns_at_most_five_rows_per_group_newest_first(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 8) as $i) {
            BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL', 'created_at' => now()->subDays(10 - $i)]);
        }

        $r = $this->as($user)->getJson('/api/search?q=aapl')->assertOk();

        $r->assertJsonCount(5, 'backtests');
        $ids = collect($r->json('backtests'))->pluck('id')->all();
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_a_row_is_light_it_carries_no_results_blob_and_no_journal_body(): void
    {
        $user = User::factory()->create();
        BacktestRun::factory()->create(['user_id' => $user->id, 'symbol' => 'AAPL', 'results' => ['equity_curve' => array_fill(0, 50, ['equity' => 1])]]);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'AAPL', 'body' => 'long private body']);

        $r = $this->as($user)->getJson('/api/search?q=aapl')->assertOk();

        $this->assertArrayNotHasKey('results', $r->json('backtests.0'));
        $this->assertArrayNotHasKey('params', $r->json('backtests.0'));
        $this->assertArrayNotHasKey('body', $r->json('journal.0'));
        $this->assertArrayNotHasKey('rules', $r->json('strategies.0') ?? []);
    }

    public function test_percent_and_underscore_are_text_not_wildcards(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Gain of 50% in a week', 'body' => 'x']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'Fifty-five and more', 'body' => 'x']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'rsi_mean note', 'body' => 'x']);
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'rsiXmean note', 'body' => 'x']);

        $this->as($user)->getJson('/api/search?q='.urlencode('50%'))->assertJsonCount(1, 'journal');
        $this->as($user)->getJson('/api/search?q='.urlencode('%%'))->assertJsonCount(0, 'journal');
        $this->as($user)->getJson('/api/search?q='.urlencode('si_me'))->assertJsonCount(1, 'journal')->assertJsonPath('journal.0.title', 'rsi_mean note');
        $this->as($user)->getJson('/api/search?q='.urlencode('a!'))->assertJsonCount(0, 'journal');
    }

    public function test_sql_in_the_query_is_only_ever_a_bound_value(): void
    {
        $user = User::factory()->create();
        JournalEntry::factory()->create(['user_id' => $user->id, 'title' => 'keep me', 'body' => 'x']);

        $this->as($user)->getJson('/api/search?q='.urlencode("x'; DROP TABLE journal_entries; --"))->assertOk()->assertJsonCount(0, 'journal');
        $this->as($user)->getJson('/api/search?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'journal');

        $this->assertSame(1, DB::table('journal_entries')->count());
    }

    public function test_the_query_is_validated_one_character_or_none_or_a_novel_is_a_422(): void
    {
        $user = User::factory()->create();

        $this->as($user)->getJson('/api/search')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->as($user)->getJson('/api/search?q=a')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->as($user)->getJson('/api/search?q='.str_repeat('a', 81))->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->as($user)->getJson('/api/search?q[]=aa')->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_no_match_is_three_empty_groups_not_an_error(): void
    {
        $user = User::factory()->create();

        $this->as($user)->getJson('/api/search?q=zzzz')->assertOk()
            ->assertJson(['backtests' => [], 'journal' => [], 'strategies' => []]);
    }

    public function test_the_search_has_its_own_rate_limit(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        foreach (range(1, 40) as $i) {
            $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/search?q=aa')->assertOk();
        }
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/search?q=aa')->assertStatus(429);
    }
}
