<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_accounts_matching_the_email_substring(): void
    {
        User::factory()->create(['email' => 'qa-fixture@example.com', 'name' => 'QA Fixture']);
        User::factory()->create(['email' => 'real-person@example.com', 'name' => 'Real Person']);

        $this->artisan('user:find', ['needle' => 'qa-'])
            ->expectsOutputToContain('qa-fixture@example.com')
            ->doesntExpectOutputToContain('real-person@example.com')
            ->expectsOutputToContain("1 of 2 accounts match 'qa-'.")
            ->assertSuccessful();
    }

    public function test_reports_zero_matches_without_failing(): void
    {
        User::factory()->create(['email' => 'someone@example.com']);

        $this->artisan('user:find', ['needle' => 'ghost'])
            ->expectsOutputToContain("0 of 1 accounts match 'ghost'.")
            ->assertSuccessful();
    }
}
