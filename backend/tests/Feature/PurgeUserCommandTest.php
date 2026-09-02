<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\CustomStrategy;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private function userWithData(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->createToken('test');
        BacktestRun::factory()->create(['user_id' => $user->id]);
        CustomStrategy::factory()->create(['user_id' => $user->id]);
        JournalEntry::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_purges_the_target_user_and_everything_they_own(): void
    {
        $target = $this->userWithData('doomed@example.com');
        $bystander = $this->userWithData('bystander@example.com');

        $this->artisan('user:purge', ['email' => 'doomed@example.com', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseCount('backtest_runs', 1);
        $this->assertDatabaseCount('custom_strategies', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // The survivors all belong to the bystander, untouched.
        $this->assertSame($bystander->id, BacktestRun::sole()->user_id);
        $this->assertSame($bystander->id, CustomStrategy::sole()->user_id);
        $this->assertSame($bystander->id, JournalEntry::sole()->user_id);
    }

    public function test_refuses_platform_operators(): void
    {
        $operator = User::factory()->create(['email' => 'operator@example.com']);
        $operator->forceFill(['is_platform_operator' => true])->save();

        $this->artisan('user:purge', ['email' => 'operator@example.com', '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $operator->id]);
    }

    public function test_fails_cleanly_on_an_unknown_email(): void
    {
        $this->artisan('user:purge', ['email' => 'ghost@example.com', '--force' => true])
            ->assertFailed();
    }

    public function test_aborts_without_force_when_not_confirmed(): void
    {
        $user = $this->userWithData('cautious@example.com');

        $this->artisan('user:purge', ['email' => 'cautious@example.com'])
            ->expectsConfirmation("Permanently delete {$user->name} <cautious@example.com> and all their data?", 'no')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
